<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Repositories\AttendanceRepository;
use App\Repositories\ClockAmendmentRepository;
use App\Repositories\LeaveRequestRepository;
use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 打卡 Service
 *
 * 處理上下班打卡、遲到/早退/加班計算、月報表彙整。
 *
 * ⚠ **「這個人今天幾點上下班」只有一個答案的來源：`resolveWorkTime()`。**
 * 輪班的人從排班拿、固定班的人從帳號上拿 —— 遲到、早退、加班、曠工四個判定
 * 都走那一支，不要在各自的地方再寫一次「有排班用排班、沒有就看固定班」。
 * 那是同一條規則的四份拷貝（見 [[fixed-work-hours]]）。
 */
class AttendanceService
{
    private $attendanceRepository;
    private $assignmentRepository;
    private $amendmentRepository;
    private $leaveRepository;
    private $userRepository;

    public function __construct(
        AttendanceRepository $attendanceRepository,
        ShiftAssignmentRepository $assignmentRepository,
        ClockAmendmentRepository $amendmentRepository,
        LeaveRequestRepository $leaveRepository,
        UserRepository $userRepository
    ) {
        $this->attendanceRepository = $attendanceRepository;
        $this->assignmentRepository = $assignmentRepository;
        $this->amendmentRepository = $amendmentRepository;
        $this->leaveRepository = $leaveRepository;
        // 固定班的上下班時間存在帳號上，不在排班表裡
        $this->userRepository = $userRepository;
    }

    /**
     * 這個人這一天的標準上下班時間
     *
     * 出勤的四個判定（遲到／早退／加班／曠工）都問這一支，兩種出勤模式的
     * 差別只在這裡分岔，分岔之後的計算完全一樣。
     *
     * ⚠ 回 `null` 代表**這天不用上班**：輪班沒排到、或固定班不在 `work_days` 裡。
     * 呼叫端要分得出「不用上班」與「該上班卻沒打卡」—— 後者才是曠工。
     *
     * ⚠ 跨日班（`end <= start`，例如 22:00–06:00）的 `work_days`
     * 指的是**開始上班那天**。勾了週五就是週五晚上上班、週六清晨下班。
     *
     * @param int|\App\Models\User $user 已經撈好的 User 傳進來，避免在迴圈裡逐人查
     * @param string               $date Y-m-d
     * @return array{start: string, end: string, assignment_id: int|null, is_fixed: bool, is_overnight: bool}|null
     */
    public function resolveWorkTime($user, $date)
    {
        $user = $user instanceof \App\Models\User ? $user : $this->userRepository->find((int) $user);

        if (blank($user)) {
            return null;
        }

        // 短路：絕大多數人是輪班，不必碰帳號上那幾個欄位
        if (!$user->isFixedWorkMode()) {
            return $this->workTimeFromAssignment((int) $user->id, $date);
        }

        return $this->workTimeFromUser($user, $date);
    }

    /**
     * 輪班：從當天的排班指派拿
     *
     * @param int    $userId
     * @param string $date
     * @return array|null
     */
    private function workTimeFromAssignment($userId, $date)
    {
        $assignment = $this->findTodayAssignment($userId, $date);

        if (blank($assignment) || blank($assignment->shift)) {
            return null;
        }

        return $this->buildWorkTime(
            (string) $assignment->shift->start_time,
            (string) $assignment->shift->end_time,
            (int) $assignment->id,
            false
        );
    }

    /**
     * 固定班：從帳號上設定的起訖與上班日拿
     *
     * @param \App\Models\User $user
     * @param string           $date
     * @return array|null
     */
    private function workTimeFromUser($user, $date)
    {
        // 設定不完整（勾了固定班卻沒填時間）＝ 無從判定，當成不用上班
        if (blank($user->work_start_time) || blank($user->work_end_time)) {
            return null;
        }

        // Carbon 的 dayOfWeekIso：1=週一 … 7=週日，跟 work_days 的存法一致
        if (!in_array(Carbon::parse($date)->dayOfWeekIso, $user->workDayList(), true)) {
            return null;
        }

        return $this->buildWorkTime(
            (string) $user->work_start_time,
            (string) $user->work_end_time,
            // 固定班沒有排班指派，紀錄上這一欄會是 null
            null,
            true
        );
    }

