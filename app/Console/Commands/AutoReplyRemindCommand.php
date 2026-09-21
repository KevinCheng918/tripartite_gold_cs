<?php

namespace App\Console\Commands;

use App\Services\AutoReplySupportService;
use Illuminate\Console\Command;

/**
 * 提醒內部支援群組處理超時的求助單
 *
 * 分兩級：第一次 tag 當下排班的人，再沒回就 tag 主管與老闆（兩級都跳過工程）。
 * 每張單最多提醒兩次，不會變成定時轟炸。
 *
 * 由排程每分鐘執行，實際超時分鐘數在後台全域設定頁調整。
 */
class AutoReplyRemindCommand extends Command
{
    protected $signature = 'auto-reply:remind';

    protected $description = '提醒內部支援群組處理超時未回答的求助單';

    /**
     * @param AutoReplySupportService $supportService
     * @return int
     */
    public function handle(AutoReplySupportService $supportService)
    {
        $count = $supportService->remindTimeoutTickets();

        $this->info("已送出 {$count} 則提醒");

        return 0;
    }
}
