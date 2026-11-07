<?php

namespace App\Console\Commands;

use App\Services\AutoReplyResumeService;
use Illuminate\Console\Command;

/**
 * 把忘記勾回來的自動回覆勾回來
 *
 * 客服取消勾選自動回覆改人工，處理完忘記勾回來 —— 那個對話從此一直是人工。
 * 這支每分鐘檢查「哪些轉人工的對話已經靜置夠久」，把它們勾回來並通知
 * 內部支援群組。
 *
 * ⚠ **只看關閉紀錄，不掃 `auto_reply = 0`**：欄位分不出「剛被關掉」與
 * 「從來沒開過」，而後者是刻意一直人工的對話 —— 誤開會把自動回覆的訊息
 * 發給客人，收不回來。
 *
 * 靜置分鐘數由關閉的人在 modal 當場填，所以每一筆各自不同。
 * 總開關預設是關的（通知設定頁可開啟），指令自己會短路。
 */
class AutoReplyResumeCommand extends Command
{
    protected $signature = 'auto-reply:resume';

    protected $description = '把轉人工後靜置夠久的對話，自動勾回自動回覆';

    /**
     * @param AutoReplyResumeService $resumeService
     * @return int
     */
    public function handle(AutoReplyResumeService $resumeService)
    {
        if (!$resumeService->isEnabled()) {
            $this->warn('自動恢復功能未啟用（通知設定頁可開啟）');

            return 0;
        }

        $count = $resumeService->resumeDue();

        $this->info("已恢復 {$count} 個對話的自動回覆");

        return 0;
    }
}
