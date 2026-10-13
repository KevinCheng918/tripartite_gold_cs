<?php

namespace App\Services;

use App\Models\ScheduledReminder;
use App\Repositories\ScheduledReminderRepository;
use App\Services\AutoReply\ReminderParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 預約提醒
 *
 * 在內部群組 tag 機器人說「明天下午三點提醒大家對帳」，時間到了把內容
 * 原文發回**同一個話題**。
 *
 * ```
 * tag 機器人 → AI 解析 → 建立 ＋ 複述確認（附取消按鈕）
 *                     ↘ 要看清單 → 列出這個話題還沒發的
 *                     ↘ 看不懂   → 回一句範例
 *
 * 每分鐘 reminder:send → 撈 remind_at <= now 且還沒發的 → 發回原話題
 * ```
 *
 * ⚠ **內容原文送出**（含 `@帳號`）。Telegram 自己會把 @xxx 變成 mention，
 * 所以要 tag 誰完全由預約的人決定 —— 系統不解析那些帳號，也不必解析。
 *
 * ⚠ **一定要複述時間與內容**。AI 解析會出錯，而錯掉的提醒要到那個時間點
 * 才會被發現。複述 ＋ 取消按鈕讓人當場就能改。
 */
class ScheduledReminderService
{
    /** @var string 這類通知不走話題分流 —— 一定要回在使用者說話的那個話題 */
    const NOTICE_TYPE = 'scheduled_reminder';

    private $repository;
    private $parser;
    private $supportGroup;
    private $botService;

    public function __construct(
        ScheduledReminderRepository $repository,
        ReminderParser $parser,
        SupportGroupService $supportGroup,
        TelegramBotService $botService
    ) {
        $this->repository = $repository;
        // 自然語言 → 時間 ＋ 內容
        $this->parser = $parser;
        $this->supportGroup = $supportGroup;
        // 判斷「這則有沒有 tag 我」要知道 Bot 自己的 username
        $this->botService = $botService;
    }

    /**
     * 支援群組收到訊息：是不是在跟機器人預約提醒
     *
     * ⚠ **回 bool 讓呼叫端決定要不要繼續往下走**，比照
     * `DailyRateService::handleSupportReply()` —— 同一則訊息不該被兩套
     * 邏輯各處理一次。
     *
     * @param array $payload Telegram Update
     * @return bool 有處理就回 true
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

        $chatId = (int) Arr::get($message, 'chat.id');
        $threadId = Arr::get($message, 'message_thread_id');

        /*
         * ⚠ **擋在解析之前**：不准排的話題不該燒模型額度。
         *
         * 也不能靜默 —— 使用者 tag 了沒反應只會以為壞了，然後再試幾次。
         */
        if (!$this->isAllowedThread($threadId)) {
            $this->reply((string) config('constants.REMINDER.WRONG_TOPIC_TEXT'), $threadId);

            return true;
        }

        $parsed = $this->parser->parse($body);

