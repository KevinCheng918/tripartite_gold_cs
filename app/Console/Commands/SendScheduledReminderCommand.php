<?php

namespace App\Console\Commands;

use App\Services\ScheduledReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 發送到時間的預約提醒
 *
 * 每分鐘跑一次。提醒是使用者自己指定的時間，間隔再長就會偏掉 ——
 * 說好 14:00，14:05 才跳出來的提醒已經失去意義。
 *
 * ⚠ **沒有下界**：排程掛掉幾小時之後補跑，那幾筆仍然要發出去。
 * 「晚了半小時的提醒」比「沒有提醒」有用得多。
 */
class SendScheduledReminderCommand extends Command
{
    protected $signature = 'reminder:send';

    protected $description = '發送到時間的預約提醒（內部群組 tag 機器人排的）';

    private $service;

    public function __construct(ScheduledReminderService $service)
    {
        parent::__construct();

        $this->service = $service;
    }

    /**
     * @return int
     */
    public function handle()
    {
        $sent = $this->service->sendDue();

        if ($sent < 1) {
            return 0;
        }

        $this->info("已發送 {$sent} 筆預約提醒");
        Log::info('預約提醒發送完成', ['sent' => $sent]);

        return 0;
    }
}
