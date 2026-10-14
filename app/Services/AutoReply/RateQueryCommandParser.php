<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「今天匯率多少」解析成「要看今天的補點訊息」
 *
 * ⚠ 這一支**不抽任何欄位**，只回答 yes/no —— 問的就是「今天」那一份，
 * 沒有站台、沒有時間、沒有對象要認。所以 schema 只有一個 `intent`。
 *
 * ⚠ 要跟「**預約**傳訊息」分乾淨：「等一下跟客戶說今天匯率」是預約，
 * 不是現在要看。prompt 裡特別點出來。
 */
class RateQueryCommandParser
{
    /** @var string 要看今天的匯率／補點訊息 */
    const INTENT_RATE = 'rate';

    /** @var string 不是在問今天的匯率（呼叫端要換下一個 handler） */
    const INTENT_NOT_RATE = 'not_rate';

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 解析一句話
     *
     * @param string $text 已經去掉 @機器人
     * @return array{intent: string}|null null = 模型不可用
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return ['intent' => self::INTENT_NOT_RATE];
        }

        $config = (array) config('constants.RATE_QUERY_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('匯率查詢指令解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        $intent = (string) Arr::get($result, 'intent', self::INTENT_NOT_RATE);

        return ['intent' => $intent === self::INTENT_RATE ? self::INTENT_RATE : self::INTENT_NOT_RATE];
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
                    'enum' => [self::INTENT_RATE, self::INTENT_NOT_RATE],
                    'description' => '要看今天的匯率或補點訊息（rate）、不是在問這個（not_rate）',
                ],
            ],
            'required' => ['intent'],
        ];
    }
}
