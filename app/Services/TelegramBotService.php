<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Telegram Bot API Service
 *
 * 封裝 Telegram Bot API 呼叫（sendMessage、setWebhook 等）。
 */
class TelegramBotService
{
    private $client;
    private $apiBase;
    private $defaultToken;

    public function __construct()
    {
        $this->defaultToken = config('telegram.bot_token');
        $this->apiBase = config('telegram.api_base');
        $this->client = new Client(['timeout' => 10]);
    }

    /**
     * 取得指定 token 的 API base URL
     *
     * @param string|null $token 不傳則使用預設 token
     * @return string
     */
    private function getBaseUrl($token = null)
    {
        return $this->apiBase . ($token ?: $this->defaultToken);
    }

    /**
     * 設定預設 token（供切換系統用）
     *
     * @param string $token
     * @return void
     */
    public function setToken($token)
    {
        $this->defaultToken = $token;
    }

    /**
     * 轉義 HTML 特殊字元，保留 Telegram 允許的標籤
     *
     * @param string $text
     * @return string
     */
    private function escapeHtml($text)
    {
        if (!filled($text)) {
            return $text ?: '';
        }

        // 沒有需要轉義的字元就直接回傳
        if (strpos($text, '<') === false && strpos($text, '&') === false) {
            return $text;
        }

        // 先全部轉義
        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        // 還原 Telegram 允許的 HTML 標籤
        $allowed = ['b', 'i', 'u', 's', 'code', 'pre', 'a'];
        foreach ($allowed as $tag) {
            $text = preg_replace('/&lt;(' . $tag . ')((?:\s[^&]*?)?)&gt;/i', '<$1$2>', $text);
            $text = str_replace("&lt;/{$tag}&gt;", "</{$tag}>", $text);
        }

        return $text;
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
            $response = $this->client->post("{$this->getBaseUrl()}/sendMessage", [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $this->escapeHtml($text),
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

            $response = $this->client->post("{$this->getBaseUrl()}/setWebhook", [
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
            $localPath = $this->resolveLocalPath($photoUrl);

            // 本站自己的圖片直接 multipart 上傳，不要讓 Telegram 反過來抓我們的網址
            // （APP_URL 為 localhost 或內網時，Telegram 會回 wrong HTTP URL specified）
            if (filled($localPath)) {
                // 超長／超寬的截圖 Telegram 不收（PHOTO_INVALID_DIMENSIONS），
                // 改以檔案傳送 —— 顯示成檔案總比整則訊息送不出去好，而且原圖不會被壓縮
                if (!$this->isValidPhotoDimensions($localPath)) {
                    Log::info('Telegram 圖片尺寸不符 photo 規格，改以檔案傳送', [
                        'chat_id' => $chatId,
                        'photo'   => $photoUrl,
                    ]);

                    return $this->sendDocument($chatId, $localPath, basename($localPath), $caption);
                }

                return $this->postMultipart('sendPhoto', $chatId, [
                    ['name' => 'photo', 'contents' => fopen($localPath, 'r'), 'filename' => basename($localPath)],
                ], $caption);
            }

            $data = [
                'chat_id' => $chatId,
                'photo'   => $photoUrl,
            ];

            if (filled($caption)) {
                $data['caption'] = $this->escapeHtml($caption);
                $data['parse_mode'] = 'HTML';
            }

            $response = $this->client->post("{$this->getBaseUrl()}/sendPhoto", [
                'json' => $data,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram sendPhoto 失敗', [
                'chat_id' => $chatId,
                'photo'   => $photoUrl,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 圖片是否符合 Telegram photo 規格
     *
     * Telegram 的限制：寬 + 高 ≤ 10000，且長短邊比例不得超過 20:1，
     * 超過會回 400 PHOTO_INVALID_DIMENSIONS。長截圖與超寬截圖很容易踩到。
     * 讀不到尺寸時回 true，讓它照原流程送、由 Telegram 自行判斷。
     *
     * @param string $path 本地絕對路徑
     * @return bool
     */
    private function isValidPhotoDimensions($path)
    {
        $size = @getimagesize($path);
        if ($size === false) {
            return true;
        }

        $width = (int) $size[0];
        $height = (int) $size[1];
        if ($width < 1 || $height < 1) {
            return true;
        }

        if ($width + $height > config('constants.TELEGRAM.PHOTO.MAX_DIMENSION_SUM')) {
            return false;
        }

        return max($width, $height) / min($width, $height) <= config('constants.TELEGRAM.PHOTO.MAX_RATIO');
    }

    /**
     * 把 public disk 產生的 URL 還原成本地絕對路徑
     *
     * 外部網址（非本站 storage）回傳 null，交由 Telegram 自行抓取。
     *
     * @param string $url
     * @return string|null
     */
    private function resolveLocalPath($url)
    {
        // 只比對路徑不比對 host：同一份檔案的網址可能帶不同 host
        // （APP_URL 是 localhost、實際請求卻是 tripartite_gold_cs 或正式網域），
        // 用完整網址前綴比對會誤判成外部網址而讓 Telegram 反向抓取，最後 400。
        $path = parse_url((string) $url, PHP_URL_PATH);
        if (!filled($path)) {
            return null;
        }

        $publicPath = parse_url((string) config('filesystems.disks.public.url'), PHP_URL_PATH);
        $publicPath = rtrim($publicPath ?: '/storage', '/');

        if (strpos($path, $publicPath . '/') !== 0) {
            return null;
        }

        $relative = ltrim(substr($path, strlen($publicPath)), '/');
        $file = Storage::disk('public')->path(rawurldecode($relative));

        return file_exists($file) ? $file : null;
    }

    /**
     * 以 multipart 送出（含 caption 處理），供本地檔案上傳共用
     *
     * @param string      $method    Telegram API method
     * @param int         $chatId
     * @param array       $parts     除了 chat_id / caption 以外的 multipart 欄位
     * @param string|null $caption
     * @return array|null
     */
    private function postMultipart($method, $chatId, array $parts, $caption = null)
    {
        $multipart = array_merge(
            [['name' => 'chat_id', 'contents' => (string) $chatId]],
            $parts
        );

        if (filled($caption)) {
            $multipart[] = ['name' => 'caption', 'contents' => $this->escapeHtml($caption)];
            $multipart[] = ['name' => 'parse_mode', 'contents' => 'HTML'];
        }

        $response = $this->client->post("{$this->getBaseUrl()}/{$method}", [
            'multipart' => $multipart,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * 發送多張圖片（相簿）到 Telegram 群組
     *
     * @param int         $chatId
     * @param array       $photoUrls 圖片 URL 陣列
     * @param string|null $caption   說明文字（僅顯示在第一張）
     * @return array|null
     */
    public function sendMediaGroup($chatId, $photoUrls, $caption = null)
    {
        try {
            $media = [];
            $files = [];

            foreach ($photoUrls as $idx => $url) {
                $localPath = $this->resolveLocalPath($url);

                if (filled($localPath)) {
                    // 本站圖片用 attach:// 對應到同一個 multipart 的檔案欄位
                    $field = "photo{$idx}";
                    $files[] = ['name' => $field, 'contents' => fopen($localPath, 'r'), 'filename' => basename($localPath)];
                    $item = ['type' => 'photo', 'media' => "attach://{$field}"];
                } else {
                    $item = ['type' => 'photo', 'media' => $url];
                }

                if ($idx === 0 && filled($caption)) {
                    $item['caption'] = $this->escapeHtml($caption);
                    $item['parse_mode'] = 'HTML';
                }
                $media[] = $item;
            }

            $multipart = array_merge(
                [
                    ['name' => 'chat_id', 'contents' => (string) $chatId],
                    ['name' => 'media', 'contents' => json_encode($media)],
                ],
                $files
            );

            $response = $this->client->post("{$this->getBaseUrl()}/sendMediaGroup", [
                'multipart' => $multipart,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram sendMediaGroup 失敗', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 發送文件到 Telegram 群組（本地檔案）
     *
     * @param int         $chatId   Telegram chat_id
     * @param string      $filePath 本地檔案絕對路徑
     * @param string|null $filename 顯示的檔名
     * @param string|null $caption  說明文字
     * @return array|null
     */
    public function sendDocument($chatId, $filePath, $filename = null, $caption = null)
    {
        try {
            $multipart = [
                ['name' => 'chat_id', 'contents' => (string) $chatId],
                ['name' => 'document', 'contents' => fopen($filePath, 'r'), 'filename' => $filename ?: basename($filePath)],
            ];

            if (filled($caption)) {
                $multipart[] = ['name' => 'caption', 'contents' => $this->escapeHtml($caption)];
                $multipart[] = ['name' => 'parse_mode', 'contents' => 'HTML'];
            }

            $response = $this->client->post("{$this->getBaseUrl()}/sendDocument", [
                'multipart' => $multipart,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram sendDocument 失敗', [
                'chat_id' => $chatId,
                'file'    => $filePath,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 對指定訊息設定表情回應
     *
     * @param int    $chatId    Telegram chat_id
     * @param int    $messageId Telegram message_id
     * @param string $emoji     表情符號（如 👍）
     * @return array|null
     */
    public function setMessageReaction($chatId, $messageId, $emoji)
    {
        try {
            $response = $this->client->post("{$this->getBaseUrl()}/setMessageReaction", [
                'json' => [
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                    'reaction'   => [
                        ['type' => 'emoji', 'emoji' => $emoji],
                    ],
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram setMessageReaction 失敗', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
                'emoji'      => $emoji,
                'error'      => $e->getMessage(),
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
            $response = $this->client->post("{$this->getBaseUrl()}/getFile", [
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

            return "https://api.telegram.org/file/bot{$this->defaultToken}/{$filePath}";
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
            $response = $this->client->post("{$this->getBaseUrl()}/getUpdates", [
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
            $response = $this->client->get("{$this->getBaseUrl()}/getWebhookInfo");

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
            $response = $this->client->post("{$this->getBaseUrl()}/deleteWebhook");

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Telegram deleteWebhook 失敗', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
