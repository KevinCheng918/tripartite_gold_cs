<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把客人貼過來的開單 request／response 解析成欄位
 *
 * 客人貼的是 `print_r` 加 `var_dump` 的輸出：
 *
 * ```
 * 失敗：[-1]订单異常
 * 请求模块【代付開單】
 * 请求URL：https://data.xxx.net/api/withdraw
 *  原始參數 Array
 * (
 *     [agent] => xxx
 *     [order_sn] => happypay20261021131734
 *     ...
 * )
 *  返回數據
 * stdClass Object
 * (
 *     [data] => stdClass Object ( [code] => -1 [msg] => 订单異常 )
 * )
 * ```
 *
 * ⚠ **模型只負責把那坨文字變成「欄位 => 值」**，規則與錯誤碼一律查
 * `config('apply_rules')`。讓模型自己判斷「order_sn 應該幾碼」，
 * 它會編一個很合理的數字出來，而客服會照著回客人 ——
 * 跟排程設定那支同一個分工。
 *
 * ⚠ 值要**原文照抄**。長度判斷是整個診斷的核心（`bank_branch_code`
 * 11 字跟 10 字差一個 `-1`），模型「順手整理一下」就全毀。
 */
class ApplyDumpParser
{
    /** @var string 代付開單 */
    const FLOW_WITHDRAW = 'withdraw';

    /** @var string 代收開單 */
    const FLOW_DEPOSIT = 'deposit';

    /** @var string 看不出是哪一種 */
    const FLOW_UNKNOWN = 'unknown';

    const FLOWS = [
        self::FLOW_WITHDRAW,
        self::FLOW_DEPOSIT,
        self::FLOW_UNKNOWN,
    ];

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 解析一坨貼上來的開單紀錄
     *
     * @param string $text
     * @return array{flow: string, code: int|null, msg: string, fields: array<string, string>}|null
     *         null ＝ 模型不可用或解析不出來
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return null;
        }

        $config = (array) config('constants.APPLY_DIAGNOSE');

        try {
            $result = $this->matcher->generateStructured(
                strtr((string) Arr::get($config, 'PARSE_PROMPT'), [
                    '{fields}' => $this->fieldList(),
                ]),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('開單紀錄解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalize($result);
    }

    /**
     * 餵給模型的欄位清單（兩種開單的聯集）
     *
     * ⚠ 用聯集而不是分開問：`請求URL` 有時候被截掉，判不出是代收還是代付，
     * 那時候兩邊的欄位都要抓得到，才能兩套規則都比對一次。
     *
     * @return string
     */
    private function fieldList()
    {
        $names = [];

        foreach ((array) config('apply_rules.FIELDS') as $fields) {
            $names = array_merge($names, array_keys($fields));
        }

        return implode('、', array_values(array_unique($names)));
    }

    /**
     * @param array $result
     * @return array
     */
    private function normalize(array $result)
    {
        $flow = (string) Arr::get($result, 'flow');

        return [
            'flow'   => in_array($flow, self::FLOWS, true) ? $flow : self::FLOW_UNKNOWN,
            'code'   => $this->code(Arr::get($result, 'code')),
            'msg'    => trim((string) Arr::get($result, 'msg')),
            'fields' => $this->fields((array) Arr::get($result, 'fields', [])),
        ];
    }

    /**
     * 錯誤碼
     *
     * ⚠ 不能用 `filled()` 判斷有沒有值 —— 碼是數字，而 `filled(0)` 雖然是
     * true，但模型沒抓到時回的是 `null`，`(int) null` 會變成 0 而 0 不是
     * 任何一個真實的錯誤碼。所以明確比對 null。
     *
     * @param mixed $value
     * @return int|null
     */
    private function code($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * 只留規則表上有的欄位，值原文照抄（只去頭尾空白）
     *
     * ⚠ 清單外的鍵一律丟掉：模型可能把 `agent`、`sign` 也塞進來，
     * 那兩個不是我們要診斷的欄位（`sign` 還是敏感資訊）。
     *
     * @param array $fields
     * @return array<string, string>
     */
    private function fields(array $fields)
    {
        $allowed = [];

        foreach ((array) config('apply_rules.FIELDS') as $flowFields) {
            $allowed = array_merge($allowed, array_keys($flowFields));
        }

        $clean = [];

        foreach (array_unique($allowed) as $name) {
            $value = Arr::get($fields, $name);

            if ($value === null) {
                continue;
            }

            $clean[$name] = trim((string) $value);
        }

        return $clean;
    }

    /**
     * @return array
     */
    private function schema()
    {
        $properties = [];

        foreach ((array) config('apply_rules.FIELDS') as $flowFields) {
            foreach ($flowFields as $name => $spec) {
                $properties[$name] = [
                    'type'        => ['string', 'null'],
                    'description' => '原文照抄，一個字都不要改；這一坨裡沒有這個欄位就填 null',
                ];
            }
        }

        return [
            'type'       => 'object',
            'properties' => [
                'flow' => [
                    'type'        => 'string',
                    'enum'        => self::FLOWS,
                    'description' => '代付開單（withdraw）、代收開單（deposit）、看不出來（unknown）',
                ],
                'code' => [
                    'type'        => ['integer', 'null'],
                    'description' => '回傳的錯誤碼（例如 -1、-3、10002）。沒看到就填 null',
                ],
                'msg' => [
                    'type'        => ['string', 'null'],
                    'description' => '回傳的錯誤訊息（例如「订单異常」）。沒看到就填 null',
                ],
                'fields' => [
                    'type'       => 'object',
                    'properties' => $properties,
                    'required'   => array_keys($properties),
                ],
            ],
            'required' => ['flow', 'code', 'msg', 'fields'],
        ];
    }
}
