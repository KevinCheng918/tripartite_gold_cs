<?php

namespace App\Console\Commands;

use App\Services\TelegramBroadcastService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 送出已到期的預約群發公告
 *
 * 由 Kernel 每分鐘執行。排程週期就是分鐘，所以預約時間只到分鐘。
 */
class SendScheduledBroadcastCommand extends Command
{
    protected $signature = 'telegram:send-scheduled';
    protected $description = '送出已到期的預約群發公告';

    /**
     * @return int
     */
    public function handle()
    {
        $summary = app(TelegramBroadcastService::class)->sendDue();

        if (empty($summary)) {
            return 0;
        }

        foreach ($summary as $item) {
            $this->info("公告 #{$item['id']}：共 {$item['total']} 個群組，成功 {$item['success']}，失敗 {$item['fail']}。");
        }

        Log::info('預約公告發送完成', ['count' => count($summary), 'results' => $summary]);

        return 0;
    }
}
