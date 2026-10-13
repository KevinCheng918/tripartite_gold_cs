<?php

namespace App\Services;

use Illuminate\Support\Arr;

/**
 * 內部群組裡 tag 機器人的指令分派
 *
 * ```
 * tag 機器人
 *   ├─ 含「公告」  → 預約發送公告（要權限、要先確認）
 *   ├─ 含「餘點」  → 同步站台餘點（要先確認）
 *   └─ 其餘        → 預約提醒（也是看不懂時的歸宿）
 * ```
 *
 * ⚠ **這一支抽出來的理由**（2026-10-13）：原本「有沒有 tag 到我」「話題准不准」
 * 「這句話是哪一種指令」全都寫在 `ScheduledReminderService` 裡。
 * 加到第三個指令時，那支就變成「預約提醒 ＋ 路由器」——
 * 下一個人要找分流邏輯會先翻 webhook，再翻到提醒的 service 裡才看到。
 *
 * ⚠ **每一支 handler 的簽章都一樣**（`handle($body, $from, $threadId): bool`），
 * 回 `false` 代表「這不是我的事，換下一個」。加第四種指令時只要寫一支 handler
 * 再掛進 `handlers()`，不必動任何既有的 service。
 *
 * ⚠ **關鍵字只是粗篩，不是判斷。** 真正的判斷在各自的解析器裡 ——
 * 「明天提醒我發公告」含「公告」卻是提醒，所以公告那支會回 false 讓它往下走。
 * 粗篩的用途單純是「不要每則訊息都跑三支模型」。
 */
class TelegramCommandRouter
{
    /** @var string 話題分流用的類型（跟預約提醒共用同一個設定，需求方 2026-10-13） */
    const NOTICE_TYPE = 'scheduled_reminder';

    private $supportGroup;
    private $botService;
    private $broadcastCommand;
    private $creditCommand;
    private $reminder;

    public function __construct(
        SupportGroupService $supportGroup,
        TelegramBotService $botService,
        ScheduledBroadcastCommandService $broadcastCommand,
        StationCreditCommandService $creditCommand,
        ScheduledReminderService $reminder
    ) {
        $this->supportGroup = $supportGroup;
        // 判斷「這則有沒有 tag 我」要知道 Bot 自己的 username
        $this->botService = $botService;
        $this->broadcastCommand = $broadcastCommand;
        $this->creditCommand = $creditCommand;
        // 擺最後：它同時是「看不懂」時的歸宿
        $this->reminder = $reminder;
    }

    /**
     * 支援群組收到訊息
     *
     * @param array $payload Telegram Update
     * @return bool 有處理就回 true（呼叫端要跳過後續的求助單判斷）
     */
    public function handleMessage(array $payload)
    {
        $message = (array) Arr::get($payload, 'message');
        $text = trim((string) Arr::get($message, 'text', ''));

        // 短路：沒有文字的訊息（貼圖、圖片）連 username 都不必查
        if (blank($text)) {
            return false;
        }

        $body = $this->stripMention($text);

        // 沒 tag 到機器人 —— 群組裡的一般討論，不是我們的事
        if (blank($body)) {
            return false;
        }

        $threadId = Arr::get($message, 'message_thread_id');

        /*
         * ⚠ **擋在解析之前**：不准用的話題不該燒模型額度。
         *
         * 也不能靜默 —— 使用者 tag 了沒反應只會以為壞了，然後再試幾次。
         */
        if (!$this->isAllowedThread($threadId)) {
            $this->supportGroup->sendToThread(
                (string) config('constants.REMINDER.WRONG_TOPIC_TEXT'),
                $threadId
            );

            return true;
        }

        $from = (array) Arr::get($message, 'from', []);

        foreach ($this->handlers() as $handler) {
            if ($handler($body, $from, $threadId, $message)) {
                return true;
            }
        }

        return true;
    }

    /**
     * 依序問過去，誰認領就誰處理
     *
     * ⚠ 順序有意義：**提醒擺最後**，因為它同時是「看不懂」時的歸宿
     * （會回一句範例）。把它放前面的話，後面兩種永遠輪不到。
     *
     * @return array<callable>
     */
    private function handlers()
    {
        $broadcast = $this->broadcastCommand;
        $credit = $this->creditCommand;
        $reminder = $this->reminder;

        return [
            function ($body, $from, $threadId) use ($broadcast) {
                return $broadcast->looksLikeCommand($body)
                    && $broadcast->handle($body, $from, $threadId);
            },
            function ($body, $from, $threadId) use ($credit) {
                return $credit->looksLikeCommand($body)
                    && $credit->handle($body, $from, $threadId);
            },
            function ($body, $from, $threadId, $message) use ($reminder) {
                return $reminder->handle($body, $threadId, $message);
            },
        ];
    }

    /**
     * 去掉 `@機器人`，回剩下的那句話
     *
     * ⚠ **查不到自己的 username 時一律回空字串**（＝當成沒 tag 到）。
     * 寧可整組指令暫時不動，也不要把群組裡每一則訊息都丟給模型解析 ——
     * 那會燒額度，而且會對閒聊亂回話。
     *
     * @param string $text
     * @return string 沒 tag 到機器人時回空字串
     */
    private function stripMention($text)
    {
        $username = $this->botService->getMyUsername();

        if (blank($username)) {
            return '';
        }

        $mention = '@' . $username;

        if (mb_stripos($text, $mention) === false) {
            return '';
        }

        // tag 可能在句首也可能在句中（「幫我 @bot 排一個提醒」），一律拿掉
        return trim(str_ireplace($mention, ' ', $text));
    }

    /**
     * 這個話題准不准用機器人指令
     *
     * 「通知設定 → 話題分流」勾了「預約提醒」的話題才可以。
     * **三種指令共用同一個設定**（需求方 2026-10-13）—— 一個設定管全部，
     * 比每種指令各一項設定好記。
     *
     * ⚠ **一個都沒勾 ＝ 不限制**，不是「全部停用」。設定還沒填的那段時間
     * 不該整個停擺 —— 這是話題分流一貫的規則。
     *
     * ⚠ 勾了之後群組主區就不能用了（主區沒有 thread id）。
     *
     * @param int|null $threadId
     * @return bool
     */
    private function isAllowedThread($threadId)
    {
        $allowed = $this->supportGroup->topicIdsFor(self::NOTICE_TYPE);

        if (blank($allowed)) {
            return true;
        }

        return filled($threadId) && in_array((int) $threadId, $allowed, true);
    }
}
