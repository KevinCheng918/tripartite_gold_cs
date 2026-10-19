<?php

namespace App\Services;

use Illuminate\Support\Arr;

/**
 * 內部群組裡 tag 機器人的指令分派
 *
 * ```
 * tag 機器人 → 依序問每一支，誰認領就誰處理
 *   ├─ 預約發送公告（要權限、要先確認、**會發給客戶**）
 *   ├─ 發餘點告警（要權限、要先確認、**會發給客戶**）
 *   ├─ 同步站台餘點（要權限、要先確認）
 *   ├─ 查站台虛擬機（要權限，純讀不必確認）
 *   ├─ 查某人的卡片進度（要權限，純讀不必確認）
 *   ├─ 查今日匯率補點訊息（要權限，純讀不必確認）
 *   ├─ 做一份 spider_setting（要權限，只產文字不寫任何系統）
 *   └─ 預約提醒（擺最後，也是看不懂時的歸宿）
 * ```
 *
 * ⚠ **這一支抽出來的理由**（2026-10-13）：原本「有沒有 tag 到我」「話題准不准」
 * 「這句話是哪一種指令」全都寫在 `ScheduledReminderService` 裡。
 * 加到第三個指令時，那支就變成「預約提醒 ＋ 路由器」——
 * 下一個人要找分流邏輯會先翻 webhook，再翻到提醒的 service 裡才看到。
 *
 * ⚠ **每一支 handler 的簽章都一樣**（`handle($body, $from, $threadId): bool`），
 * 回 `false` 代表「這不是我的事，換下一個」。再加一種指令時只要寫一支 handler
 * 再掛進 `handlers()`，不必動任何既有的 service。
 *
 * ⚠ **判斷一律由解析器做，沒有關鍵字捷徑。** 「明天提醒我發公告」含「公告」
 * 卻是提醒 —— 這種只有讀懂整句才分得出來。理由見 `handlers()`。
 */
class TelegramCommandRouter
{
    /** @var string 話題分流用的類型（跟預約提醒共用同一個設定，需求方 2026-10-13） */
    const NOTICE_TYPE = 'scheduled_reminder';

    private $supportGroup;
    private $botService;
    private $broadcastCommand;
    private $alertCommand;
    private $creditCommand;
    private $vmCommand;
    private $taskCommand;
    private $rateCommand;
    private $spiderCommand;
    private $reminder;

    public function __construct(
        SupportGroupService $supportGroup,
        TelegramBotService $botService,
        ScheduledBroadcastCommandService $broadcastCommand,
        CreditAlertCommandService $alertCommand,
        StationCreditCommandService $creditCommand,
        VmQueryCommandService $vmCommand,
        TaskQueryCommandService $taskCommand,
        RateQueryCommandService $rateCommand,
        SpiderSettingCommandService $spiderCommand,
        ScheduledReminderService $reminder
    ) {
        $this->supportGroup = $supportGroup;
        // 判斷「這則有沒有 tag 我」要知道 Bot 自己的 username
        $this->botService = $botService;
        $this->broadcastCommand = $broadcastCommand;
        // 擺在查餘點之前：「發告警」與「查餘點」都提到點數，先問會發的那支
        $this->alertCommand = $alertCommand;
        $this->creditCommand = $creditCommand;
        $this->vmCommand = $vmCommand;
        $this->taskCommand = $taskCommand;
        $this->rateCommand = $rateCommand;
        $this->spiderCommand = $spiderCommand;
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
     * （會回一句範例）。把它放前面的話，後面幾種永遠輪不到。
     *
     * ⚠ **沒有關鍵字粗篩**（2026-10-13 拿掉，需求方決定）。原本每支指令
     * 前面都先用關鍵字擋一道，省模型呼叫 —— 但上線當天就漏掉
     * 「確認 Rich 還剩多少點」（沒有「餘點」也沒有「點數」），餘點指令
     * 根本沒被問到，訊息掉到提醒那一棒被排成了明天的提醒。
     *
     * 關鍵字清單永遠追不完講法，而每漏一次都是默默出錯、要等人回報。
     * 粗篩本來就只是成本機制，拿它當正確性機制就會變成「功能做好了
     * 但叫不出來」。現在一律問過去，各解析器自己回 `false` 退場。
     *
     * 代價是最差跑四次模型。被 tag 的訊息本來就少（是人特地 tag 機器人），
     * 用這個換「講法換一種就失靈」是划算的。
     *
     * @return array<callable>
     */
    private function handlers()
    {
        $broadcast = $this->broadcastCommand;
        $alert = $this->alertCommand;
        $credit = $this->creditCommand;
        $vm = $this->vmCommand;
        $task = $this->taskCommand;
        $rate = $this->rateCommand;
        $spider = $this->spiderCommand;
        $reminder = $this->reminder;

        return [
            function ($body, $from, $threadId) use ($broadcast) {
                return $broadcast->handle($body, $from, $threadId);
            },
            /*
             * ⚠ 發告警擺在查餘點**之前**：兩者都提到「點數」，而這一支會
             * 把訊息發給客戶。萬一兩邊都想認領，先問會發的那支比較安全 ——
             * 查詢被誤判成發送，至少還有二次確認擋著；反過來則是使用者
             * 想發卻只拿到一個查詢結果，會重打好幾次。
             */
            function ($body, $from, $threadId) use ($alert) {
                return $alert->handle($body, $from, $threadId);
            },
            function ($body, $from, $threadId) use ($credit) {
                return $credit->handle($body, $from, $threadId);
            },
            function ($body, $from, $threadId) use ($vm) {
                return $vm->handle($body, $from, $threadId);
            },
            function ($body, $from, $threadId) use ($task) {
                return $task->handle($body, $from, $threadId);
            },
            function ($body, $from, $threadId) use ($rate) {
                return $rate->handle($body, $from, $threadId);
            },
            function ($body, $from, $threadId) use ($spider) {
                return $spider->handle($body, $from, $threadId);
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