        // 模型不可用（沒設 token、額度用完、CLI 逾時）
        if (blank($parsed)) {
            $this->reply((string) config('constants.REMINDER.UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        if (Arr::get($parsed, 'intent') === ReminderParser::INTENT_LIST) {
            $this->replyList($chatId, $threadId);

            return true;
        }

        if (Arr::get($parsed, 'intent') !== ReminderParser::INTENT_CREATE) {
            $this->reply((string) config('constants.REMINDER.UNKNOWN_TEXT'), $threadId);

            return true;
        }

        $this->create($message, $chatId, $threadId, $parsed);

        return true;
    }

    /**
     * 這個話題准不准排提醒
     *
     * 「通知設定 → 話題分流」裡勾了「預約提醒」的話題才可以。
     *
     * ⚠ **一個都沒勾 ＝ 不限制**，不是「全部停用」。設定還沒填的那段時間
     * 不該整個停擺 —— 這是話題分流一貫的規則（沒勾的通知發到主區，
     * 而不是不發）。上線時忘了勾不會讓功能靜悄悄失效。
     *
     * ⚠ 勾了之後**群組主區就不能排了**（主區沒有 thread id，比不上任何一個
     * 勾選的話題）。那是對的：既然限定了話題，主區就不在限定範圍內。
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

    /**
     * 去掉開頭的 `@機器人`，回剩下的那句話
     *
     * ⚠ **查不到自己的 username 時一律回空字串**（＝當成沒 tag 到）。
     * 寧可整個功能暫時不動，也不要把群組裡每一則訊息都丟給模型解析 ——
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
     * 建立一筆並複述
     *
     * @param array    $message
     * @param int      $chatId
     * @param int|null $threadId
     * @param array    $parsed
     * @return void
     */
    private function create(array $message, $chatId, $threadId, array $parsed)
    {
        $remindAt = Arr::get($parsed, 'remind_at');
        $content = (string) Arr::get($parsed, 'content');

        $reminder = $this->repository->create([
            'chat_id'                => $chatId,
            'message_thread_id'      => filled($threadId) ? (int) $threadId : null,
            'content'                => $content,
            'remind_at'              => $remindAt,
            'status'                 => (int) config('constants.REMINDER.STATUS.PENDING'),
            'created_by'             => $this->senderName($message),
            'created_by_telegram_id' => Arr::get($message, 'from.id'),
        ]);

        $text = strtr((string) config('constants.REMINDER.CREATED_TEXT'), [
            '{time}'    => $remindAt->format('Y-m-d H:i'),
            '{when}'    => $this->whenText($remindAt),
            '{content}' => $content,
        ]);

        $this->reply($text, $threadId, $this->cancelKeyboard($reminder->id));

        Log::info('預約提醒已建立', [
            'reminder_id' => $reminder->id,
            'remind_at'   => $remindAt->toDateTimeString(),
            'by'          => $this->senderName($message),
        ]);
    }

    /**
     * 列出這個話題還沒發的
     *
     * ⚠ 只列**這個話題**的：在「對帳」話題問「有哪些提醒」，不該看到
     * 「客訴」話題排的那些。
     *
     * @param int      $chatId
     * @param int|null $threadId
     * @return void
     */
    private function replyList($chatId, $threadId)
    {
        $max = (int) config('constants.REMINDER.LIST_MAX');
        $items = $this->repository->getPendingInThread($chatId, $threadId, $max + 1);

        if ($items->isEmpty()) {
            $this->reply((string) config('constants.REMINDER.LIST_EMPTY'), $threadId);

            return;
        }

        $text = (string) config('constants.REMINDER.LIST_TITLE');
        $shown = 0;

        foreach ($items as $item) {
            if ($shown >= $max) {
                break;
            }

            $text .= strtr((string) config('constants.REMINDER.LIST_LINE'), [
                '{time}'    => $item->remind_at->format('Y-m-d H:i'),
                '{when}'    => $this->whenText($item->remind_at),
                '{content}' => $item->content,
            ]);
            $shown++;
        }

        /*
         * ⚠ 多撈一筆就是為了這一行：`count()` 等於上限時分不出
         * 「剛好十筆」還是「還有更多」。
         */
        if ($items->count() > $shown) {
            $text .= strtr((string) config('constants.REMINDER.LIST_MORE'), [
                '{count}' => $items->count() - $shown,
            ]);
        }

        $this->reply($text, $threadId);
    }

    /**
     * 取消按鈕按下去
     *
     * @param array    $parts     callback_data 去掉前綴後的片段
     * @param int|null $messageId 按鈕所在的訊息
     * @return void
     */
    public function handleCallback(array $parts, $messageId)
    {
        $reminder = $this->repository->find((int) Arr::get($parts, 0, 0));

        if (blank($reminder)) {
            $this->supportGroup->editMessage($messageId, (string) config('constants.REMINDER.TOO_LATE_TEXT'));

            return;
        }

        /*
         * 0 筆代表這筆剛剛已經發出去了（排程與按鈕撞在一起）——
         * 要照實說「來不及取消」，回「已取消」是在騙人：訊息已經出去了。
         */
        $cancelled = $this->repository->cancel($reminder->id) > 0;

        $this->supportGroup->editMessage($messageId, (string) config(
            $cancelled ? 'constants.REMINDER.CANCELLED_TEXT' : 'constants.REMINDER.TOO_LATE_TEXT'
        ));

        Log::info('預約提醒取消', ['reminder_id' => $reminder->id, 'cancelled' => $cancelled]);
    }

    /**
     * 排程：把到時間的發出去
     *
     * ⚠ **一筆失敗不能讓整輪停掉** —— 逐筆送、逐筆標記。
     *
     * ⚠ **先標記再送**？不行。送失敗時那筆就永遠不會再試了。
     * 現在是送成功才標 `SENT`，失敗的下一分鐘會再試一次 ——
     * 重複發一則提醒，比永遠不發好。
     *
     * @return int 實際發出幾筆
     */
    public function sendDue()
    {
        $items = $this->repository->getDue();

        if ($items->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach ($items as $item) {
            if ($this->fire($item)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * 發一筆
     *
     * @param ScheduledReminder $reminder
     * @return bool
     */
    private function fire(ScheduledReminder $reminder)
    {
        $text = strtr((string) config('constants.REMINDER.FIRE_TEXT'), [
            '{by}'      => (string) ($reminder->created_by ?: '有人'),
            '{created}' => $reminder->created_at->format('n/j H:i'),
            '{content}' => (string) $reminder->content,
        ]);

        try {
            $result = $this->supportGroup->sendToThread($text, $reminder->message_thread_id);

            if (blank(Arr::get((array) $result, 'result'))) {
                Log::warning('預約提醒送不出去，下一分鐘再試', ['reminder_id' => $reminder->id]);

                return false;
            }

            $this->repository->markSent($reminder->id);

            return true;
        } catch (\Exception $e) {
            Log::error('預約提醒發送失敗', ['reminder_id' => $reminder->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 回話給使用者（一定回在他說話的那個話題）
     *
     * @param string     $text
     * @param int|null   $threadId
     * @param array|null $keyboard
     * @return void
     */
    private function reply($text, $threadId, $keyboard = null)
    {
        $this->supportGroup->sendToThread($text, $threadId, $keyboard);
    }

    /**
     * 取消按鈕
     *
     * @param int $reminderId
     * @return array
     */
    private function cancelKeyboard($reminderId)
    {
        $prefix = (string) config('constants.REMINDER.CALLBACK');

        return [[
            ['text' => (string) config('constants.REMINDER.BUTTON_CANCEL'), 'callback_data' => "{$prefix}:{$reminderId}"],
        ]];
    }

    /**
     * 預約的人叫什麼
     *
     * @param array $message
     * @return string
     */
    private function senderName(array $message)
    {
        $from = (array) Arr::get($message, 'from', []);
        $username = Arr::get($from, 'username');

        if (filled($username)) {
            return "@{$username}";
        }

        $name = trim(Arr::get($from, 'first_name', '') . ' ' . Arr::get($from, 'last_name', ''));

        return filled($name) ? $name : '同仁';
    }

    /**
     * 還有多久
     *
     * ⚠ 三種單位各自取整：「0 天後」看起來像壞掉，「1440 分鐘後」沒人換算得出來。
     *
     * @param \Illuminate\Support\Carbon $date
     * @return string
     */
    private function whenText($date)
    {
        $minutes = now()->diffInMinutes($date, false);

        if ($minutes < 0) {
            return (string) config('constants.REMINDER.WHEN_PAST');
        }

        if ($minutes < 1) {
            return (string) config('constants.REMINDER.WHEN_NOW');
        }

        if ($minutes < 60) {
            return strtr((string) config('constants.REMINDER.WHEN_MINS'), ['{minutes}' => $minutes]);
        }

        if ($minutes < 1440) {
            return strtr((string) config('constants.REMINDER.WHEN_HOURS'), ['{hours}' => intdiv($minutes, 60)]);
        }

        return strtr((string) config('constants.REMINDER.WHEN_DAYS'), ['{days}' => intdiv($minutes, 1440)]);
    }
}
