<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「做一個 Ckpay 的排程，商戶號 12345、金鑰 abcdef」解析出來
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
    /** @var string 要做一份排程設定 */
    const INTENT_SPIDER = 'spider';

    /** @var string 不是在講這件事（呼叫端要換下一個 handler） */
    const INTENT_NOT_SPIDER = 'not_spider';

    /** @var string 是在講，但沒講哪一個通道 */
    const INTENT_UNKNOWN = 'unknown';

    /** @var string 只要代收 */
    const SCOPE_DEPOSIT = 'deposit';

    /** @var string 只要代付 */
    const SCOPE_WITHDRAW = 'withdraw';

    /** @var string 兩種都要 */
    const SCOPE_BOTH = 'both';

    /** @var string 沒講要哪一種 */
    const SCOPE_UNSAID = 'unsaid';

    const SCOPES = [
        self::SCOPE_DEPOSIT,
        self::SCOPE_WITHDRAW,
        self::SCOPE_BOTH,
        self::SCOPE_UNSAID,
    ];

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 第一步：這句話是不是在要排程設定、要哪一個通道、代收還是代付
     *
     * ⚠ **分兩步問模型**。第一步只認通道與類型，第二步才帶著那個通道的欄位
     * 清單去對值 —— 一次問完的話，prompt 得塞進 64 個通道的全部欄位，
     * 又長又容易讓模型張冠李戴。
     *
     * @param string $text 已經去掉 @機器人
     * @return array{intent: string, channel: string|null, scope: string}|null null = 模型不可用
     */
    public function detect($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return $this->notSpider();
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
            Log::warning('排程設定指令解析失敗', ['error' => $e->getMessage()]);

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
     * @param array<string> $keys 這次要問的欄位（代收／代付合併後的全部）
     * @return array<string, string>|null 欄位 => 值；null = 模型不可用
     */
    public function extract($text, $channel, array $keys)
    {
        // 短路：這個通道一個欄位都不吃（例如 SelfHold）就不必問模型
        if (blank($keys)) {
            return [];
        }

        $config = (array) config('constants.SPIDER_SETTING_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                strtr((string) Arr::get($config, 'EXTRACT_PROMPT'), [
                    '{channel}' => $channel,
                    '{fields}'  => implode('、', $keys),
                ]),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => trim((string) $text)]),
                $this->extractSchema($keys)
            );
        } catch (\Exception $e) {
            Log::warning('排程設定欄位對應失敗', ['channel' => $channel, 'error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalizeExtract($result, $keys);
    }

    /**
     * @param array $result
     * @return array
     */
    private function normalizeDetect(array $result)
    {
        $intent = (string) Arr::get($result, 'intent', self::INTENT_UNKNOWN);

        if ($intent === self::INTENT_NOT_SPIDER) {
            return $this->notSpider();
        }

        $channel = trim((string) Arr::get($result, 'channel'));

        if ($intent !== self::INTENT_SPIDER || blank($channel)) {
            return [
                'intent'  => self::INTENT_UNKNOWN,
                'channel' => null,
                'scope'   => $this->scope(Arr::get($result, 'scope')),
            ];
        }

        return [
            'intent'  => self::INTENT_SPIDER,
            'channel' => $channel,
            'scope'   => $this->scope(Arr::get($result, 'scope')),
        ];
    }

    /**
     * 代收／代付
     *
     * ⚠ 認不出來一律回 `unsaid`（**不是**猜一個）—— 呼叫端看到 `unsaid`
     * 會把兩份都給，那是「沒講」時最不會漏的做法；猜錯則是只給一份，
     * 而他要的是另一份。
     *
     * @param mixed $value
     * @return string
     */
    private function scope($value)
    {
        $scope = trim((string) $value);

        return in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_UNSAID;
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
        $flags = (array) config('apollo_fields.FLAGS');
        $clean = [];

        foreach ($allowed as $key) {
            $value = trim((string) Arr::get($fields, $key, ''));

            if (blank($value)) {
                continue;
            }

            /*
             * ⚠ 開關型欄位（目前只有 `order_group`）**一律改寫成 `"1"`**。
             *
             * 模型照抄人話是對的規則（憑證不能被改），但這幾個欄位的值只有
             * 「有」「沒有」兩種意義 —— 實測「同單多組要開」會被抄成
             * `"開"`，而主系統判斷只用 `filled()`，所以它「能跑」、不會報錯，
             * 只是資料是錯的，下一個看到的人會以為這裡可以填中文。
             */
            $clean[$key] = in_array($key, $flags, true) ? '1' : $value;
        }

        return $clean;
    }

    /**
     * @return array
     */
    private function notSpider()
    {
        return [
            'intent'  => self::INTENT_NOT_SPIDER,
            'channel' => null,
            'scope'   => self::SCOPE_UNSAID,
        ];
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
                    'description' => '要做一份排程設定（spider）、不是在講這件事（not_spider）、是在講但沒指名通道（unknown）',
                ],
                'channel' => [
                    'type'        => ['string', 'null'],
                    'description' => '通道代號，必須是清單裡的其中一個，大小寫照清單。認不出來就填 null',
                ],
                'scope' => [
                    'type'        => 'string',
                    'enum'        => self::SCOPES,
                    'description' => '他要代收（deposit）、代付（withdraw）、兩種都要（both），還是沒講（unsaid）',
                ],
            ],
            'required' => ['intent', 'channel', 'scope'],
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
                'description' => '對方有給這個欄位的值就填，原文照抄不要改寫；沒給就填 null',
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
