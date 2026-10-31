<?php

namespace App\Console\Commands;

use App\Services\TaskChangeNoticeService;
use Illuminate\Console\Command;

/**
 * 把任務卡的異動合併通知到「任務卡專區」話題
 *
 * 需求方 2026-11-01：任務卡有更動要通知，而且**同一張卡幾分鐘內的連續異動
 * 要合併成一則**（詳情面板是逐欄位存檔的，整理一張卡會連續打好幾支
 * `ajaxUpdateTask`）。
 *
 * 所以異動當下只記在 `task_activity`（本來就會記），這支每分鐘檢查
 * 「哪些卡片已經安靜滿 N 分鐘」，把那張卡累積的異動併成一則發出去。
 *
 * ⚠ **不要改成在 `TaskBoardService` 裡直接發**：那就回到「改一個欄位發一則」，
 * 正是需求方要避免的事。
 *
 * 合併窗口與單次上限在 `constants.TASK.CHANGE_NOTICE`；
 * 發到哪個話題在「通訊管理 → 通知設定 → 話題分流」勾選
 * （一個都沒勾會發到群組主區）。
 */
class NotifyTaskChangesCommand extends Command
{
    protected $signature = 'task:notify-changes {--dry-run : 只印出會發什麼，不真的送}';

    protected $description = '把安靜滿一段時間的任務卡異動合併通知到內部群組';

    /**
     * @param TaskChangeNoticeService $noticeService
     * @return int
     */
    public function handle(TaskChangeNoticeService $noticeService)
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $noticeService->run($dryRun);

        if ($dryRun) {
            foreach ($result['messages'] as $message) {
                $this->line(str_repeat('-', 40));
                $this->line($message);
            }
        }

        $this->info(sprintf(
            '%s已發送 %d 則、跳過 %d 張、還在等 %d 張',
            $dryRun ? '[dry-run] ' : '',
            $result['sent'],
            $result['skipped'],
            $result['pending']
        ));

        return 0;
    }
}
