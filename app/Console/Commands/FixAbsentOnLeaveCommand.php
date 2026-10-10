<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Repositories\LeaveRequestRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 一次性修正：清掉「請整天假卻被記成曠工」的出勤紀錄
 *
 * `MarkAbsentCommand` 在 2026-10-10 之前沒有檢查請假，請整天假的人每請一天
 * 就被記一天曠工。那支已經修好，但**既有資料還是錯的** —— 不清掉的話，
 * 第一份週報與月報就會帶著錯誤的曠工數字發給主管。
 *
 * ⚠ **這支是一次性的，不要掛進排程。** 跑完就該被忘記；留在 Kernel 裡
 * 等於每天拿一個 DELETE 掃出勤表。
 *
 * ⚠ **預設是 dry-run**：要真的動手必須明確加 `--force`。
 * 這支會刪資料，不該因為少打一個參數就執行。
 *
 * ⚠ **只刪 status = 曠工、而且當天真的有核准整天假的那幾筆。**
 * 曠工紀錄除了 `status` 之外沒有別的內容（沒有打卡時間），
 * 所以刪掉是乾淨的 —— 它本來就不該存在。
 * 時段假不在範圍內：那種情況本來就該打卡，沒打就是曠工。
 */
class FixAbsentOnLeaveCommand extends Command
{
    protected $signature = 'attendance:fix-absent-on-leave
                            {--force : 真的刪除；不加這個參數只會列出要刪哪些}';

    protected $description = '清掉「請整天假卻被記成曠工」的出勤紀錄（一次性）';

    /**
     * @return int
     */
    public function handle()
    {
        $force = (bool) $this->option('force');
        $leaveRepository = app(LeaveRequestRepository::class);

        $absences = AttendanceRecord::query()
            ->select(['id', 'user_id', 'date'])
            ->with('user:id,nickname')
            ->where('status', AttendanceRecord::STATUS_ABSENT)
            ->orderBy('user_id')
            ->orderBy('date')
            ->get();

        if ($absences->isEmpty()) {
            $this->info('沒有任何曠工紀錄，不用處理。');

            return 0;
        }

        $this->info("現有曠工紀錄共 {$absences->count()} 筆，檢查當天有沒有核准的整天假……");

        /*
         * ⚠ **一次撈完**整個曠工紀錄涵蓋的日期範圍，不要在迴圈裡逐筆
         * `hasApprovedFullDayOnDate()` —— 那是 N+1，而且這支要掃的是
         * 「有史以來所有曠工紀錄」，不是一天份。
         */
        $onLeaveMap = $leaveRepository->fullDayLeaveMapByDateRange(
            $absences->min('date')->format('Y-m-d'),
            $absences->max('date')->format('Y-m-d')
        );

        $targets = [];

        foreach ($absences as $record) {
            $date = $record->date->format('Y-m-d');

            if (!in_array((int) $record->user_id, (array) Arr::get($onLeaveMap, $date, []), true)) {
                continue;
            }

            $targets[] = $record;
            $name = filled($record->user) ? $record->user->nickname : "user#{$record->user_id}";
            $this->line("  - {$date}  {$name}");
        }

        $count = count($targets);

        if ($count === 0) {
            $this->info('沒有誤記的曠工，資料是對的。');

            return 0;
        }

        if (!$force) {
            $this->warn("以上 {$count} 筆是誤記的曠工（當天有核准的整天假）。");
            $this->warn('這次沒有刪除任何資料 —— 確認無誤後加 --force 再跑一次。');

            return 0;
        }

        /*
         * 包 transaction：要嘛整批清掉、要嘛一筆都不動。
         * 刪到一半中斷會留下「有些人的報表對了、有些還是錯的」，更難查。
         */
        DB::transaction(function () use ($targets) {
            AttendanceRecord::query()
                ->whereIn('id', array_column($targets, 'id'))
                ->delete();
        });

        $this->info("已刪除 {$count} 筆誤記的曠工。");
        Log::info('清除誤記的曠工紀錄', ['count' => $count]);

        return 0;
    }
}
