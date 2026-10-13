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
    /*
     * ⚠ 這裡原本有一個 `NOTICE_TYPE`（話題分流用），2026-10-13 搬到
     * `TelegramCommandRouter` —— 話題准不准用機器人指令是**全部指令**共用的
     * 判斷，不是提醒專屬的。留一份在這裡遲早會跟那邊分岔。
     */

    private $repository;
    private $parser;
    private $supportGroup;

    public function __construct(
        ScheduledReminderRepository $repository,
        ReminderParser $parser,
        SupportGroupService $supportGroup
    ) {
        $this->repository = $repository;
        // 自然語言 → 時間 ＋ 內容
        $this->parser = $parser;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 排一筆提醒（或列出已排的）
     *
     * ⚠ **這一支是分派器的最後一棒**（`TelegramCommandRouter`）：
     * 前面的指令都不認領時才輪到它，所以它同時是「看不懂」的歸宿 ——
     * 回一句範例讓人重講，而不是靜默。
     *
     * ⚠ 「有沒有 tag 到機器人」「話題准不准」都在分派器做過了，
     * 這裡拿到的 `$body` 已經是去掉 `@機器人` 的那句話。
     *
     * @param string   $body     去掉 @機器人 之後的內容
     * @param int|null $threadId
     * @param array    $message  Telegram 的 message（要 from 與 chat.id）
     * @return bool 永遠 true —— 走到這裡就是要回話的
     */
    public function handle($body, $threadId, array $message)
    {
        $chatId = (int) Arr::get($message, 'chat.id');
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
     * ⚠ **基準要截到整分鐘**（`startOfMinute()`）。`remind_at` 的秒數一律是 0
     * （`ReminderParser` 存進去前就 `seconds(0)`），而 `now()` 帶著秒 ——
     * 14:52:30 說「五分鐘後」排到 14:57:00，實際差距是 4 分 30 秒，
     * `diffInMinutes()` 無條件捨去就變成「**4 分鐘後**」。
     * 使用者剛說完五分鐘，看到 4 只會覺得系統算錯（2026-10-13 回報）。
     *
     * 截到整分鐘之後，14:52:00 → 14:57:00 剛好是 5，跟他講的一致。
     *
     * @param \Illuminate\Support\Carbon $date
     * @return string
     */
    private function whenText($date)
    {
        $minutes = now()->startOfMinute()->diffInMinutes($date, false);

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
