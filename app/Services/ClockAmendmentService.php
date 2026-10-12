<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\ClockAmendment;
use App\Repositories\AttendanceRepository;
use App\Repositories\ClockAmendmentRepository;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 補打卡申請 Service
 *
 * ⚠ **標準上下班時間一律問 `AttendanceService::resolveWorkTime()`。**
 * 這裡原本自己從 `$record->assignment->shift` 取，於是固定班的人
 * （工程，沒有排班指派）補打卡後遲到／早退／加班全部是 0 ——
 * 跟正常打卡同一個病根，修在同一個地方（見 [[fixed-work-hours]]）。
 */
class ClockAmendmentService
{
    private $amendmentRepository;
    private $attendanceRepository;
    private $attendanceService;

    public function __construct(
        ClockAmendmentRepository $amendmentRepository,
        AttendanceRepository $attendanceRepository,
        AttendanceService $attendanceService
    ) {
        $this->amendmentRepository = $amendmentRepository;
        $this->attendanceRepository = $attendanceRepository;
        // 「這個人這天幾點上下班」只有這一個答案來源
        $this->attendanceService = $attendanceService;
    }

    /**
     * 客服申請補打卡
     *
     * @param array $params
     * @param int   $userId
     * @return ClockAmendment
     * @throws ValidationException
     */
    public function request($params, $userId)
    {
        $date = $params['date'];
        $type = (int) $params['type'];

        // 檢查重複申請
        if ($this->amendmentRepository->hasPending($userId, $date, $type)) {
            throw ValidationException::withMessages([
                'amend' => [trans('attendance.amend_duplicate')],
            ]);
        }

        return $this->amendmentRepository->create([
            'user_id'    => $userId,
            'date'       => $date,
            'type'       => $type,
            'clock_time' => $params['clock_time'],
            'reason'     => $params['reason'] ?? null,
        ]);
    }

