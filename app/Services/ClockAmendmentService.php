<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\ClockAmendment;
use App\Repositories\AttendanceRepository;
use App\Repositories\ClockAmendmentRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 補打卡申請 Service
 */
class ClockAmendmentService
{
    private $amendmentRepository;
    private $attendanceRepository;

    public function __construct(
        ClockAmendmentRepository $amendmentRepository,
        AttendanceRepository $attendanceRepository
    ) {
        $this->amendmentRepository = $amendmentRepository;
        $this->attendanceRepository = $attendanceRepository;
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
            if (!$record || !$record->clock_in) {
                Log::warning('補下班卡但無上班紀錄', [
                    'amendment_id' => $amendment->id,
                    'user_id'      => $amendment->user_id,
                    'date'         => $dateStr,
                ]);
                return;
            }

            $earlyMinutes = $this->calcEarlyMinutes($record, $amendment->clock_time);
            $overtimeMinutes = $this->calcOvertimeMinutes($record, $amendment->clock_time);
            $status = $this->calcStatus($record->late_minutes ?? 0, $earlyMinutes);

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
        if (!$record->assignment || !$record->assignment->shift) {
            return 0;
        }

        $startTime = $record->assignment->shift->start_time;
        $startMin = $this->timeToMinutes($startTime);
        $clockMin = $this->timeToMinutes($clockTime);

        return max(0, $clockMin - $startMin);
    }

    /**
     * 新建紀錄時計算遲到（需查排班）
     *
     * @param int    $userId
     * @param string $date
     * @param string $clockTime
     * @return int
     */
    private function calcLateMinutesForNew($userId, $date, $clockTime)
    {
        $assignment = \App\Models\ShiftAssignment::query()
            ->select(['id', 'shift_id'])
            ->with(['shift'])
            ->where('user_id', $userId)
            ->where('date', $date)
            ->first();

        if (!$assignment || !$assignment->shift) {
            return 0;
        }

        $startMin = $this->timeToMinutes($assignment->shift->start_time);
        $clockMin = $this->timeToMinutes($clockTime);

        return max(0, $clockMin - $startMin);
    }

    /**
     * 計算早退分鐘數
     *
     * @param AttendanceRecord $record
     * @param string           $clockTime
     * @return int
     */
    private function calcEarlyMinutes($record, $clockTime)
    {
        if (!$record->assignment || !$record->assignment->shift) {
            return 0;
        }

        $endTime = $record->assignment->shift->end_time;
        $endMin = $this->timeToMinutes($endTime);
        if ($endMin === 0) { $endMin = 1440; }
        $clockMin = $this->timeToMinutes($clockTime);

        return max(0, $endMin - $clockMin);
    }

    /**
     * 計算加班分鐘數
     *
     * @param AttendanceRecord $record
     * @param string           $clockTime
     * @return int
     */
    private function calcOvertimeMinutes($record, $clockTime)
    {
        if (!$record->assignment || !$record->assignment->shift) {
            return 0;
        }

        $endTime = $record->assignment->shift->end_time;
        $endMin = $this->timeToMinutes($endTime);
        if ($endMin === 0) { $endMin = 1440; }
        $clockMin = $this->timeToMinutes($clockTime);

        return max(0, $clockMin - $endMin);
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
     * 取得個人申請紀錄
     *
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByUser($userId)
    {
        return $this->amendmentRepository->getByUser($userId);
    }
}