    /**
     * 一批出勤紀錄各自對應的標準上下班時間（出勤明細顯示用）
     *
     * 明細頁要顯示班別、提早上班幾分、延後下班幾分 —— 那些都需要「那天本來
     * 幾點上下班」。原本 Blade 直接從 `$r->assignment->shift` 取，
     * 於是固定班的人整欄都是 `-`。
     *
     * ⚠ **不逐日再查一次 DB**：輪班直接用紀錄上已經 eager load 的 assignment
     * （`getByUserAndMonth()` 帶了 `with('assignment.shift')`），固定班根本
     * 不必查 —— 時間在帳號上。一個月 31 筆逐日打 `resolveWorkTime()` 就是 31 次查詢。
     *
     * @param \App\Models\User $user
     * @param iterable         $records
     * @return array<string, array|null> date => work time（null = 那天不用上班）
     */
    public function workTimesForRecords($user, $records)
    {
        $map = [];
        $isFixed = filled($user) && $user->isFixedWorkMode();

        foreach ($records as $record) {
            $date = $record->date->format('Y-m-d');

            if ($isFixed) {
                $map[$date] = $this->workTimeFromUser($user, $date);

                continue;
            }

            $assignment = $record->assignment;

            $map[$date] = filled($assignment) && filled($assignment->shift)
                ? $this->buildWorkTime(
                    (string) $assignment->shift->start_time,
                    (string) $assignment->shift->end_time,
                    (int) $assignment->id,
                    false
                )
                : null;
        }

        return $map;
    }

    /**
     * 把一組上下班時間整理成計算用的形狀
     *
     * ⚠ **`end_minutes` 一律換算成「從上班那天午夜起算」。** 既有班別用
     * `00:00` 表示「到午夜」（＝1440），固定班則可以設到隔天任何時刻
     * （22:00–06:00 → 1800）。在這裡一次算好，後面的早退／加班就不必各自
     * 認得「哪一種寫法代表跨日」—— `ClockAmendmentService` 以前就是因為要
     * 自己認 `00:00`，而在兩個方法裡各寫了一次 `if ($endMin === 0)`。
     *
     * @param string   $start        HH:mm:ss
     * @param string   $end          HH:mm:ss
     * @param int|null $assignmentId
     * @param bool     $isFixed
     * @return array
     */
    private function buildWorkTime($start, $end, $assignmentId, $isFixed)
    {
        $startMinutes = $this->toMinutes($start);
        $endMinutes = $this->toMinutes($end);

        // 不晚於上班時間就是跨日（`00:00` → 0 <= 任何上班時間，自然命中）
        $isOvernight = $endMinutes <= $startMinutes;

        return [
            'start'         => $start,
            'end'           => $end,
            'start_minutes' => $startMinutes,
            'end_minutes'   => $isOvernight ? $endMinutes + 1440 : $endMinutes,
            'assignment_id' => $assignmentId,
            'is_fixed'      => $isFixed,
            'is_overnight'  => $isOvernight,
        ];
    }

    /**
     * HH:mm:ss → 從午夜起算的分鐘數
     *
     * @param string $time
     * @return int
     */
    private function toMinutes($time)
    {
        $parts = explode(':', (string) $time);

        return (int) Arr::get($parts, 0, 0) * 60 + (int) Arr::get($parts, 1, 0);
    }

