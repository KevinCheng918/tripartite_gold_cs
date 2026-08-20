<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Repositories\AttendanceRepository;
use App\Repositories\ClockAmendmentRepository;
use App\Repositories\LeaveRequestRepository;
use App\Repositories\ShiftAssignmentRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 打卡 Service
 *
 * 處理上下班打卡、遲到/早退/加班計算、月報表彙整。
 */
class AttendanceService
{
    private $attendanceRepository;
    private $assignmentRepository;
    private $amendmentRepository;
    private $leaveRepository;

    public function __construct(
        AttendanceRepository $attendanceRepository,
        ShiftAssignmentRepository $assignmentRepository,
        ClockAmendmentRepository $amendmentRepository,
        LeaveRequestRepository $leaveRepository
    ) {
        $this->attendanceRepository = $attendanceRepository;
        $this->assignmentRepository = $assignmentRepository;
        $this->amendmentRepository = $amendmentRepository;
        $this->leaveRepository = $leaveRepository;
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

        // 找今日排班
        $assignment = $this->findTodayAssignment($userId, $today);

        // 計算遲到
        $lateMinutes = 0;
        if ($assignment && $assignment->shift) {
            $lateMinutes = $this->calcLateMinutes($assignment->shift->start_time);
        }

        $status = $lateMinutes > 0 ? AttendanceRecord::STATUS_LATE : AttendanceRecord::STATUS_INCOMPLETE;

        return $this->attendanceRepository->create([
            'user_id'         => $userId,
            'assignment_id'   => $assignment ? $assignment->id : null,
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
        $assignment = $record->assignment_id
            ? $this->assignmentRepository->find($record->assignment_id)
            : null;

        if ($assignment && $assignment->shift) {
            $endTime = $assignment->shift->end_time;
            $result = $this->calcEarlyLeaveAndOvertime($endTime, $isOvernight);
            $earlyLeaveMinutes = $result['early_leave'];
            $overtimeMinutes = $result['overtime'];

            // 提早上班加班：上班打卡時間早於排班開始時間，差值計為加班
            $overtimeMinutes += $this->calcEarlyClockInOvertime(
                $assignment->shift->start_time,
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
     * 取得所有員工某月的打卡紀錄（管理者月報表）
     *
     * @param string $yearMonth Y-m
     * @return array 按員工分組的統計資料
     */
    public function getMonthlyReport($yearMonth)
    {
        $records = $this->attendanceRepository->getAllByMonth($yearMonth);
        $amendCounts = $this->amendmentRepository->getApprovedCountByMonth($yearMonth);
        $leaveRecords = $this->leaveRepository->getApprovedByMonth($yearMonth);

        $monthStart = "{$yearMonth}-01";
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        // 按員工分組統計
        $grouped = $records->groupBy('user_id');
        $report = [];

        foreach ($grouped as $userId => $userRecords) {
            $user = $userRecords->first()->user;

            // 計算請假統計
            $userLeaves = $leaveRecords->where('user_id', $userId);
            $leaveCount = $userLeaves->count();
            $leaveDays = 0;
            $leaveHours = 0;

            foreach ($userLeaves as $leave) {
                if ((int) $leave->is_full_day === 1) {
                    // 整天假：計算在本月內的天數
                    $start = max(strtotime($monthStart), strtotime($leave->start_date->format('Y-m-d')));
                    $end = min(strtotime($monthEnd), strtotime($leave->end_date->format('Y-m-d')));
                    $days = (int) round(($end - $start) / 86400) + 1;
                    $leaveDays += $days;
                } else {
                    // 時段假：計算小時數（支援跨日，如 19:30 ~ 00:00）
                    $startParts = explode(':', $leave->start_time);
                    $endParts = explode(':', $leave->end_time);
                    $startMin = (int) $startParts[0] * 60 + (int) $startParts[1];
                    $endMin = (int) $endParts[0] * 60 + (int) $endParts[1];
                    $minutes = $endMin - $startMin;
                    if ($minutes <= 0) { $minutes += 1440; } // 跨日加 24 小時
                    $leaveHours += $minutes / 60;
                }
            }

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
                'records'              => $userRecords->values(),
            ];
        }

        return $report;
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
     * @param string $workStart 上班時間 HH:mm:ss
     * @return int
     */
    private function calcLateMinutes($workStart)
    {
        $parts = explode(':', $workStart);
        $startMinutes = (int) $parts[0] * 60 + (int) $parts[1];
        $nowMinutes = now()->hour * 60 + now()->minute;

        $diff = $nowMinutes - $startMinutes;

        return $diff > 0 ? $diff : 0;
    }

    /**
     * 計算提早上班的加班分鐘數
     *
     * @param string                $shiftStart 排班開始時間 HH:mm:ss
     * @param \Carbon\Carbon|string $clockIn    實際上班打卡時間
     * @return int
     */
    private function calcEarlyClockInOvertime($shiftStart, $clockIn)
    {
        $parts = explode(':', $shiftStart);
        $startMinutes = (int) $parts[0] * 60 + (int) $parts[1];

        $clockInTime = \Carbon\Carbon::parse($clockIn);
        $clockInMinutes = $clockInTime->hour * 60 + $clockInTime->minute;

        $diff = $startMinutes - $clockInMinutes;

        return $diff > 0 ? $diff : 0;
    }

    /**
     * 計算早退和加班分鐘數
     *
     * @param string $endTime     班別結束時間 HH:mm:ss
     * @param bool   $isOvernight 是否為跨日打卡（下班打卡日期 ≠ 上班打卡日期）
     * @return array{early_leave: int, overtime: int}
     */
    private function calcEarlyLeaveAndOvertime($endTime, $isOvernight = false)
    {
        $parts = explode(':', $endTime);
        $endMinutes = (int) $parts[0] * 60 + (int) $parts[1];
        $nowMinutes = now()->hour * 60 + now()->minute;

        // 跨日打卡：目前時間已過午夜，加上一天的分鐘數才能正確比較
        if ($isOvernight) {
            $nowMinutes += 1440; // 24 * 60
        }

        // 處理跨日班（endTime 00:00 代表到午夜）
        if ($endMinutes === 0) {
            $endMinutes = 1440;
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
