<?php

namespace App\Console\Commands;

use App\Services\AutoReplySupportService;
use Illuminate\Console\Command;

/**
 * 提醒內部支援群組處理超時的求助單
 *
 * 一直催到單被處理為止（2026-10-06 改，以前只催兩次就不管了）：
 * 第 1、2 次 tag 當下排班的人，第 3 次起同時 tag 主管與老闆（都跳過工程）。
 *
 * 不會變成定時轟炸的煞車是**次數上限** —— 到上限就發一則收尾然後停。
 *
 * 由排程每分鐘執行，首次間隔／之後每隔／上限次數都在後台全域設定頁調整。
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
