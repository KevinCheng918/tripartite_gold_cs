<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Repositories\AttendanceRepository;
use App\Repositories\LeaveRequestRepository;
use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 曠工自動標記
 *
 * 每日執行一次，檢查前一天「該上班卻沒打卡」的人，自動建立曠工出勤紀錄。
 *
 * ⚠ **跑兩輪，因為「該上班」有兩種來源**（2026-10-12）：
 *
 * | 輪 | 對象 | 怎麼知道他該上班 |
 * |---|---|---|
 * | 一 | 輪班 | 當天有 `shift_assignments` |
 * | 二 | 固定班 | 帳號上的 `work_days` 包含當天的星期 |
 *
 * 第二輪不能省：工程不在排班名單裡，只跑第一輪的話他們**永遠不會被標曠工**，
 * 而這正是這個功能要解決的事。
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
        $userRepository = app(UserRepository::class);
        $attendanceService = app(AttendanceService::class);

        $markedCount = 0;
        $skippedOnLeave = 0;

        // ⚠ 一次撈完當天請整天假的人，不要在迴圈裡逐人查 —— 那就是 N+1
        $onLeave = (array) Arr::get($leaveRepository->fullDayLeaveMapByDateRange($date, $date), $date, []);

        // ===== 第一輪：輪班（當天有排班指派） =====
        foreach ($assignmentRepository->getByDateRange($date, $date) as $assignment) {
            // 檢查是否已有打卡紀錄
            $existing = $attendanceRepository->findByUserAndDate($assignment->user_id, $date);

            if ($existing) {
                continue;
            }

            // 請整天假 → 本來就不用打卡，不是曠工
            if (in_array((int) $assignment->user_id, $onLeave, true)) {
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

        // ===== 第二輪：固定班（帳號上的 work_days 包含當天） =====
        foreach ($userRepository->getFixedWorkModeUsers() as $user) {
            /*
             * 「這天該不該上班」交給 `resolveWorkTime()` 回答，不要在這裡自己
             * 比對星期 —— 那條規則同時被打卡、補打卡用著，寫第二份就會分岔。
             * null ＝ 不是他的上班日。
             */
            if (blank($attendanceService->resolveWorkTime($user, $date))) {
                continue;
            }

            if ($attendanceRepository->findByUserAndDate($user->id, $date)) {
                continue;
            }

            if (in_array((int) $user->id, $onLeave, true)) {
                $skippedOnLeave++;

                continue;
            }

            $attendanceRepository->create([
                'user_id' => $user->id,
                // 固定班沒有排班指派
                'assignment_id' => null,
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
