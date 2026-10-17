<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\AutoReplySupportService;
use App\Services\CustomerFollowupService;
use App\Services\DailyRateService;
use App\Services\CreditAlertCommandService;
use App\Services\CreditAlertResendService;
use App\Services\ScheduledBroadcastCommandService;
use App\Services\ScheduledReminderService;
use App\Services\StationCreditCommandService;
use App\Services\TelegramBotService;
use App\Services\TelegramChatService;
use App\Services\TelegramCommandRouter;
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
    private $commandRouter;
    private $reminderService;
    private $broadcastCommandService;
    private $creditCommandService;
    private $alertCommandService;
    private $alertResendService;
    private $botService;

    public function __construct(
        TelegramChatService $chatService,
        AutoReplySupportService $supportService,
        DailyRateService $dailyRateService,
        CustomerFollowupService $followupService,
        TelegramCommandRouter $commandRouter,
        ScheduledReminderService $reminderService,
        ScheduledBroadcastCommandService $broadcastCommandService,
        StationCreditCommandService $creditCommandService,
        CreditAlertCommandService $alertCommandService,
        CreditAlertResendService $alertResendService,
        TelegramBotService $botService
    ) {
        $this->chatService = $chatService;
        $this->supportService = $supportService;
        $this->dailyRateService = $dailyRateService;
        // 待追蹤事項的三顆按鈕（callback_data 前綴 fu:）
        $this->followupService = $followupService;
        // tag 機器人的那些指令（預約提醒／預約公告／查餘點）走同一個入口分流
        $this->commandRouter = $commandRouter;
        /*
         * ⚠ 下面三支**只用來接按鈕**（前綴 rm: / bc: / sc:）。
         *
         * 訊息有共用入口（tag 機器人）可以交給分派器，callback 沒有 ——
         * 每顆按鈕的 `callback_data` 本來就各帶各的前綴，在這裡攤平分派
         * 比再包一層薄薄的 router 清楚。
         */
        $this->reminderService = $reminderService;
        $this->broadcastCommandService = $broadcastCommandService;
        $this->creditCommandService = $creditCommandService;
        $this->alertCommandService = $alertCommandService;
        // 匯率被改掉時問「要不要用新匯率重發告警」的那兩顆按鈕（前綴 cr:）
        $this->alertResendService = $alertResendService;
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
                 * tag 機器人 ＝ 下指令（預約提醒／預約公告／查站台餘點）。
                 *
                 * ⚠ 擺在求助單**之前**：求助單靠「引用回覆」運作，而指令是
                 * 直接 tag 機器人 —— 兩者的觸發條件不重疊，但先問這一支
                 * 才不會讓「@bot 明天提醒我…」掉進求助單的作答判斷。
                 */
                if ($this->commandRouter->handleMessage($payload)) {
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
     * 所有按鈕都在同一個內部群組裡，只能靠 `callback_data` 的前綴分辨：
     * 待追蹤事項 `fu:`、預約公告 `bc:`、預約提醒 `rm:`、查餘點 `sc:`，
     * 其餘（求助單的處理選單、候選題目、類別）由 `AutoReplySupportService`
     * 自己再分一層。
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

        /*
         * 查站台餘點的【查詢】【取消】。
         *
         * ⚠ 這一支的 `handleCallback()` 會去打主系統 API（要幾秒），所以
         * `answerCallbackQuery()` 一定要在它**之前**：先把按鈕的轉圈停掉，
         * 再慢慢同步。順序顛倒的話使用者會看著轉圈以為沒反應又按一次。
         */
        if ($prefix === (string) config('constants.STATION_CREDIT_COMMAND.CALLBACK')) {
            $this->botService->answerCallbackQuery(Arr::get($callback, 'id'));

            $this->creditCommandService->handleCallback(
                array_slice(explode(':', $data), 1),
                Arr::get($callback, 'message.message_id'),
                // 按按鈕的人要再驗一次權限 —— 他不一定是下指令的那個人
                (array) Arr::get($callback, 'from', []),
                Arr::get($callback, 'message.message_thread_id')
            );

            return;
        }

        /*
         * 發餘點告警給客戶的【確認發送】【取消】。
         *
         * ⚠ 這一支按下去會**把訊息發給客戶**，所以 service 裡會再驗一次
         * 權限（按的人不一定是下指令的人），草稿也會過期。
         */
        if ($prefix === (string) config('constants.CREDIT_ALERT_COMMAND.CALLBACK')) {
            $this->botService->answerCallbackQuery(Arr::get($callback, 'id'));

            $this->alertCommandService->handleCallback(
                array_slice(explode(':', $data), 1),
                Arr::get($callback, 'message.message_id'),
                (array) Arr::get($callback, 'from', [])
            );

            return;
        }

        /*
         * 匯率改掉之後的【重新發送】【不用】。
         *
         * ⚠ 按下去會把餘點告警重發給**全部低於門檻的站台客戶**，
         * 所以 service 裡會再驗一次權限（按的人不一定是改匯率的人）。
         */
        if ($prefix === (string) config('constants.CREDIT_ALERT_RESEND.CALLBACK')) {
            $this->botService->answerCallbackQuery(Arr::get($callback, 'id'));

            $this->alertResendService->handleCallback(
                array_slice(explode(':', $data), 1),
                Arr::get($callback, 'message.message_id'),
                (array) Arr::get($callback, 'from', [])
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
