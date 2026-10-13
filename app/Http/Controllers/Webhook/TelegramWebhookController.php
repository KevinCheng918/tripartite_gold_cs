<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\AutoReplySupportService;
use App\Services\CustomerFollowupService;
use App\Services\DailyRateService;
use App\Services\ScheduledBroadcastCommandService;
use App\Services\ScheduledReminderService;
use App\Services\TelegramBotService;
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
    private $followupService;
    private $reminderService;
    private $broadcastCommandService;
    private $botService;

    public function __construct(
        TelegramChatService $chatService,
        AutoReplySupportService $supportService,
        DailyRateService $dailyRateService,
        CustomerFollowupService $followupService,
        ScheduledReminderService $reminderService,
        ScheduledBroadcastCommandService $broadcastCommandService,
        TelegramBotService $botService
    ) {
        $this->chatService = $chatService;
        $this->supportService = $supportService;
        $this->dailyRateService = $dailyRateService;
        // 待追蹤事項的三顆按鈕（callback_data 前綴 fu:）
        $this->followupService = $followupService;
        // 預約提醒：tag 機器人建立、取消按鈕（前綴 rm:）
        $this->reminderService = $reminderService;
        /*
         * 指令預約公告的【確認預約】【取消】（前綴 bc:）。
         *
         * ⚠ 訊息的分流在 `ScheduledReminderService` 裡做（兩者共用「tag 機器人」
         * 這個入口），這裡只接按鈕 —— callback 沒有共用的入口可分。
         */
        $this->broadcastCommandService = $broadcastCommandService;
        // 回應 callback_query，不然按鈕會一直轉圈
        $this->botService = $botService;
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

            // 按鈕事件（內部支援群組的處理選單、待追蹤事項的決定）
            if (isset($payload['callback_query'])) {
                $this->handleCallback($payload);

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

                /*
                 * tag 機器人 ＝ 要排預約提醒（或查清單）。
                 *
                 * ⚠ 擺在求助單**之前**：求助單靠「引用回覆」運作，而預約是
                 * 直接 tag 機器人 —— 兩者的觸發條件不重疊，但先問這一支
                 * 才不會讓「@bot 明天提醒我…」掉進求助單的作答判斷。
                 */
                if ($this->reminderService->handleMessage($payload)) {
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
     * 按鈕事件要交給哪一支 service
     *
     * 兩套按鈕都在同一個內部群組裡，只能靠 `callback_data` 的前綴分辨：
     * 待追蹤事項是 `fu:`，其餘（求助單的處理選單、候選題目、類別）
     * 由 `AutoReplySupportService` 自己再分一層。
     *
     * ⚠ 這裡**只做分派**，不碰任何判斷邏輯 —— Controller 不該有商業邏輯。
     *
     * @param array $payload Telegram Update
     * @return void
     */
    private function handleCallback($payload)
    {
        $data = (string) Arr::get($payload, 'callback_query.data');
        $prefix = (string) Arr::get(explode(':', $data), 0);
        $callback = (array) Arr::get($payload, 'callback_query');

        if ($prefix === (string) config('constants.FOLLOWUP.CALLBACK.DECIDE')) {
            /*
             * 要先回應 callback_query，否則按鈕會一直轉圈（Telegram 的規矩）。
             * 求助單那邊是在 `handleCallback()` 裡做，這兩條路自己做一次。
             */
            $this->botService->answerCallbackQuery(Arr::get($callback, 'id'));

            $this->followupService->handleCallback(
                array_slice(explode(':', $data), 1),
                Arr::get($callback, 'message.message_id'),
                (array) Arr::get($callback, 'from', [])
            );

            return;
        }

        // 指令預約公告的【確認預約】【取消】
        if ($prefix === (string) config('constants.BROADCAST_COMMAND.CALLBACK')) {
            $this->botService->answerCallbackQuery(Arr::get($callback, 'id'));

            $this->broadcastCommandService->handleCallback(
                array_slice(explode(':', $data), 1),
                Arr::get($callback, 'message.message_id')
            );

            return;
        }

        // 預約提醒的取消鈕
        if ($prefix === (string) config('constants.REMINDER.CALLBACK')) {
            $this->botService->answerCallbackQuery(Arr::get($callback, 'id'));

            $this->reminderService->handleCallback(
                array_slice(explode(':', $data), 1),
                Arr::get($callback, 'message.message_id')
            );

            return;
        }

        $this->supportService->handleCallback($payload);
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
