<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 把「明天下午三點提醒大家對帳」解析成時間與內容
 *
 * 跟 `WaitDetector` 同一類：讀一句話、回一個結構，共用 `ClaudeCodeMatcher`。
 * 放在這個目錄是因為所有吃那支 matcher 的解析器都在這裡，不是因為它跟
 * 自動回覆有關。
 *
 * ⚠ **模型不知道今天幾號。** 「明天」「下週一」「三小時後」沒有基準點就
 * 算不出來，所以 prompt 一定要帶現在時間進去。
 *
 * ⚠ **解析不出來就回 unknown，不要猜。** 猜一個日期的後果是提醒在錯的
 * 時間跳出來，而那時原本要做的事已經過了 —— 當場說「看不懂」讓人重講一次
 * 便宜得多。
 */
class ReminderParser
{
    /** @var string 要排一個提醒 */
    const INTENT_CREATE = 'create';

    /** @var string 要看已經排了哪些 */
    const INTENT_LIST = 'list';

    /** @var string 看不懂 */
    const INTENT_UNKNOWN = 'unknown';

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 解析一句話
     *
     * @param string $text 使用者說的話（已經去掉 @機器人）
     * @return array{intent: string, remind_at: \Illuminate\Support\Carbon|null, content: string|null}|null
     *         回 null 代表模型不可用（沒設 token、額度用完、CLI 逾時）——
     *         跟「看不懂」不一樣，呼叫端的回覆文案不同
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return ['intent' => self::INTENT_UNKNOWN, 'remind_at' => null, 'content' => null];
        }

        $config = (array) config('constants.REMINDER');
        $now = now();

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), [
                    '{now}'     => $now->format('Y-m-d H:i'),
                    '{weekday}' => $this->weekday($now),
                    '{text}'    => $text,
                ]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('預約提醒解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalize($result, $now);
    }

    /**
     * 把模型回來的東西整理成呼叫端要的形狀
     *
     * ⚠ **時間一定要自己再驗一次。** prompt 寫了「必須在現在之後」，
     * 但那是請求不是保證 —— 落在過去的提醒會在排程下一次跑時立刻發出去，
     * 看起來就像系統壞了。
     *
     * @param array                      $result
     * @param \Illuminate\Support\Carbon $now
     * @return array
     */
    private function normalize(array $result, $now)
    {
        $intent = (string) Arr::get($result, 'intent', self::INTENT_UNKNOWN);

        if ($intent !== self::INTENT_CREATE) {
            // list 與 unknown 都不需要時間與內容
            return [
                'intent'    => $intent === self::INTENT_LIST ? self::INTENT_LIST : self::INTENT_UNKNOWN,
                'remind_at' => null,
                'content'   => null,
            ];
        }

        $content = trim((string) Arr::get($result, 'content'));
        $remindAt = $this->parseTime(Arr::get($result, 'remind_at'));

        /*
         * 時間看不懂、內容是空的、或時間落在過去 —— 都排不成，當成看不懂。
         *
         * ⚠ 還要擋「content 是一整包 JSON」：實測時模型真的把整個輸出
         * 塞進那一欄過（2026-10-12 的雙層 JSON 同一個形狀）。schema 的
         * description 已經說了「純文字」，但那是請求不是保證 ——
         * 放過去的話，時間到了會有一串大括號發到群組裡。
         */
        if (blank($remindAt) || blank($content) || $remindAt->lessThanOrEqualTo($now) || $this->looksLikeJson($content)) {
            Log::info('預約提醒解析結果不可用', [
                'remind_at' => Arr::get($result, 'remind_at'),
                'content'   => $content,
            ]);

            return ['intent' => self::INTENT_UNKNOWN, 'remind_at' => null, 'content' => null];
        }

        return ['intent' => self::INTENT_CREATE, 'remind_at' => $remindAt, 'content' => $content];
    }

    /**
     * 這段內容是不是一整包 JSON
     *
     * 誤殺的代價只是請對方重講一次；放過去的代價是群組裡跳出一串大括號。
     *
     * @param string $content
     * @return bool
     */
    private function looksLikeJson($content)
    {
        return is_array(json_decode($content, true));
    }

    /**
     * `Y-m-d H:i` → Carbon
     *
     * ⚠ 用 `createFromFormat` 而不是 `Carbon::parse()`：後者什麼都吃得下，
     * 模型回一句「明天下午」也會被它解析成某個時間，而那不是我們要的格式。
     *
     * @param mixed $value
     * @return \Illuminate\Support\Carbon|null
     */
    private function parseTime($value)
    {
        if (blank($value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d H:i', trim((string) $value));
        } catch (\Exception $e) {
            return null;
        }

        return filled($date) ? $date->seconds(0) : null;
    }

    /**
     * 星期幾（給模型當基準）
     *
     * @param \Illuminate\Support\Carbon $date
     * @return string
     */
    private function weekday($date)
    {
        $names = ['日', '一', '二', '三', '四', '五', '六'];

        return '週' . Arr::get($names, (int) $date->dayOfWeek, '');
    }

    /**
     * 要模型填的欄位
     *
     * @return array
     */
    private function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => [self::INTENT_CREATE, self::INTENT_LIST, self::INTENT_UNKNOWN],
                    'description' => '他要排提醒（create）、要看清單（list）、還是看不懂（unknown）',
                ],
                'remind_at' => [
                    'type'        => ['string', 'null'],
                    'description' => '什麼時候提醒，格式 YYYY-MM-DD HH:MM。看不懂時間就填 null，不要猜',
                ],
                'content' => [
                    'type' => ['string', 'null'],
                    /*
                     * ⚠ 「純文字，不要放 JSON」不是廢話：實測時模型把整包
                     * JSON 塞進這一欄過（跟 2026-10-12 的雙層 JSON 同一個形狀）。
                     * 加了這句之後就正常 —— 但那仍然是請求，所以
                     * `normalize()` 還會再擋一次。
                     */
                    'description' => '要提醒的那句話本身，純文字、不要放 JSON，不含時間描述。裡面的 @帳號原封不動保留',
                ],
            ],
            'required' => ['intent', 'remind_at', 'content'],
        ];
    }
}