    /**
     * 上班打卡
     *
     * @param int    $userId
     * @param string $ip
     * @param string $device
     * @return AttendanceRecord
     * @throws ValidationException
     */
    public function clockIn($userId, $ip, $device)
    {
        $today = now()->format('Y-m-d');

        // 檢查今日是否有整天請假
        if ($this->leaveRepository->hasApprovedFullDayOnDate($userId, $today)) {
            throw ValidationException::withMessages([
                'clock_in' => [trans('leave.msg.on_leave_today')],
            ]);
        }

        $existing = $this->attendanceRepository->findByUserAndDate($userId, $today);

        if ($existing && filled($existing->clock_in)) {
            throw ValidationException::withMessages([
                'clock_in' => [trans('attendance.msg.already_clocked_in')],
            ]);
        }

        // 檢查昨日是否有未完成的跨日班打卡（未下班就不允許打新的上班卡）
        $yesterday = now()->subDay()->format('Y-m-d');
        $yesterdayRecord = $this->attendanceRepository->findByUserAndDate($userId, $yesterday);

        if ($yesterdayRecord && filled($yesterdayRecord->clock_in) && !filled($yesterdayRecord->clock_out)) {
            throw ValidationException::withMessages([
                'clock_in' => [trans('attendance.msg.previous_not_clocked_out')],
            ]);
        }

        /*
         * 今天的標準上下班時間：輪班從排班拿、固定班從帳號拿。
         *
         * null ＝ 這天不用上班（沒排到班、或固定班的休假日）。
         * **仍然可以打卡** —— 假日臨時進來處理事情是常有的，只是沒有遲到可算。
         */
        $work = $this->resolveWorkTime($userId, $today);

        $lateMinutes = filled($work)
            ? $this->calcLateMinutes((int) Arr::get($work, 'start_minutes'))
            : 0;

        $status = $lateMinutes > 0 ? AttendanceRecord::STATUS_LATE : AttendanceRecord::STATUS_INCOMPLETE;

        return $this->attendanceRepository->create([
            'user_id'         => $userId,
            'assignment_id'   => Arr::get($work, 'assignment_id'),
            'date'            => $today,
            'clock_in'        => now(),
            'clock_in_ip'     => $ip,
            'clock_in_device' => $device,
            'late_minutes'    => $lateMinutes,
            'status'          => $status,
        ]);
    }

    /**
     * 下班打卡
     *
     * @param int    $userId
     * @param string $ip
     * @param string $device
     * @return AttendanceRecord
     * @throws ValidationException
     */
    public function clockOut($userId, $ip, $device)
    {
        $today = now()->format('Y-m-d');
        $record = $this->attendanceRepository->findByUserAndDate($userId, $today);
        $isOvernight = false;

        // 今日找不到紀錄，檢查昨日是否有未完成的跨日班打卡
        if (!$record || !filled($record->clock_in)) {
            $yesterday = now()->subDay()->format('Y-m-d');
            $yesterdayRecord = $this->attendanceRepository->findByUserAndDate($userId, $yesterday);

            if ($yesterdayRecord && filled($yesterdayRecord->clock_in) && !filled($yesterdayRecord->clock_out)) {
                $record = $yesterdayRecord;
                $isOvernight = true;
            }
        }

        if (!$record || !filled($record->clock_in)) {
            throw ValidationException::withMessages([
                'clock_out' => [trans('attendance.msg.not_clocked_in')],
            ]);
        }

        if (filled($record->clock_out)) {
            throw ValidationException::withMessages([
                'clock_out' => [trans('attendance.msg.already_clocked_out')],
            ]);
        }

        // 計算早退和加班
        $earlyLeaveMinutes = 0;
        $overtimeMinutes = 0;

        /*
         * ⚠ 用**那筆紀錄的日期**問，不是今天 —— 跨日班的下班卡打在隔天，
         * 標準時間要看上班那一天（固定班的 `work_days` 也是記開始那天）。
         *
         * ⚠ 不再從 `$record->assignment_id` 回查班別：固定班沒有 assignment，
         * 那條路會直接跳過整段計算，遲到早退加班全部是 0。
         */
        $work = $this->resolveWorkTime($record->user_id, $record->date->format('Y-m-d'));

        if (filled($work)) {
            $result = $this->calcEarlyLeaveAndOvertime((int) Arr::get($work, 'end_minutes'), $isOvernight);
            $earlyLeaveMinutes = $result['early_leave'];
            $overtimeMinutes = $result['overtime'];

            // 提早上班加班：上班打卡時間早於標準上班時間，差值計為加班
            $overtimeMinutes += $this->calcEarlyClockInOvertime(
                (int) Arr::get($work, 'start_minutes'),
                $record->clock_in
            );
        }

        // 時段假加班計算：打卡時間超過請假開始時間，超出部分算加班
        $recordDate = $record->date->format('Y-m-d');
        $leaves = $this->leaveRepository->getApprovedOnDate($userId, $recordDate);
        foreach ($leaves as $leave) {
            if ((int) $leave->is_full_day === 1) {
                continue;
            }
            if (filled($leave->start_time)) {
                $leaveStartParts = explode(':', $leave->start_time);
                $leaveStartMin = (int) $leaveStartParts[0] * 60 + (int) $leaveStartParts[1];
                $clockOutMin = now()->hour * 60 + now()->minute;
                if ($clockOutMin > $leaveStartMin) {
                    $overtimeMinutes += ($clockOutMin - $leaveStartMin);
                }
            }
        }

        // 計算最終狀態
        $isLate = $record->late_minutes > 0;
        $isEarly = $earlyLeaveMinutes > 0;

        if ($isLate && $isEarly) {
            $status = AttendanceRecord::STATUS_LATE_AND_EARLY;
        } elseif ($isLate) {
            $status = AttendanceRecord::STATUS_LATE;
        } elseif ($isEarly) {
            $status = AttendanceRecord::STATUS_EARLY_LEAVE;
        } else {
            $status = AttendanceRecord::STATUS_NORMAL;
        }

        return $this->attendanceRepository->update($record, [
            'clock_out'           => now(),
            'clock_out_ip'        => $ip,
            'clock_out_device'    => $device,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'overtime_minutes'    => $overtimeMinutes,
            'status'              => $status,
        ]);
    }

