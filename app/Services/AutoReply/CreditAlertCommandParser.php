<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「傳餘點告警給 LV_UAT」解析成站台名稱
 *
 * ⚠ **「查」與「發」要分得乾淨。** 這是四支指令裡唯一會把訊息送到客戶群的，
 * 認錯的代價不是回錯話，是**客戶收到一則不該收到的告警**。
 *
 * 所以 prompt 裡特別寫明：想知道還剩多少點是 `not_alert`，
 * 要把告警送出去才是 `alert`。
 *
 * ⚠ **一次只認一個站台。** 講了兩個以上回 unknown ——
 * 猜錯要發給誰，等於發錯對象。
 */
class CreditAlertCommandParser
{
    /** @var string 要發餘點告警給某個站台 */
    const INTENT_ALERT = 'alert';

    /** @var string 不是要發告警（呼叫端要換下一個 handler） */
    const INTENT_NOT_ALERT = 'not_alert';

    /** @var string 是要發，但沒講發給哪一個站台 */
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

        $config = (array) config('constants.CREDIT_ALERT_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('餘點告警指令解析失敗', ['error' => $e->getMessage()]);

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

        if ($intent === self::INTENT_NOT_ALERT) {
            return ['intent' => self::INTENT_NOT_ALERT, 'station' => null];
        }

        $station = trim((string) Arr::get($result, 'station'));

        /*
         * ⚠ 沒講站台就不是 `alert`。這一支會發訊息給客戶，
         * 寧可回一句「要發給誰」也不要讓後面有任何猜的空間。
         */
        if ($intent !== self::INTENT_ALERT || blank($station)) {
            return ['intent' => self::INTENT_UNKNOWN, 'station' => null];
        }

        return ['intent' => self::INTENT_ALERT, 'station' => $station];
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
                    'enum' => [self::INTENT_ALERT, self::INTENT_NOT_ALERT, self::INTENT_UNKNOWN],
                    'description' => '要發餘點告警給某站台（alert）、不是要發告警（not_alert）、要發但沒講站台（unknown）',
                ],
                'station' => [
                    'type'        => ['string', 'null'],
                    'description' => '站台名稱原文，純文字、不要放 JSON，不要帶「的」「站台」「群組」。沒講就填 null',
                ],
            ],
            'required' => ['intent', 'station'],
        ];
    }
}
