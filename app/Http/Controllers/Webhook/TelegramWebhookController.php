<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\TelegramChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Webhook 控制器
 *
 * 公開端點，無 auth。Telegram 主動推送訊息到此 URL。
 * 透過 X-Telegram-Bot-Api-Secret-Token header 驗證來源。
 */
class TelegramWebhookController extends Controller
{
    private $chatService;

    public function __construct(TelegramChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * 接收 Telegram Webhook
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request)
    {
        // 驗證 secret token
        $secret = config('telegram.webhook_secret');
        if (filled($secret)) {
            $headerToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($headerToken !== $secret) {
                Log::warning('Telegram Webhook 驗證失敗', ['ip' => $request->ip()]);

                return response()->json(['ok' => false], 403);
            }
        }

        try {
            $payload = $request->all();

            // debug: 記錄 webhook 收到的 payload keys
            Log::info('Webhook payload', [
                'keys'      => array_keys($payload),
                'has_msg'   => isset($payload['message']),
                'msg_keys'  => isset($payload['message']) ? array_keys($payload['message']) : [],
                'has_reply' => isset($payload['message']['reply_to_message']),
            ]);

            if (isset($payload['message_reaction'])) {
                $this->chatService->handleReactionUpdate($payload);
            } else {
                $this->chatService->handleIncomingMessage($payload);
            }
        } catch (\Exception $e) {
            Log::error('Telegram Webhook 處理失敗', ['error' => $e->getMessage()]);
        }

        // Telegram 要求永遠回 200
        return response()->json(['ok' => true]);
    }
}
