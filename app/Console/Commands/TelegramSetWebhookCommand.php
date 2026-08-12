<?php

namespace App\Console\Commands;

use App\Models\System;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * 設定 / 刪除 / 查看 Telegram Bot Webhook
 *
 * 支援多系統 Bot：會自動為所有有 bot_token 的系統設定 webhook，
 * 加上 .env 的預設 bot（如果有設定）。
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

    protected $description = '設定／刪除／查看 Telegram Bot Webhook（支援多系統）';

    public function handle()
    {
        $tokens = $this->collectTokens();

        if (empty($tokens)) {
            $this->error('沒有可用的 Bot Token（.env 未設定，系統表也沒有）');
            return;
        }

        $this->info("找到 " . count($tokens) . " 個 Bot Token");
        foreach ($tokens as $label => $token) {
            $this->line("  - {$label}: " . substr($token, 0, 10) . '...');
        }
        $this->line('');

        $client = new Client(['timeout' => 10, 'http_errors' => false]);

        // --info
        if ($this->option('info')) {
            foreach ($tokens as $label => $token) {
                $this->warn("【{$label}】");
                $this->showWebhookInfo($client, $token);
                $this->line('');
            }
            return;
        }

        // --delete
        if ($this->option('delete')) {
            foreach ($tokens as $label => $token) {
                $response = $client->get("https://api.telegram.org/bot{$token}/deleteWebhook");
                $body = json_decode($response->getBody()->getContents(), true);

                if (Arr::get($body, 'ok', false)) {
                    $this->info("【{$label}】Webhook 已刪除");
                } else {
                    $this->error("【{$label}】刪除失敗：" . Arr::get($body, 'description', '未知錯誤'));
                }
            }
            return;
        }

        // 提醒
        $this->warn('⚠ 設定前請確認：');
        $this->line('  1. 所有 Bot 已被加入對應的 Telegram 群組');
        $this->line('  2. 所有 Bot 的 Group Privacy 已關閉');
        $this->line('  3. TELEGRAM_WEBHOOK_SECRET 已設定');
        $this->line('');

        if (!$this->confirm('確認以上都已完成？')) {
            $this->info('已取消');
            return;
        }

        $baseUrl = $this->option('url') ?: config('app.url');

        if (blank($baseUrl) || $baseUrl === 'http://localhost') {
            $this->error('請指定 --url 參數，或在 .env 設定 APP_URL');
            return;
        }

        $webhookUrl = rtrim($baseUrl, '/') . '/api/telegram/webhook';
        $secret = config('telegram.webhook_secret');

        foreach ($tokens as $label => $token) {
            $params = ['url' => $webhookUrl];
            if (filled($secret)) {
                $params['secret_token'] = $secret;
            }

            $response = $client->get("https://api.telegram.org/bot{$token}/setWebhook?" . http_build_query($params));
            $body = json_decode($response->getBody()->getContents(), true);

            if (Arr::get($body, 'ok', false)) {
                $this->info("【{$label}】Webhook 設定成功：{$webhookUrl}");
            } else {
                $this->error("【{$label}】設定失敗：" . Arr::get($body, 'description', '未知錯誤'));
            }
        }

        $this->line('');
        foreach ($tokens as $label => $token) {
            $this->warn("【{$label}】");
            $this->showWebhookInfo($client, $token);
            $this->line('');
        }
    }

    /**
     * 收集所有可用的 Bot Token（預設 + 各系統）
     *
     * @return array label => token
     */
    private function collectTokens()
    {
        $tokens = [];

        // 預設 token
        $defaultToken = config('telegram.bot_token');
        if (filled($defaultToken)) {
            $tokens['預設 (.env)'] = $defaultToken;
        }

        // 各系統的 token
        $systems = System::query()
            ->select(['id', 'name', 'bot_token'])
            ->whereNotNull('bot_token')
            ->where('bot_token', '!=', '')
            ->get();

        foreach ($systems as $system) {
            // 避免重複（跟預設一樣的 token 跳過）
            if ($system->bot_token === $defaultToken) {
                continue;
            }
            $tokens["系統：{$system->name}"] = $system->bot_token;
        }

        return $tokens;
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
        ]);
    }
}
