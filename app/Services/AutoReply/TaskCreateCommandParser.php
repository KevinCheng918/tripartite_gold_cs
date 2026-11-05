<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 「幫我開一張卡」的指令解析
 *
 * ⚠ 照 `TaskQueryCommandParser` 寫的 —— 同一套 intent／schema／normalize
 * 的形狀。差別只在這一支要多拆幾個欄位。
 *
 * ⚠ **這支只負責讀懂句子，不碰資料庫也不判斷權限。** 專案名要不要對得上、
 * 那個 @帳號存不存在，都是 `TaskCreateCommandService` 的事 ——
 * 混進來的話，模型回什麼就得查什麼，解析會變得很慢而且不好測。
 */
class TaskCreateCommandParser
{
    /** @var string 他要開一張卡 */
    const INTENT_CREATE = 'create';

    /** @var string 這句話不是要開卡 */
    const INTENT_NOT_CREATE = 'not_create';

    /**
     * 模型自己也不確定這句話是什麼
     *
     * ⚠⚠ **這一種一定要讓給下一支 handler，不可以回話。**
     *
     * 2026-11-05 踩過：有人說「購物網站規章」，模型回 unknown，而當時的服務
     * 把 unknown 當成「要開卡但沒講標題」直接回了「要開什麼卡片？」並
     * `return true` —— 於是**規章那支永遠跑不到**。
     *
     * 這一支在分派器裡排得很前面，所以它一旦誤判就會把訊息整個吞掉。
     * 「不確定」的歸宿是後面的 handler，不是這裡。
     *
     * @var string
     */
    const INTENT_UNKNOWN = 'unknown';

    /**
     * 確定是要開卡，但沒講要開什麼
     *
     * ⚠ 跟 `INTENT_UNKNOWN` **不是同一件事**：這一種是模型明確說了 create，
     * 只是標題拆不出來 —— 那時候回「要開什麼卡片？」才有意義。
     *
     * @var string
     */
    const INTENT_NEED_TITLE = 'need_title';