    /**
     * 審核補打卡申請
     *
     * @param ClockAmendment $amendment
     * @param int            $status     1=通過, 2=拒絕
     * @param int            $reviewerId 審核人 ID
     * @return ClockAmendment
     * @throws ValidationException
     */
    public function respond(ClockAmendment $amendment, $status, $reviewerId)
    {
        if ($amendment->status !== ClockAmendment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'amend' => [trans('attendance.amend_already_reviewed')],
            ]);
        }

        return DB::transaction(function () use ($amendment, $status, $reviewerId) {
            // 更新申請狀態
            $this->amendmentRepository->update($amendment, [
                'status'      => $status,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            // 通過時更新打卡紀錄
            if ($status === ClockAmendment::STATUS_APPROVED) {
                $this->applyAmendment($amendment);
            }

            return $amendment;
        });
    }

    /**
     * 套用補打卡到打卡紀錄
     *
     * @param ClockAmendment $amendment
     * @return void
     */
    private function applyAmendment(ClockAmendment $amendment)
    {
        $dateStr = $amendment->date->format('Y-m-d');
        $record = $this->attendanceRepository->findByUserAndDate($amendment->user_id, $dateStr);

        $clockTime = "{$dateStr} {$amendment->clock_time}";

        if ($amendment->type === ClockAmendment::TYPE_CLOCK_IN) {
            if ($record) {
                // 已有紀錄，補上班時間
                $attrs = [
                    'clock_in' => $clockTime,
                ];
                // 重新計算遲到
                $attrs['late_minutes'] = $this->calcLateMinutes($record, $amendment->clock_time);
                $attrs['status'] = $this->calcStatus($attrs['late_minutes'], $record->early_leave_minutes ?? 0);
                $this->attendanceRepository->update($record, $attrs);
            } else {
                // 沒有紀錄，新建
                $lateMinutes = $this->calcLateMinutesForNew($amendment->user_id, $dateStr, $amendment->clock_time);
                $this->attendanceRepository->create([
                    'user_id'      => $amendment->user_id,
                    'date'         => $dateStr,
                    'clock_in'     => $clockTime,
                    'late_minutes' => $lateMinutes,
                    'status'       => $lateMinutes > 0 ? AttendanceRecord::STATUS_LATE : AttendanceRecord::STATUS_INCOMPLETE,
                ]);
            }
        } elseif ($amendment->type === ClockAmendment::TYPE_CLOCK_OUT) {
            /*
             * 晚班（例如 16:00–00:00）補下班卡時，申請的日期會是「隔天」——
             * 人是隔天凌晨 00:00 下班的，但那筆出勤紀錄掛在**前一天**。
             *
             * 只查申請日期的話會找不到紀錄，這張單就默默作廢（以前只寫一行 log）。
             * 往前一天補查一次，跟正常打卡的 clockOut() 同一套做法。
             */
            $isOvernight = false;

            if (blank($record) || blank($record->clock_in)) {
                $previous = $this->attendanceRepository->findByUserAndDate(
                    $amendment->user_id,
                    $amendment->date->copy()->subDay()->format('Y-m-d')
                );

                // 只接手「上班了但還沒下班」的那一筆，已經完成的不要覆蓋
                if (filled($previous) && filled($previous->clock_in) && blank($previous->clock_out)) {
                    $record = $previous;
                    $isOvernight = true;
                }
            }

            if (blank($record) || blank($record->clock_in)) {
                Log::warning('補下班卡但無上班紀錄', [
                    'amendment_id' => $amendment->id,
                    'user_id'      => $amendment->user_id,
                    'date'         => $dateStr,
                ]);

                return;
            }

            $earlyMinutes = $this->calcEarlyMinutes($record, $amendment->clock_time, $isOvernight);
            $overtimeMinutes = $this->calcOvertimeMinutes($record, $amendment->clock_time, $isOvernight);
            $status = $this->calcStatus($record->late_minutes ?? 0, $earlyMinutes);

            /*
             * clock_out 存的是**申請日期**的那個時刻（也就是隔天凌晨），
             * 紀錄的 date 維持前一天 —— 跟 clockOut() 存 now() 的模型一致。
             */
            $this->attendanceRepository->update($record, [
                'clock_out'           => $clockTime,
                'early_leave_minutes' => $earlyMinutes,
                'overtime_minutes'    => $overtimeMinutes,
                'status'              => $status,
            ]);
        }
    }

    /**
     * 計算遲到分鐘數
     *
     * @param AttendanceRecord $record
     * @param string           $clockTime HH:mm:ss
     * @return int
     */
    private function calcLateMinutes($record, $clockTime)
    {
        return $this->calcLateMinutesForNew(
            $record->user_id,
            $record->date->format('Y-m-d'),
            $clockTime
        );
    }

    /**
     * 計算遲到（兩種情況共用）
     *
     * ⚠ 原本有兩份幾乎一樣的程式：一份從 `$record->assignment->shift` 取、
     * 一份自己下 `ShiftAssignment::where(...)` 查。兩份都只看得到排班，
     * 所以固定班的人補打卡一律算不出遲到。現在都走 `resolveWorkTime()`，
     * 它回答「這個人這天幾點該上班」而不管那個答案是排班還是帳號設定來的。
     *
     * ⚠ 這裡**不是** `AttendanceService` 那支（那支比的是 `now()`）——
     * 補打卡比的是申請單上填的時刻。
     *
     * @param int    $userId
     * @param string $date      Y-m-d
     * @param string $clockTime HH:mm 或 HH:mm:ss
     * @return int
     */
    private function calcLateMinutesForNew($userId, $date, $clockTime)
    {
        $work = $this->attendanceService->resolveWorkTime($userId, $date);

        // 這天本來就不用上班（沒排到班、或固定班的休假日）→ 沒有遲到可算
        if (blank($work)) {
            return 0;
        }

        return max(0, $this->timeToMinutes($clockTime) - (int) Arr::get($work, 'start_minutes'));
    }

    /**
     * 計算早退分鐘數
     *
     * @param AttendanceRecord $record
     * @param string           $clockTime
     * @return int
     */
    private function calcEarlyMinutes($record, $clockTime, $isOvernight = false)
    {
        $endMin = $this->endMinutesOf($record);

        if (blank($endMin)) {
            return 0;
        }

        return max(0, $endMin - $this->clockMinutes($clockTime, $isOvernight));
    }

    /**
     * 這筆紀錄當天的標準下班時間（從**上班那天**午夜起算的分鐘數）
     *
     * ⚠ 原本兩支（早退、加班）各自從 `$record->assignment->shift` 取，
     * 又各自寫一次「`00:00` 代表到午夜」的換算。現在問 `resolveWorkTime()`，
     * 那支回來的 `end_minutes` 已經把跨日加好了 —— 包括固定班設到隔天
     * 任何時刻（22:00–06:00 → 1800），不只 `00:00` 那一種。
     *
     * @param AttendanceRecord $record
     * @return int|null null = 這天不用上班，沒有早退／加班可算
     */
    private function endMinutesOf($record)
    {
        $work = $this->attendanceService->resolveWorkTime(
            $record->user_id,
            $record->date->format('Y-m-d')
        );

        return filled($work) ? (int) Arr::get($work, 'end_minutes') : null;
    }

    /**
     * 打卡時刻換算成「從該班次當天零點起算」的分鐘數
     *
     * ⚠️ 跨日班一定要加 1440，否則晚班（16:00–00:00）補 `00:00` 的下班卡
     * 會算成 `1440 - 0 = 1440` 分鐘早退 —— 整整一天。
     * 正常打卡的 `AttendanceService::calcEarlyLeaveAndOvertime()` 也是這樣處理的。
     *
     * @param string $clockTime   HH:mm 或 HH:mm:ss
     * @param bool   $isOvernight 這筆下班卡是不是打在隔天
     * @return int
     */
    private function clockMinutes($clockTime, $isOvernight)
    {
        $minutes = $this->timeToMinutes($clockTime);

        return $isOvernight ? $minutes + 1440 : $minutes;
    }

    /**
     * 計算加班分鐘數
     *
     * @param AttendanceRecord $record
     * @param string           $clockTime
     * @return int
     */
    private function calcOvertimeMinutes($record, $clockTime, $isOvernight = false)
    {
        $endMin = $this->endMinutesOf($record);

        if (blank($endMin)) {
            return 0;
        }

        return max(0, $this->clockMinutes($clockTime, $isOvernight) - $endMin);
    }

    /**
     * 根據遲到和早退計算狀態
     *
     * @param int $lateMinutes
     * @param int $earlyMinutes
     * @return int
     */
    private function calcStatus($lateMinutes, $earlyMinutes)
    {
        if ($lateMinutes > 0 && $earlyMinutes > 0) {
            return AttendanceRecord::STATUS_LATE_AND_EARLY;
        }
        if ($lateMinutes > 0) {
            return AttendanceRecord::STATUS_LATE;
        }
        if ($earlyMinutes > 0) {
            return AttendanceRecord::STATUS_EARLY_LEAVE;
        }

        return AttendanceRecord::STATUS_NORMAL;
    }

    /**
     * 時間字串轉分鐘數
     *
     * @param string $time HH:mm 或 HH:mm:ss
     * @return int
     */
    private function timeToMinutes($time)
    {
        $parts = explode(':', $time);

        return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
    }

    /**
     * 取得待審核列表
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPending()
    {
        return $this->amendmentRepository->getPending();
    }

    /**
     * 取得所有申請
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAll()
    {
        return $this->amendmentRepository->getAll();
    }

    /**
     * 取得所有補打卡申請（含原始打卡紀錄）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllWithOriginalRecord()
    {
        $amendments = $this->amendmentRepository->getAll();

        // 批次查詢原始打卡紀錄避免 N+1
        $records = [];
        $amendments->each(function ($a) use (&$records) {
            $key = "{$a->user_id}_{$a->date->format('Y-m-d')}";
            if (!isset($records[$key])) {
                $records[$key] = $this->attendanceRepository->findByUserAndDate($a->user_id, $a->date->format('Y-m-d'));
            }
        });

        return $amendments->map(function ($a) use ($records) {
            $key = "{$a->user_id}_{$a->date->format('Y-m-d')}";
            $record = $records[$key] ?? null;
            $a->original_clock_in = $record && $record->clock_in ? $record->clock_in->format('H:i') : null;
            $a->original_clock_out = $record && $record->clock_out ? $record->clock_out->format('H:i') : null;

            return $a;
        });
    }

    /**
     * 取得個人申請紀錄
     *
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByUser($userId)
    {
        return $this->amendmentRepository->getByUser($userId);
    }

    /**
     * 取得指定員工指定月份的已通過補打卡
     *
     * @param int    $userId
     * @param string $yearMonth
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getApprovedByUserAndMonth($userId, $yearMonth)
    {
        return $this->amendmentRepository->getApprovedByUserAndMonth($userId, $yearMonth);
    }
}