    /**
     * 取得今日打卡狀態
     *
     * @param int $userId
     * @return AttendanceRecord|null
     */
    public function getTodayRecord($userId)
    {
        $record = $this->attendanceRepository->findByUserAndDate($userId, now()->format('Y-m-d'));

        if ($record) {
            return $record;
        }

        // 檢查昨日是否有未完成的跨日班打卡（上班打卡但未下班）
        $yesterday = now()->subDay()->format('Y-m-d');
        $yesterdayRecord = $this->attendanceRepository->findByUserAndDate($userId, $yesterday);

        if ($yesterdayRecord && filled($yesterdayRecord->clock_in) && !filled($yesterdayRecord->clock_out)) {
            return $yesterdayRecord;
        }

        return null;
    }

    /**
     * 取得指定員工某月的打卡紀錄
     *
     * @param int    $userId
     * @param string $yearMonth Y-m
     * @return Collection
     */
    public function getMonthlyRecords($userId, $yearMonth)
    {
        $records = $this->attendanceRepository->getByUserAndMonth($userId, $yearMonth);

        // 帶入每天的請假資訊
        $records->each(function ($record) use ($userId) {
            $date = $record->date->format('Y-m-d');
            $leaves = $this->leaveRepository->getApprovedOnDate($userId, $date);
            if ($leaves->isNotEmpty()) {
                $leave = $leaves->first();
                $record->leave_info = [
                    'is_full_day' => (int) $leave->is_full_day,
                    'start_time'  => $leave->start_time,
                    'end_time'    => $leave->end_time,
                ];
            }
        });

        return $records;
    }