    /** @var array<string> 優先序的合法值（對應 constants.TASK.PRIORITY.*） */
    const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /**
     * 標題上限（字元）
     *
     * ⚠⚠ **指令這條路沒有經過 `StoreTaskRequest`**（那是後台表單的驗證），
     * 所以 `max:200` 擋不到這裡 —— 而 `task.title` 是 `varchar(200)`。
     * 不截的話，使用者貼一整段錯誤訊息進來會直接撞資料庫長度限制，
     * 而他只會看到一句「建立失敗了」，完全不知道是標題太長。
     *
     * ⚠ 截在**解析**這一層，不是寫入前：這樣使用者在確認卡上看到的就是
     * 真正會被存下來的標題，覺得被截得奇怪可以當場按取消。
     *
     * ⚠ 一定要用 `mb_substr` —— `substr` 會把中文從中間切斷成亂碼。
     *
     * @var int
     */
    const TITLE_MAX = 200;

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 解析一句話
     *
     * @param string $text 已經去掉 @機器人
     * @return array{intent: string, title: string|null, project: string|null,
     *     assignee: string|null, priority: string|null, due_date: string|null}|null
     *     intent 見 INTENT_*；null = 模型不可用
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return $this->unknown();
        }

        $config = (array) config('constants.TASK_CREATE_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), [
                    '{text}'  => $text,
                    // 模型要有「今天」才算得出「明天」「下週一」
                    '{today}' => now()->toDateString(),
                ]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('開卡指令解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalize($result);
    }

    /**
     * 把模型回的東西整理成固定形狀
     *
     * @param array $result
     * @return array
     */
    private function normalize(array $result)
    {
        $intent = (string) Arr::get($result, 'intent', self::INTENT_UNKNOWN);

        if ($intent === self::INTENT_NOT_CREATE) {
            return $this->notCreate();
        }

        // 模型自己不確定 —— 讓給下一支，理由見 `INTENT_UNKNOWN`
        if ($intent !== self::INTENT_CREATE) {
            return $this->unknown();
        }

        $title = trim((string) Arr::get($result, 'title'));

        // 確定要開卡但沒講標題 —— 問回去，不要拿整句話當標題
        if (blank($title)) {
            return $this->needTitle();
        }

        return [
            'intent'   => self::INTENT_CREATE,
            // 理由見 `TITLE_MAX`
            'title'    => mb_substr($title, 0, self::TITLE_MAX),
            'project'  => $this->nullableText(Arr::get($result, 'project')),
            // `@` 去掉，跟 `TaskQueryCommandParser` 一致
            'assignee' => ltrim((string) $this->nullableText(Arr::get($result, 'assignee')), '@') ?: null,
            'priority' => $this->priority(Arr::get($result, 'priority')),
            'due_date' => $this->dueDate(Arr::get($result, 'due_date')),
        ];
    }

    /**
     * 空字串與 'null' 字樣都當成沒講
     *
     * ⚠ 模型有時候會把「沒有」回成字串 `"null"` 而不是 JSON null ——
     * 不擋的話專案名會變成一個叫「null」的專案去比對，然後永遠對不上。
     *
     * @param mixed $value
     * @return string|null
     */
    private function nullableText($value)
    {
        $text = trim((string) $value);

        if (blank($text) || strtolower($text) === 'null') {
            return null;
        }

        return $text;
    }

    /**
     * 優先序：只收白名單內的值
     *
     * @param mixed $value
     * @return string|null
     */
    private function priority($value)
    {
        $text = strtolower(trim((string) $value));

        return in_array($text, self::PRIORITIES, true) ? $text : null;
    }

    /**
     * 到期日：只收 Y-m-d
     *
     * ⚠ **在這裡擋掉格式不對的**，不要丟給 `Carbon::parse()` 自己猜 ——
     * 模型偶爾會回「下週一」這種原文，`parse()` 會丟例外或給出一個
     * 很奇怪的日期，而那會變成卡片上一個沒人看得懂的到期日。
     *
     * @param mixed $value
     * @return string|null
     */
    private function dueDate($value)
    {
        $text = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            return null;
        }

        return $text;
    }

    /**
     * @return array
     */
    private function notCreate()
    {
        return [
            'intent'   => self::INTENT_NOT_CREATE,
            'title'    => null,
            'project'  => null,
            'assignee' => null,
            'priority' => null,
            'due_date' => null,
        ];
    }

    /**
     * @return array
     */
    private function needTitle()
    {
        return [
            'intent'   => self::INTENT_NEED_TITLE,
            'title'    => null,
            'project'  => null,
            'assignee' => null,
            'priority' => null,
            'due_date' => null,
        ];
    }

    /**
     * @return array
     */
    private function unknown()
    {
        return [
            'intent'   => self::INTENT_UNKNOWN,
            'title'    => null,
            'project'  => null,
            'assignee' => null,
            'priority' => null,
            'due_date' => null,
        ];
    }

    /**
     * @return array
     */
    private function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'intent' => [
                    'type'        => 'string',
                    'enum'        => [self::INTENT_CREATE, self::INTENT_NOT_CREATE, self::INTENT_UNKNOWN],
                    'description' => '要開一張任務卡（create）、不是要開卡（not_create）、你不確定這句話是什麼（unknown）。不確定就填 unknown 或 not_create，不要硬猜成 create',
                ],
                'title' => [
                    'type'        => ['string', 'null'],
                    'description' => '卡片標題：把那件事濃縮成一句話，不要含「幫我開一張卡」這種指令本身。純文字、不要放 JSON。沒講就填 null',
                ],
                'project' => [
                    'type'        => ['string', 'null'],
                    'description' => '專案名稱，句子裡怎麼寫就怎麼填。沒講就填 null',
                ],
                'assignee' => [
                    'type'        => ['string', 'null'],
                    'description' => '要指派給誰：句子裡那個 @帳號，原文照抄不要改寫。沒講就填 null',
                ],
                'priority' => [
                    'type'        => ['string', 'null'],
                    'enum'        => ['low', 'medium', 'high', 'urgent', null],
                    'description' => '優先序。沒講就填 null，不要自己判斷急不急',
                ],
                'due_date' => [
                    'type'        => ['string', 'null'],
                    'description' => '到期日，格式一定要是 YYYY-MM-DD。沒講就填 null',
                ],
            ],
            'required' => ['intent', 'title', 'project', 'assignee', 'priority', 'due_date'],
        ];
    }
}
