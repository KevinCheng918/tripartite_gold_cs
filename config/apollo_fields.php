<?php

/**
 * 各金流通道的 spider_setting 需要哪些欄位
 *
 * 給小幫手的「做一份 spider_setting」指令用（見 features/spider-setting-command.md）。
 *
 * ⚠ **這份表是從主專案掃出來的快照，不是即時的。**
 *
 * 來源：`tripartite_gold/app/Apollos/*Apollo.php` 裡的
 * `Arr::get($this->setting, 'xxx')`。客服系統讀不到主專案的檔案
 * （唯一的管道是每個站台的 HTTP API），所以只能存一份。
 *
 * ⚠ **主專案新增或修改通道時，這份表要回來補。** 沒補的後果是小幫手漏講
 * 某個必填欄位，或說某個通道不存在 —— 不會寫壞資料（產出只是一段文字），
 * 但會讓人以為設定齊了。重掃的方法寫在知識庫那篇裡。
 *
 * ⚠ `required` 與 `optional` 的差別來自原始碼的寫法：
 *   Arr::get($this->setting, 'app_id')            → 沒給就是 null，列為 required
 *   Arr::get($this->setting, 'currency', 'HKD')   → 有預設值，列為 optional
 *
 * 掃描日期：2026-10-19（主專案 app/Apollos 共 65 檔，其中 Apollo.php 是基底）
 */

