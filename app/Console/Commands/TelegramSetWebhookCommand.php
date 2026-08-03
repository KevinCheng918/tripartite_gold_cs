<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 設定 / 刪除 / 查看 Telegram Bot Webhook
 *
 * @example php artisan telegram:set-webhook
 * @example php artisan telegram:set-webhook --url=https://abc123.ngrok-free.app
 * @example php artisan telegram:set-webhook --delete
 * @example php artisan telegram:set-webhook --info
 */
class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook
        {--url= : 自訂 Webhook URL（例如 ngrok URL），不指定則用 APP_URL}
        {--delete : 刪除 webhook}
        {--info : 僅查看目前 webhook 資訊}';

    protected $description = '設定／刪除／查看 Telegram Bot Webhook';

    public function handle()
    {
        $token = config('telegram.bot_token');

        if (blank($token)) {
            $this->error('TELEGRAM_BOT_TOKEN 未設定，請先在 .env 填入 Bot Token');
            return;
        }

        $client = new Client(['timeout' => 10, 'http_errors' => false]);

        // --info：僅查看
        if ($this->option('info')) {
            $this->showWebhookInfo($client, $token);
            return;
        }

        // --delete：刪除
        if ($this->option('delete')) {
            $response = $client->get("https://api.telegram.org/bot{$token}/deleteWebhook");
            $body = json_decode($response->getBody()->getContents(), true);

            if (Arr::get($body, 'ok', false)) {
                $this->info('Webhook 已刪除');
            } else {
                $this->error('刪除失敗：' . Arr::get($body, 'description', '未知錯誤'));
            }

            return;
        }

        // 提醒檢查事項
        $this->line('');
        $this->warn('⚠ 設定前請確認：');
        $this->line('  1. Bot 已被加入 Telegram 群組');
        $this->line('  2. Bot 的 Group Privacy 已關閉（@BotFather → Bot Settings → Group Privacy → Turn off）');
        $this->line('     否則 Bot 只能收到 / 開頭的指令，收不到一般訊息');
        $this->line('  3. TELEGRAM_WEBHOOK_SECRET 已設定（建議設定，防止偽造請求）');
        $this->line('');

        if (!$this->confirm('確認以上都已完成？')) {
            $this->info('已取消');
            return;
        }

        // 設定 Webhook
        $baseUrl = $this->option('url') ?: config('app.url');

        if (blank($baseUrl) || $baseUrl === 'http://localhost') {
            $this->error('請指定 --url 參數，或在 .env 設定 APP_URL');
            $this->line('');
            $this->line('  範例（ngrok）：');
            $this->line('  php artisan telegram:set-webhook --url=https://abc123.ngrok-free.app');
            return;
        }

        $webhookUrl = rtrim($baseUrl, '/') . '/api/telegram/webhook';

        $params = ['url' => $webhookUrl];
        $secret = config('telegram.webhook_secret');
        if (filled($secret)) {
            $params['secret_token'] = $secret;
        }

        $response = $client->get("https://api.telegram.org/bot{$token}/setWebhook?" . http_build_query($params));
        $body = json_decode($response->getBody()->getContents(), true);

        if (Arr::get($body, 'ok', false)) {
            $this->info("Webhook 設定成功：{$webhookUrl}");
        } else {
            $this->error('設定失敗：' . Arr::get($body, 'description', '未知錯誤'));
            return;
        }

        // 顯示目前 webhook 資訊
        $this->showWebhookInfo($client, $token);
    }

    /**
     * 顯示目前 Webhook 資訊
     *
     * @param Client $client
     * @param string $token
     */
    private function showWebhookInfo($client, $token)
    {
        $response = $client->get("https://api.telegram.org/bot{$token}/getWebhookInfo");
        $info = json_decode($response->getBody()->getContents(), true);

        $this->table(['欄位', '值'], [
            ['URL',             Arr::get($info, 'result.url', '-')],
            ['Pending Updates', Arr::get($info, 'result.pending_update_count', 0)],
            ['Last Error',      Arr::get($info, 'result.last_error_message', '無')],
            ['Last Error Date', Arr::get($info, 'result.last_error_date')
                ? date('Y-m-d H:i:s', Arr::get($info, 'result.last_error_date'))
                : '-'],
            ['Has Secret',      Arr::get($info, 'result.has_custom_certificate') ? '是' : '否'],
        ]);
    }
}
