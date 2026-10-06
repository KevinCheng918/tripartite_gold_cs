<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\AutoReplySupportService;
use App\Services\DailyRateService;
use App\Services\TelegramChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
    private $dailyRateService;

    public function __construct(
        TelegramChatService $chatService,
        AutoReplySupportService $supportService,
        DailyRateService $dailyRateService
    ) {
        $this->chatService = $chatService;
        $this->supportService = $supportService;
        $this->dailyRateService = $dailyRateService;
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

            /*
             * debug：記錄 webhook 收到了什麼。
             *
             * ⚠ **`chat_id` 與 `thread_id` 一定要記**。踩過一次：群組開了話題功能
             * 之後 Telegram 把它升級成 supergroup、**chat_id 換了一個**，設定裡的
             * 舊 id 於是對不上 —— 內部群組的訊息全被當成客戶訊息處理，而當時的
             * log 只記 key 名稱，完全看不出 chat_id 已經變了。
             *
             * 新的 chat_id 與話題 id 都在這一行，設定頁要填的就是它們。
             */
            Log::info('Webhook payload', [
                'keys'      => array_keys($payload),
                'chat_id'   => Arr::get($payload, 'message.chat.id', Arr::get($payload, 'edited_message.chat.id')),
                'chat_type' => Arr::get($payload, 'message.chat.type'),
                'thread_id' => Arr::get($payload, 'message.message_thread_id'),
                'text'      => Arr::get($payload, 'message.text'),
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
                /*
                 * 匯率報價與求助單都靠「引用回覆」運作，所以要先問匯率那邊
                 * 認不認得被引用的那則訊息。認得就由它處理完直接結束，
                 * 不認得才往求助單走。
                 */
                if ($this->dailyRateService->handleSupportReply($payload)) {
                    return response()->json(['ok' => true]);
                }

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
