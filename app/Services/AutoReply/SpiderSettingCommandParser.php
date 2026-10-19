<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「做一個 Ckpay 的 spider_setting，商戶號 12345、金鑰 abcdef」解析出來
 *
 * ⚠ **模型只負責「把人話對到欄位名」，不負責知道有哪些欄位。**
 * 哪個通道要哪些欄位是查 `config('apollo_fields')` 得到的（從主專案掃出來的
 * 快照）—— 讓模型自己想欄位名，它會編出 `merchant_key` 這種不存在的鍵，
 * 而那份 JSON 是要貼進金流設定的。
 *
 * 所以 prompt 會把**那個通道的欄位清單一起餵進去**，模型做的是對應：
 * 「商戶號 12345」→ `app_id: "12345"`。
 */
class SpiderSettingCommandParser
{
    /** @var string 要做一份 spider_setting */
    const INTENT_SPIDER = 'spider';

    /** @var string 不是在講這件事（呼叫端要換下一個 handler） */
    const INTENT_NOT_SPIDER = 'not_spider';

    /** @var string 是在講，但沒講哪一個通道 */
    const INTENT_UNKNOWN = 'unknown';

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 第一步：這句話是不是在要 spider_setting、要哪一個通道
     *
     * ⚠ **分兩步問模型**。第一步只認通道，第二步才帶著那個通道的欄位清單
     * 去對值 —— 一次問完的話，prompt 得塞進 64 個通道的全部欄位，
     * 又長又容易讓模型張冠李戴。
     *
     * @param string $text 已經去掉 @機器人
     * @return array{intent: string, channel: string|null}|null null = 模型不可用
     */
    public function detect($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return ['intent' => self::INTENT_NOT_SPIDER, 'channel' => null];
        }

        $config = (array) config('constants.SPIDER_SETTING_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                strtr((string) Arr::get($config, 'DETECT_PROMPT'), [
                    '{channels}' => implode('、', array_keys((array) config('apollo_fields.CHANNELS'))),
                ]),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->detectSchema()
            );
        } catch (\Exception $e) {
            Log::warning('spider_setting 指令解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalizeDetect($result);
    }

    /**
     * 第二步：把句子裡的資訊對到這個通道的欄位
     *
     * @param string        $text
     * @param string        $channel
     * @param array<string> $required
     * @param array<string> $optional
     * @return array<string, string>|null 欄位 => 值；null = 模型不可用
     */
    public function extract($text, $channel, array $required, array $optional)
    {
        $config = (array) config('constants.SPIDER_SETTING_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                strtr((string) Arr::get($config, 'EXTRACT_PROMPT'), [
                    '{channel}'  => $channel,
                    '{required}' => filled($required) ? implode('、', $required) : '（無）',
                    '{optional}' => filled($optional) ? implode('、', $optional) : '（無）',
                ]),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => trim((string) $text)]),
                $this->extractSchema(array_merge($required, $optional))
            );
        } catch (\Exception $e) {
            Log::warning('spider_setting 欄位對應失敗', ['channel' => $channel, 'error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalizeExtract($result, array_merge($required, $optional));
    }

    /**
     * @param array $result
     * @return array
     */
    private function normalizeDetect(array $result)
    {
        $intent = (string) Arr::get($result, 'intent', self::INTENT_UNKNOWN);

        if ($intent === self::INTENT_NOT_SPIDER) {
            return ['intent' => self::INTENT_NOT_SPIDER, 'channel' => null];
        }

        $channel = trim((string) Arr::get($result, 'channel'));

        if ($intent !== self::INTENT_SPIDER || blank($channel)) {
            return ['intent' => self::INTENT_UNKNOWN, 'channel' => null];
        }

        return ['intent' => self::INTENT_SPIDER, 'channel' => $channel];
    }

    /**
     * 只留清單上的欄位，值一律轉成字串並去掉前後空白
     *
     * ⚠ **清單外的鍵一律丟掉。** 模型可能自己多補一個 `merchant_key`，
     * 那個鍵在 Apollo 裡沒人讀，貼進去只會讓人以為設好了。
     *
     * @param array         $result
     * @param array<string> $allowed
     * @return array<string, string>
     */
    private function normalizeExtract(array $result, array $allowed)
    {
        $fields = (array) Arr::get($result, 'fields', []);
        $clean = [];

        foreach ($allowed as $key) {
            $value = trim((string) Arr::get($fields, $key, ''));

            if (filled($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @return array
     */
    private function detectSchema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => [self::INTENT_SPIDER, self::INTENT_NOT_SPIDER, self::INTENT_UNKNOWN],
                    'description' => '要做一份 spider_setting（spider）、不是在講這件事（not_spider）、是在講但沒指名通道（unknown）',
                ],
                'channel' => [
                    'type'        => ['string', 'null'],
                    'description' => '通道代號，必須是清單裡的其中一個，大小寫照清單。認不出來就填 null',
                ],
            ],
            'required' => ['intent', 'channel'],
        ];
    }

    /**
     * 欄位對應的 schema
     *
     * ⚠ 每個欄位都明寫成 property，不用自由的 object —— 這樣模型只能填
     * 清單上的鍵，編不出別的名字。
     *
     * @param array<string> $keys
     * @return array
     */
    private function extractSchema(array $keys)
    {
        $properties = [];

        foreach ($keys as $key) {
            $properties[$key] = [
                'type'        => ['string', 'null'],
                'description' => "對方有給這個欄位的值就填，原文照抄不要改寫；沒給就填 null",
            ];
        }

        return [
            'type'       => 'object',
            'properties' => [
                'fields' => [
                    'type'       => 'object',
                    'properties' => $properties,
                    'required'   => array_values($keys),
                ],
            ],
            'required' => ['fields'],
        ];
    }
}
