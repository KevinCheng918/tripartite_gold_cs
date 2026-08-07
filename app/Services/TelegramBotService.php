<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Bot API Service
 *
 * 封裝 Telegram Bot API 呼叫（sendMessage、setWebhook 等）。
 */
class TelegramBotService
{
    private $client;
    private $baseUrl;

    public function __construct()
    {
        $token = config('telegram.bot_token');
        $this->baseUrl = config('telegram.api_base') . $token;
        $this->client = new Client(['timeout' => 10]);
    }

    /**
     * 發送訊息到 Telegram 群組
     *
     * @param int    $chatId Telegram chat_id
     * @param string $text   訊息內容
     * @return array|null API 回傳
     */
    public function sendMessage($chatId, $text)
    {
        try {
            $response = $this->client->post("{$this->baseUrl}/sendMessage", [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram sendMessage 失敗', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 設定 Webhook URL
     *
     * @param string $url Webhook URL（需 HTTPS）
     * @return array|null
     */
    public function setWebhook($url)
    {
        try {
            $params = [
                'url' => $url,
            ];

            $secret = config('telegram.webhook_secret');
            if (filled($secret)) {
                $params['secret_token'] = $secret;
            }

            $response = $this->client->post("{$this->baseUrl}/setWebhook", [
                'json' => $params,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram setWebhook 失敗', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 發送圖片到 Telegram 群組
     *
     * @param int         $chatId
     * @param string      $photoUrl 圖片 URL 或本地路徑
     * @param string|null $caption  圖片說明文字
     * @return array|null
     */
    public function sendPhoto($chatId, $photoUrl, $caption = null)
    {
        try {
            $data = [
                'chat_id' => $chatId,
                'photo'   => $photoUrl,
            ];

            if (filled($caption)) {
                $data['caption'] = $caption;
                $data['parse_mode'] = 'HTML';
            }

            $response = $this->client->post("{$this->baseUrl}/sendPhoto", [
                'json' => $data,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram sendPhoto 失敗', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 取得 Telegram 檔案的下載 URL
     *
     * @param string $fileId Telegram file_id
     * @return string|null
     */
    public function getFileUrl($fileId)
    {
        try {
            $response = $this->client->post("{$this->baseUrl}/getFile", [
                'json' => ['file_id' => $fileId],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!($body['ok'] ?? false)) {
                return null;
            }

            $filePath = $body['result']['file_path'] ?? null;

            if (!filled($filePath)) {
                return null;
            }

            $token = config('telegram.bot_token');

            return "https://api.telegram.org/file/bot{$token}/{$filePath}";
        } catch (\Exception $e) {
            Log::error('Telegram getFile 失敗', ['file_id' => $fileId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 取得最近的 updates（需先關閉 webhook）
     *
     * @param int $limit
     * @return array|null
     */
    public function getUpdates($limit = 100)
    {
        try {
            $response = $this->client->post("{$this->baseUrl}/getUpdates", [
                'json' => ['limit' => $limit],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram getUpdates 失敗', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 取得目前 Webhook 資訊
     *
     * @return array|null
     */
    public function getWebhookInfo()
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/getWebhookInfo");

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram getWebhookInfo 失敗', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 刪除 Webhook
     *
     * @return array|null
     */
    public function deleteWebhook()
    {
        try {
            $response = $this->client->post("{$this->baseUrl}/deleteWebhook");

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram deleteWebhook 失敗', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
