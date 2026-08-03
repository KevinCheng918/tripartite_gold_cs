<?php

namespace App\Console\Commands;

use App\Repositories\TelegramRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Telegram 訊息 TTL 清理
 *
 * 刪除超過指定天數的訊息紀錄。
 */
class TelegramPurgeCommand extends Command
{
    protected $signature = 'telegram:purge {--days= : 保留天數，預設讀取 config}';
    protected $description = '清理超過 TTL 天數的 Telegram 訊息';

    /**
     * @return int
     */
    public function handle()
    {
        $days = $this->option('days') ?: config('constants.TELEGRAM.TTL_DAYS', 7);

        $count = app(TelegramRepository::class)->deleteOlderThan((int) $days);

        $this->info("已清理 {$count} 筆超過 {$days} 天的訊息。");
        Log::info('Telegram 訊息清理完成', ['days' => $days, 'count' => $count]);

        return 0;
    }
}
