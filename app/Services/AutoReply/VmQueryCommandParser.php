<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「幫我查一下 LV_UAT 虛擬機」解析成要查什麼
 *
 * 三種問法對到三種回覆：
 *
 * ```
 * 有講站台                  → station（那個站台的完整狀況）
 * 沒講站台 ＋ 問繳費／欠錢    → unpaid（跨站台的未收清單）
 * 沒講站台 ＋ 問開機關機      → power（跨站台的電源清單）
 * ```
 *
 * ⚠ **講了站台就一律回完整狀況**，不管他問的是規格、繳費還是電源 ——
 * 那張卡片本來就包含這些（需求方：「查虛擬機都是回應」這幾項）。
 * 為「他問的是哪一項」再分支，只是多一個會分岔的地方。
 */
class VmQueryCommandParser
{
    /** @var string 查某個站台的虛擬機 */
    const INTENT_STATION = 'station';

    /** @var string 跨站台：誰還沒繳錢 */
    const INTENT_UNPAID = 'unpaid';

    /** @var string 跨站台：誰開機誰關機 */
    const INTENT_POWER = 'power';

    /** @var string 不是在問虛擬機（呼叫端要換下一個 handler） */
    const INTENT_NOT_VM = 'not_vm';

    /** @var string 是在問虛擬機，但聽不出要查什麼 */
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

        $config = (array) config('constants.VM_QUERY_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('虛擬機查詢解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalize($result);
    }

    /**
     * 把模型回的東西收斂成可用的形狀
     *
     * ⚠ **`station` 填了就一定是 `station` 意圖。** 模型偶爾會回
     * 「intent=power ＋ station=LV_UAT」（他問「lv-uat 他是開機還是關機」）
     * —— 講了站台就該回那個站台的完整狀況，不是跨站台的清單。
     * 在這裡收斂，呼叫端就不必再判一次。
     *
     * @param array $result
     * @return array
     */
    private function normalize(array $result)
    {
        $intent = (string) Arr::get($result, 'intent', self::INTENT_UNKNOWN);

        if ($intent === self::INTENT_NOT_VM) {
            return ['intent' => self::INTENT_NOT_VM, 'station' => null];
        }

        $station = trim((string) Arr::get($result, 'station'));

        if (filled($station)) {
            return ['intent' => self::INTENT_STATION, 'station' => $station];
        }

        // 沒講站台，剩下只能是那兩種跨站台清單
        if (in_array($intent, [self::INTENT_UNPAID, self::INTENT_POWER], true)) {
            return ['intent' => $intent, 'station' => null];
        }

        return ['intent' => self::INTENT_UNKNOWN, 'station' => null];
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
                    'enum' => [
                        self::INTENT_STATION,
                        self::INTENT_UNPAID,
                        self::INTENT_POWER,
                        self::INTENT_NOT_VM,
                        self::INTENT_UNKNOWN,
                    ],
                    'description' => '查某個站台的虛擬機（station）、誰還沒繳錢（unpaid）、誰開機誰關機（power）、不是在問虛擬機（not_vm）、問虛擬機但聽不出要查什麼（unknown）',
                ],
                'station' => [
                    'type'        => ['string', 'null'],
                    'description' => '站台名稱原文，純文字、不要放 JSON，不要帶「的」「虛擬機」「站台」。沒指名哪一個站台就填 null',
                ],
            ],
            'required' => ['intent', 'station'],
        ];
    }
}
