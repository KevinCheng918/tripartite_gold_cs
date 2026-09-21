<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\AutoReplySupportService;
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
    private $supportService;

    public function __construct(TelegramChatService $chatService, AutoReplySupportService $supportService)
    {
        $this->chatService = $chatService;
        $this->supportService = $supportService;
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

            // 按鈕事件（內部支援群組的處理選單）
            if (isset($payload['callback_query'])) {
                $this->supportService->handleCallback($payload);

                return response()->json(['ok' => true]);
            }

            // 內部支援群組的訊息不是客服對話，必須在這裡攔掉 ——
            // 讓它往下走的話，內部群組會被當成新客戶建進對話列表，
            // 自己人的討論也會被存成客戶訊息
            if ($this->isFromSupportChat($payload)) {
                $this->supportService->handleSupportMessage($payload);

                return response()->json(['ok' => true]);
            }

            if (isset($payload['message_reaction'])) {
                $this->chatService->handleReactionUpdate($payload);
            } elseif (isset($payload['edited_message'])) {
                // 編輯事件的 payload 是 edited_message 不是 message，
                // 沒有這個分支會被 handleIncomingMessage 當成空訊息直接丟掉
                $this->chatService->handleEditedMessage($payload);
            } else {
                $this->chatService->handleIncomingMessage($payload);
            }
        } catch (\Exception $e) {
            Log::error('Telegram Webhook 處理失敗', ['error' => $e->getMessage()]);
        }

        // Telegram 要求永遠回 200
        return response()->json(['ok' => true]);
    }

    /**
     * 這則 update 是不是來自內部支援群組
     *
     * @param array $payload
     * @return bool
     */
    private function isFromSupportChat($payload)
    {
        $chatId = $payload['message']['chat']['id']
            ?? $payload['edited_message']['chat']['id']
            ?? null;

        if (!filled($chatId)) {
            return false;
        }

        return $this->supportService->isSupportChat($chatId);
    }
}
