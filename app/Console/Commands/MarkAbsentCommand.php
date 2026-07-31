<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\ShiftAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 曠工自動標記
 *
 * 每日執行一次，檢查前一天有排班但沒打卡的員工，
 * 自動建立曠工出勤紀錄。
 */
class MarkAbsentCommand extends Command
{
    /** @var string 指令名稱 */
    protected $signature = 'attendance:mark-absent {--date= : 指定日期（Y-m-d），預設為昨天}';

    /** @var string 指令說明 */
    protected $description = '標記有排班但未打卡的員工為曠工';

    /**
     * @return int
     */
    public function handle()
    {
        $date = $this->option('date') ?: now()->subDay()->format('Y-m-d');

        $this->info("檢查日期：{$date}");

        // 取得該日所有排班
        $assignments = ShiftAssignment::query()
            ->select(['id', 'user_id', 'shift_id', 'date'])
            ->where('date', $date)
            ->get();

        if ($assignments->isEmpty()) {
            $this->info('該日無排班紀錄。');
            return 0;
        }

        $markedCount = 0;

        foreach ($assignments as $assignment) {
            // 檢查是否已有打卡紀錄
            $existing = AttendanceRecord::query()
                ->where('user_id', $assignment->user_id)
                ->where('date', $date)
                ->first();

            // 已有紀錄（不管狀態）→ 跳過
            if ($existing) {
                continue;
            }

            // 沒有打卡紀錄 → 標記曠工
            AttendanceRecord::query()->create([
                'user_id'       => $assignment->user_id,
                'assignment_id' => $assignment->id,
                'date'          => $date,
                'status'        => AttendanceRecord::STATUS_ABSENT,
            ]);

            $markedCount++;
        }

        $this->info("已標記 {$markedCount} 筆曠工。");
        Log::info("曠工標記完成", ['date' => $date, 'count' => $markedCount]);

        return 0;
    }
}