    /**
     * 取得所有員工某段期間的出勤統計
     *
     * 週報（週一～週日）與月報共用這一支；Telegram 通知與後台報表頁都走這裡。
     *
     * ⚠ **回傳的陣列只包含「這段期間有打卡紀錄的人」。** 整段期間都請假、
     * 或根本沒排班的人不會出現 —— 呼叫端如果要「每個人都發一則」，
     * 得自己從同仁名單出發，查不到就當成全部是 0（見 `AttendanceReportService`）。
     *
     * @param string $startDate Y-m-d
     * @param string $endDate   Y-m-d（含當天）
     * @return array 按員工分組的統計資料
     */
    public function getReport($startDate, $endDate)
    {
        $records = $this->attendanceRepository->getAllByDateRange($startDate, $endDate);
        $amendCounts = $this->amendmentRepository->getApprovedCountByDateRange($startDate, $endDate);
        $leaveRecords = $this->leaveRepository->getApprovedByDateRange($startDate, $endDate);

        // 下面算請假天數時要跟區間取交集，沿用原本的變數名
        $monthStart = $startDate;
        $monthEnd = $endDate;

        // 按員工分組統計
        $grouped = $records->groupBy('user_id');
        $report = [];

        foreach ($grouped as $userId => $userRecords) {
            $user = $userRecords->first()->user;

            $leave = $this->summariseLeaves($leaveRecords->where('user_id', $userId), $monthStart, $monthEnd);
            $leaveCount = Arr::get($leave, 'count');
            $leaveDays = Arr::get($leave, 'days');
            $leaveHours = Arr::get($leave, 'hours');

            $report[] = [
                'user'                 => $user,
                'total_days'           => $userRecords->count(),
                'normal_days'          => $userRecords->where('status', AttendanceRecord::STATUS_NORMAL)->count(),
                'late_count'           => $userRecords->whereIn('status', [AttendanceRecord::STATUS_LATE, AttendanceRecord::STATUS_LATE_AND_EARLY])->count(),
                'late_total_minutes'   => $userRecords->sum('late_minutes'),
                'early_count'          => $userRecords->whereIn('status', [AttendanceRecord::STATUS_EARLY_LEAVE, AttendanceRecord::STATUS_LATE_AND_EARLY])->count(),
                'early_total_minutes'  => $userRecords->sum('early_leave_minutes'),
                'absent_count'         => $userRecords->where('status', AttendanceRecord::STATUS_ABSENT)->count(),
                'overtime_total_minutes' => $userRecords->sum('overtime_minutes'),
                'amend_count'          => $amendCounts->get($userId, 0),
                'leave_count'          => $leaveCount,
                'leave_days'           => $leaveDays,
                'leave_hours'          => round($leaveHours, 1),
                'leave_ranges'         => Arr::get($leave, 'ranges'),
                'records'              => $userRecords->values(),
            ];
        }

        return $report;
    }

    /**
     * 把一個人的請假單彙總成天數、時數與區間
     *
     * ⚠ **跟統計區間取交集**：一筆 10/28～11/03 的假，在十月的報表只算
     * 10/28～10/31、在十一月只算 11/01～11/03。不取交集的話同一筆假
     * 會在兩份報表裡各算滿天數。
     *
     * ⚠ 整天假算「天」、時段假算「小時」，**兩者不互相換算** ——
     * 一天幾小時取決於班別，硬換會得到一個誰都不認得的數字。
     *
     * ⚠ 這支是 public：出勤報表通知要用 `ranges` 寫「10/08 ～ 10/09 請假」，
     * 而且必須跟這裡算出來的天數是同一套邏輯，不能各算各的。
     *
     * @param iterable $leaves    這個人在區間內的已核准請假單
     * @param string   $startDate Y-m-d
     * @param string   $endDate   Y-m-d
     * @return array count / days / hours / ranges（每筆 start、end、is_full_day）
     */
    public function summariseLeaves($leaves, $startDate, $endDate)
    {
        $count = 0;
        $days = 0;
        $hours = 0;
        $ranges = [];

        foreach ($leaves as $leave) {
            $count++;

            // 不論整天或時段，顯示用的區間都先跟統計區間夾好
            $from = max(strtotime($startDate), strtotime($leave->start_date->format('Y-m-d')));
            $to = min(strtotime($endDate), strtotime($leave->end_date->format('Y-m-d')));

            $ranges[] = [
                'start'       => date('Y-m-d', $from),
                'end'         => date('Y-m-d', $to),
                'is_full_day' => (int) $leave->is_full_day === 1,
            ];

            if ((int) $leave->is_full_day === 1) {
                $days += (int) round(($to - $from) / 86400) + 1;

                continue;
            }

            // 時段假：計算小時數（支援跨日，如 19:30 ~ 00:00）
            $startParts = explode(':', $leave->start_time);
            $endParts = explode(':', $leave->end_time);
            $startMin = (int) $startParts[0] * 60 + (int) $startParts[1];
            $endMin = (int) $endParts[0] * 60 + (int) $endParts[1];
            $minutes = $endMin - $startMin;

            if ($minutes <= 0) {
                $minutes += 1440; // 跨日加 24 小時
            }

            $hours += $minutes / 60;
        }

        return [
            'count'  => $count,
            'days'   => $days,
            'hours'  => round($hours, 1),
            'ranges' => $ranges,
        ];
    }