return [

    /*
     * 所有通道共用的欄位（基底 Apollo.php 讀的）。
     *
     * ⚠ `api_url` 在 `setPaymentModel()` 就會被讀進 `$this->apiUrl`，
     * 等於每個通道都要 —— 但有些通道會在自己的方法裡覆寫，
     * 所以不強制列入必填，由各通道自己的 required 決定。
     */
    'BASE' => [
        'required' => ['api_url'],
        // 都有預設值：protocol_type 預設 HTTPS、cashier_url 預設取 api_url 的 host
        'optional' => ['protocol_type', 'cashier_url'],
    ],

    /*
     * 每個通道自己的欄位。key 是 `spider_setting.index` 的值，
     * 也就是 `Apollo::get($code)` 的那個 code。
     */
    'CHANNELS' => [
        'A8'                   => [
            'required' => ['iv', 'key', 'product_id', 'user_id'],
            'optional' => [],
        ],
        'Baoyuan'              => [
            'required' => ['agent', 'channel', 'key', 'ping_url', 'template'],
            'optional' => [],
        ],
        'Bes'                  => [
            'required' => ['iv', 'key', 'product_id'],
            'optional' => [],
        ],
        'Bgp'                  => [
            'required' => ['agent', 'check_url', 'key', 'ping_url'],
            'optional' => [],
        ],
        'Bonnyfun'             => [
            'required' => ['agent', 'key', 'ping_url'],
            'optional' => [],
        ],
        'BonnyfunVa'           => [
            'required' => ['agent', 'key'],
            'optional' => [],
        ],
        'Bridge'               => [
            'required' => ['key', 'token'],
            'optional' => ['is_show_ibon'],
        ],
        'Cashier'              => [
            'required' => ['protocol'],
            'optional' => [],
        ],
        'Ccat'                 => [
            'required' => ['api_password', 'api_url', 'api_username', 'content', 'token_url', 'virtual_code'],
            'optional' => [],
        ],
        'Chno'                 => [
            'required' => ['iv', 'key', 'username'],
            'optional' => ['apply_currency_id', 'locale'],
        ],
        'Ckpay'                => [
            'required' => ['api_url', 'app_id', 'app_key', 'check_url', 'ping_url'],
            'optional' => ['currency'],
        ],
        'Dwpay'                => [
            'required' => ['check_url', 'iv', 'key', 'ping_url', 'user_id'],
            'optional' => [],
        ],
        'Ecpay'                => [
            'required' => ['agent', 'iv', 'key'],
            'optional' => [],
        ],
        'Family'               => [
            'required' => ['api_password', 'api_url', 'api_username', 'family_cashier_url', 'tax_id', 'termino'],
            'optional' => ['cashier_url'],
        ],
        'Fangs'                => [
            'required' => ['api_key', 'api_secret', 'provider_ids', 'webhook_secret'],
            'optional' => [],
        ],
        'Fhc'                  => [
            'required' => ['HashIV', 'HashKey', 'Password', 'ValidateKey', 'ping_url'],
            'optional' => [],
        ],
        'Fuwin'                => [
            'required' => ['appSecret', 'check_url', 'key', 'merchant_no'],
            'optional' => [],
        ],
        'Gameone'              => [
            'required' => ['agent', 'check_url', 'key', 'ping_url'],
            'optional' => [],
        ],
        'Goldblue'             => [
            'required' => ['agent', 'key'],
            'optional' => [],
        ],
        'GoldblueVa'           => [
            'required' => ['agent', 'key'],
            'optional' => [],
        ],
        'Gz'                   => [
            'required' => ['hash_iv', 'hash_key', 'merchant_id'],
            'optional' => [],
        ],
        'Hp'                   => [
            'required' => ['api_url', 'key', 'uid'],
            'optional' => [],
        ],
        'Hz'                   => [
            'required' => ['agent', 'api_url', 'check_url', 'key', 'ping_url'],
            'optional' => ['pay_mode_id'],
        ],
        'Jat'                  => [
            'required' => ['iv', 'key', 'ping_url', 'product_id', 'user_id', 'validate_key', 'withdraw_password'],
            'optional' => [],
        ],
        'Jko'                  => [
            'required' => [],
            'optional' => ['overtime_minutes'],
        ],
        'Jxpay'                => [
            'required' => ['iv', 'key', 'ping_url', 'user_id'],
            'optional' => [],
        ],
        'JxpayCashier'         => [
            'required' => ['iv', 'key', 'ping_url', 'real_name', 'user_id'],
            'optional' => [],
        ],
        'King'                 => [
            'required' => ['MerchantNumber', 'iv', 'key', 'ping_url', 'product_id', 'validate_key', 'withdraw_password'],
            'optional' => [],
        ],
        'Kpay'                 => [
            'required' => ['api_url', 'code', 'key', 'merchant', 'ping_url'],
            'optional' => [],
        ],
        'Liuba'                => [
            'required' => ['Apis', 'HashID', 'HashKey', 'api_url'],
            'optional' => [],
        ],
        'Lnw'                  => [
            'required' => ['api_url', 'check_url', 'prefix', 'token', 'token_url'],
            'optional' => [],
        ],
        'Luckypay'             => [
            'required' => ['agent', 'key'],
            'optional' => [],
        ],
        'Maxhk'                => [
            'required' => ['SecureIV', 'SecureKey', 'api_url', 'check_url', 'key', 'ping_url', 'sn'],
            'optional' => ['fps_bank_name'],
        ],
        'Mm'                   => [
            'required' => ['iv', 'key', 'product_id', 'user_id'],
            'optional' => [],
        ],
        'Mt'                   => [
            'required' => ['iv', 'key', 'token'],
            'optional' => ['bank_id'],
        ],
        'Mycash'               => [
            'required' => ['choose_payment', 'iv', 'key', 'product_id'],
            'optional' => ['cashier_url'],
        ],
        'NewMm'                => [
            'required' => ['iv', 'key', 'merchant_id', 'ping_url', 'token', 'withdraw_type'],
            'optional' => ['bank_id'],
        ],
        'NewMt'                => [
            'required' => ['iv', 'key', 'product_id', 'user_id'],
            'optional' => [],
        ],
        'Onepay'               => [
            'required' => ['iv', 'key', 'ping_url', 'return_url', 'user_id'],
            'optional' => ['channel'],
        ],
        'OnepayCashier'        => [
            'required' => ['iv', 'key', 'ping_url', 'real_name', 'user_id'],
            'optional' => ['channel'],
        ],
        'Plus'                 => [
            'required' => ['api_url', 'appid', 'check_url', 'key', 'ping_url'],
            'optional' => [],
        ],
        'RbCashier'            => [
            'required' => ['iv', 'key', 'pay_bank', 'username'],
            'optional' => [],
        ],
        'Sbs'                  => [
            'required' => ['key'],
            'optional' => [],
        ],
        'Self'                 => [
            'required' => ['agent', 'check_url', 'key', 'ping_url'],
            'optional' => [],
        ],
        'SelfCashier'          => [
            'required' => ['agent', 'key', 'ping_url'],
            'optional' => [],
        ],
        'SelfHold'             => [
            'required' => [],
            'optional' => [],
        ],
        'Speed'                => [
            'required' => ['api_key', 'category_code', 'merchant_code'],
            'optional' => [],
        ],
        'Sunny'                => [
            'required' => ['iv', 'key', 'merchant_account', 'payment_method_key', 'ping_url', 'product_id', 'secret', 'store_user_name', 'store_user_phone', 'withdraw_password'],
            'optional' => [],
        ],
        'Tcs'                  => [
            'required' => ['api_url', 'key', 'ping_url', 'user_account'],
            'optional' => [],
        ],
        'Teimshen'             => [
            'required' => ['agent', 'key', 'ping_url'],
            'optional' => [],
        ],
        'TeimshenCashier'      => [
            'required' => ['agent', 'key'],
            'optional' => ['template'],
        ],
        'ThaCashier'           => [
            'required' => [],
            'optional' => ['template'],
        ],
        'Totoken'              => [
            'required' => ['cashier_url', 'currency', 'key'],
            'optional' => [],
        ],
        'Trc20Usdt'            => [
            'required' => ['rate', 'rate_default', 'rate_edit', 'rate_keep_sec'],
            'optional' => [],
        ],
        'Treasure'             => [
            'required' => ['check_url', 'clientCode', 'iv', 'key'],
            'optional' => [],
        ],
        'TwoOneCashier'        => [
            'required' => ['SecureIV', 'SecureKey', 'api_url', 'check_url', 'key', 'ping_url', 'sn'],
            'optional' => [],
        ],
        'X'                    => [
            'required' => ['iv', 'key', 'ping_url', 'user_id'],
            'optional' => [],
        ],
        'XCashier'             => [
            'required' => ['channel', 'iv', 'key', 'real_name', 'user_account', 'user_id'],
            'optional' => [],
        ],
        'Xigua'                => [
            'required' => ['api_url', 'key', 'mchId', 'wayCode'],
            'optional' => [],
        ],
        'Xin'                  => [
            'required' => ['agent', 'channel_id', 'check_url', 'key', 'ping_url'],
            'optional' => [],
        ],
        'XinVa'                => [
            'required' => ['agent', 'channel_id', 'key', 'ping_url'],
            'optional' => [],
        ],
        'Xingxin'              => [
            'required' => ['agent', 'iv', 'key', 'payment_type', 'ping_url'],
            'optional' => [],
        ],
        'XingxinCashier'       => [
            'required' => ['agent', 'iv', 'key', 'payment_type'],
            'optional' => [],
        ],
        'Yongxin'              => [
            'required' => ['api_key', 'api_url', 'check_url', 'client_id', 'phone', 'ping_url', 'user_id_number'],
            'optional' => [],
        ],
    ],

];
