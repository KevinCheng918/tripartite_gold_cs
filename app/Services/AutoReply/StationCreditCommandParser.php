<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「幫我查一下 LV_UAT 餘點」解析成站台名稱
 *
 * 跟另外兩支解析器同一個形狀（讀一句話、回結構），只是這一支特別簡單 ——
 * 它只需要抽出一個名詞。
 *
 * ⚠ **為什麼不用正則抓**：「幫我查一下 LV_UAT 餘點」「LV_UAT 還有多少點」
 * 「查 LV_UAT」的詞序都不一樣，而站台名稱本身沒有固定格式（可能有底線、
 * 數字、中文）。正則寫得出來的那幾種寫法，使用者偏偏會用第四種。
 *
 * ⚠ **一次只認一個站台。** 講了兩個以上回 unknown ——
 * 猜錯要查哪一個，使用者會拿著別人的點數做判斷。
 */
class StationCreditCommandParser
{
    /** @var string 要查餘點 */
    const INTENT_CREDIT = 'credit';

    /** @var string 不是在查餘點（呼叫端要換下一個 handler） */
    const INTENT_NOT_CREDIT = 'not_credit';

    /** @var string 是在查餘點，但沒講哪一個站台 */
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
     * @return array{intent: string, station: string|null}|null null = 模型不可用
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return ['intent' => self::INTENT_UNKNOWN, 'station' => null];
        }

        $config = (array) config('constants.STATION_CREDIT_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('餘點查詢解析失敗', ['error' => $e->getMessage()]);

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

        if ($intent === self::INTENT_NOT_CREDIT) {
            return ['intent' => self::INTENT_NOT_CREDIT, 'station' => null];
        }

        $station = trim((string) Arr::get($result, 'station'));

        if ($intent !== self::INTENT_CREDIT || blank($station)) {
            return ['intent' => self::INTENT_UNKNOWN, 'station' => null];
        }

        return ['intent' => self::INTENT_CREDIT, 'station' => $station];
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
                    'enum' => [self::INTENT_CREDIT, self::INTENT_NOT_CREDIT, self::INTENT_UNKNOWN],
                    'description' => '要查站台餘點（credit）、不是在查餘點（not_credit）、還是在查但沒講站台（unknown）',
                ],
                'station' => [
                    'type'        => ['string', 'null'],
                    'description' => '站台名稱原文，純文字、不要放 JSON，不要帶「的」「餘點」「站台」。沒講就填 null',
                ],
            ],
            'required' => ['intent', 'station'],
        ];
    }
}