    // ---------------------------------------------------------------
    //  私有方法
    // ---------------------------------------------------------------

    /**
     * 找今日排班紀錄
     *
     * @param int    $userId
     * @param string $date
     * @return \App\Models\ShiftAssignment|null
     */
    private function findTodayAssignment($userId, $date)
    {
        $assignments = $this->assignmentRepository->getByUserAndDateRange($userId, $date, $date);

        return $assignments->first();
    }

    /**
     * 計算遲到分鐘數
     *
     * ⚠ **已知限制**：跨日班在午夜之後才打上班卡（22:00 的班拖到 00:30 才打）
     * 算不出遲到 —— `now()` 的 00:30 比上班時間 22:00 小，相減是負數。
     * 這不是這次改出來的，輪班的跨日班本來就是如此；而且午夜後才打卡時，
     * 那一天已經換日，`resolveWorkTime()` 查的是新的一天（固定班的
     * `work_days` 記的是開始那天），根本拿不到前一晚那組時間。
     * 要修得連「往前一天找未開始的跨日班」一起做，不在這次範圍。
     *
     * @param int $startMinutes 標準上班時間，從午夜起算的分鐘數
     * @return int
     */
    private function calcLateMinutes($startMinutes)
    {
        $nowMinutes = now()->hour * 60 + now()->minute;

        $diff = $nowMinutes - $startMinutes;

        return $diff > 0 ? $diff : 0;
    }

    /**
     * 計算提早上班的加班分鐘數
     *
     * @param int                   $startMinutes 標準上班時間，從午夜起算的分鐘數
     * @param \Carbon\Carbon|string $clockIn      實際上班打卡時間
     * @return int
     */
    private function calcEarlyClockInOvertime($startMinutes, $clockIn)
    {
        $clockInTime = Carbon::parse($clockIn);
        $clockInMinutes = $clockInTime->hour * 60 + $clockInTime->minute;

        $diff = $startMinutes - $clockInMinutes;

        return $diff > 0 ? $diff : 0;
    }

    /**
     * 計算早退和加班分鐘數
     *
     * ⚠ 兩個「跨日」是不同的東西，不要混在一起看：
     *
     * | | 意思 | 誰算的 |
     * |---|---|---|
     * | `$endMinutes` 已經 > 1440 | **班別設定**跨日（22:00–06:00、或 end 是 00:00） | `buildWorkTime()` |
     * | `$isOvernight` | **這次打卡**真的打在隔天 | 呼叫端（今天沒紀錄、昨天有未完成的） |
     *
     * 原本這裡自己認 `endTime === '00:00'` 把它當 1440，那只蓋得住「到午夜」
     * 這一種寫法；固定班可以設到隔天任何時刻，所以換算移到 `buildWorkTime()`。
     *
     * @param int  $endMinutes  標準下班時間，從**上班那天**午夜起算的分鐘數
     * @param bool $isOvernight 這次下班打卡是否打在隔天
     * @return array{early_leave: int, overtime: int}
     */
    private function calcEarlyLeaveAndOvertime($endMinutes, $isOvernight = false)
    {
        $nowMinutes = now()->hour * 60 + now()->minute;

        // 跨日打卡：目前時間已過午夜，加上一天的分鐘數才能正確比較
        if ($isOvernight) {
            $nowMinutes += 1440; // 24 * 60
        }

        $diff = $nowMinutes - $endMinutes;

        if ($diff < 0) {
            // 提前下班 → 早退
            return ['early_leave' => abs($diff), 'overtime' => 0];
        }

        // 正常或加班
        return ['early_leave' => 0, 'overtime' => $diff];
    }
}
