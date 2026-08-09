<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * 產生 VAPID 金鑰（Web Push 推播用）
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'vapid:generate';

    protected $description = '產生 VAPID 金鑰（Web Push 推播用）';

    /**
     * @return void
     */
    public function handle()
    {
        $keys = VAPID::createVapidKeys();

        $this->info('VAPID 金鑰產生成功，請貼到 .env：');
        $this->line('');
        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");
        $this->line('VAPID_SUBJECT=mailto:admin@example.com');
    }
}
