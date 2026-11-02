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

    /** @var string 是要開卡，但沒講標題 */
    const INTENT_UNKNOWN = 'unknown';

    /** @var array<string> 優先序的合法值（對應 constants.TASK.PRIORITY.*） */
    const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

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
     *     null = 模型不可用
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

        $title = trim((string) Arr::get($result, 'title'));

        // 是要開卡但沒講標題 —— 問回去，不要用整句話當標題
        if ($intent !== self::INTENT_CREATE || blank($title)) {
            return $this->unknown();
        }

        return [
            'intent'   => self::INTENT_CREATE,
            'title'    => $title,
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
                    'description' => '要開一張任務卡（create）、不是要開卡（not_create）、是要開卡但沒講要開什麼（unknown）',
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
