<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「確認一下 @aabb123 的卡片進度狀況」解析成要查誰
 *
 * ⚠ **`account` 要原文照抄**（含 `@`）。那是 Telegram 的 username，
 * 會拿去對 `user.telegram_username` —— 模型自作主張把它改成暱稱或去掉底線，
 * 就查不到人了。
 *
 * ⚠ **一次只查一個人。** 講了兩個以上回 unknown ——
 * 把兩個人的卡混在一起列，看的人分不出哪張是誰的。
 */
class TaskQueryCommandParser
{
    /** @var string 要查某個人的卡片進度 */
    const INTENT_TASK = 'task';

    /** @var string 不是在問卡片（呼叫端要換下一個 handler） */
    const INTENT_NOT_TASK = 'not_task';

    /** @var string 是在問卡片，但沒講要查誰 */
    const INTENT_UNKNOWN = 'unknown';

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 解析一句話
     *
     * @param string $text 已經去掉 @機器人
     * @return array{intent: string, account: string|null}|null null = 模型不可用
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return ['intent' => self::INTENT_UNKNOWN, 'account' => null];
        }

        $config = (array) config('constants.TASK_QUERY_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('卡片進度指令解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalize($result);
    }

    /**
     * @param array $result
     * @return array
     */
    private function normalize(array $result)
    {
        $intent = (string) Arr::get($result, 'intent', self::INTENT_UNKNOWN);

        if ($intent === self::INTENT_NOT_TASK) {
            return ['intent' => self::INTENT_NOT_TASK, 'account' => null];
        }

        // `@` 去掉，`findByTelegramUsername()` 兩種都吃，但統一比較好比對
        $account = ltrim(trim((string) Arr::get($result, 'account')), '@');

        if ($intent !== self::INTENT_TASK || blank($account)) {
            return ['intent' => self::INTENT_UNKNOWN, 'account' => null];
        }

        return ['intent' => self::INTENT_TASK, 'account' => $account];
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
                    'type' => 'string',
                    'enum' => [self::INTENT_TASK, self::INTENT_NOT_TASK, self::INTENT_UNKNOWN],
                    'description' => '要查某個人的卡片進度（task）、不是在問卡片（not_task）、是在問但沒講要查誰（unknown）',
                ],
                'account' => [
                    'type'        => ['string', 'null'],
                    'description' => '要查誰的卡片：句子裡那個 @帳號，原文照抄不要改寫。純文字、不要放 JSON。沒講就填 null',
                ],
            ],
            'required' => ['intent', 'account'],
        ];
    }
}
