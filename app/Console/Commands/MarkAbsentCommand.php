<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Repositories\AttendanceRepository;
use App\Repositories\LeaveRequestRepository;
use App\Repositories\ShiftAssignmentRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 曠工自動標記
 *
 * 每日執行一次，檢查前一天有排班但沒打卡的員工，
 * 自動建立曠工出勤紀錄。
 *
 * ⚠ **請整天假的人要跳過**（2026-10-10 修）。
 *
 * 原本只看「有排班 + 沒打卡」就標曠工，完全沒問請假 —— 於是請整天假的人
 * 每請一天就被記一天曠工（他本來就不該打卡）。
 *
 * 這不只是數字難看：出勤報表的文案規則是「沒有遲到／早退／曠工但有請假 →
 * 就說幾號到幾號請假」，請假的人身上永遠掛著曠工，那個分支一輩子走不到。
 *
 * ⚠ **時段假不跳過**：請半天或幾小時的人當天仍然要上班、仍然要打卡，
 * 沒打就是沒打。所以只認 `hasApprovedFullDayOnDate()`。
 */
class MarkAbsentCommand extends Command
{
    protected $signature = 'attendance:mark-absent {--date= : 指定日期（Y-m-d），預設為昨天}';
    protected $description = '標記有排班但未打卡的員工為曠工';

    /**
     * @return int
     */
    public function handle()
    {
        $date = $this->option('date') ?: now()->subDay()->format('Y-m-d');

        $this->info("檢查日期：{$date}");

        $assignmentRepository = app(ShiftAssignmentRepository::class);
        $attendanceRepository = app(AttendanceRepository::class);
        $leaveRepository = app(LeaveRequestRepository::class);

        // 取得該日所有排班
        $assignments = $assignmentRepository->getByDateRange($date, $date);

        if ($assignments->isEmpty()) {
            $this->info('該日無排班紀錄。');
            return 0;
        }

        $markedCount = 0;
        $skippedOnLeave = 0;

        foreach ($assignments as $assignment) {
            // 檢查是否已有打卡紀錄
            $existing = $attendanceRepository->findByUserAndDate($assignment->user_id, $date);

            if ($existing) {
                continue;
            }

            // 請整天假 → 本來就不用打卡，不是曠工
            if ($leaveRepository->hasApprovedFullDayOnDate($assignment->user_id, $date)) {
                $skippedOnLeave++;

                continue;
            }

            // 沒有打卡紀錄 → 標記曠工
            $attendanceRepository->create([
                'user_id'       => $assignment->user_id,
                'assignment_id' => $assignment->id,
                'date'          => $date,
                'status'        => AttendanceRecord::STATUS_ABSENT,
            ]);

            $markedCount++;
        }

        $this->info("已標記 {$markedCount} 筆曠工，{$skippedOnLeave} 筆因請整天假而跳過。");
        Log::info('曠工標記完成', [
            'date'             => $date,
            'count'            => $markedCount,
            'skipped_on_leave' => $skippedOnLeave,
        ]);

        return 0;
    }
}
