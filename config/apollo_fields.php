<?php

/**
 * 金流通道 spider_setting（＝團隊口語的「排程」）的欄位對照表
 *
 * ⚠ **這是快照，不是即時的。** 來源是主專案
 * `tripartite_gold/app/Apollos/*Apollo.php` 裡的 `Arr::get($this->setting, 'xxx')`
 * ——有給預設值的算選填、沒給的算必填。客服系統**讀不到主專案的檔案**
 * （不同部署，唯一管道是站台的 HTTP API），所以做不到即時讀取。
 * 主專案新增通道或改欄位名之後，這份要**重新掃一次**。
 *
 * ⚠ **欄位分代收／代付／共用**（需求方 2026-10-19：「有時候會只需要代收，
 * 有時候只需要代付，有時候兩個都要」）。分界是**方法名**：
 * - `deposit`：`getPay*`、`verifySign`（回調）、`getRate`、收銀台相關
 * - `withdraw`：方法名含 withdraw（`generateWithdrawRecord`、`sendWithdraw`、
 *   `checkWithdrawStatus`）
 * - `shared`：建構子、`initSet()`、`getBalance()` 等兩種都會走到的
 *
 * 一個鍵同時出現在代收與代付的方法裡就歸 `shared`。
 *
 * 掃描時間：2026-10-19
 *
 * @see .claude/knowledge/features/spider-setting-command.md
 */
return [
    /*
     * `Apollo.php` 自己讀的 —— **每一個通道都適用**，所以產出時一律併進去。
     *
     * ⚠ 第一版漏了這一段（需求方 2026-10-19 抓到：「api_url 這些都要填寫
     * 上去」）—— 只列了通道自己的欄位，而 `api_url` 是基底在讀的，
     * 結果產出的排程少了最基本的那一個。
     */
    'BASE' => [
        'shared' => [
            'required' => ['api_url'],
            'optional' => ['protocol_type'],
        ],
        // 收銀台網址只有代收會用到
        'deposit' => [
            'required' => [],
            'optional' => ['cashier_url'],
        ],
    ],

    /*
     * 不是 Apollo 讀的，但實際上會從 spider_setting 拿的鍵。
     *
     * ⚠ 第一版整段漏掉：掃描只看了 `app/Apollos`，而這些是
     * Controller／Trait／Blade 在讀的（`order_group` 就是需求方點出來的）。
     */
    'EXTRA' => [
        'deposit' => [
            'optional' => [
                // 上游開單時另外收取的手續費，扣掉才是要匹配的入帳金額
                'amount_deduct',
                // 1 ＝ 公司戶，2 ＝ 個人數位戶
                'company',
                // 防詐驗證頁顯示的客服聯絡方式
                'contact',
                // 繳款截圖逾時告警的秒數，0 或沒填 ＝ 不告警
                'image_alert_seconds',
                // 收銀台不顯示的欄位（例如 overtime_at）
                'not_show',
                // 收銀台「注意事項」的文案，用「。」分行
                'note',
                /*
                 * 通道接受一次送多筆匯款帳號（`"1"` 啟用）。
                 * 團隊口語叫**「同單多組」**（需求方 2026-10-19）。
                 *
                 * ⚠ **不開就連這個 key 都不要寫**（需求方 2026-10-19）——
                 * 所以它是選填，不是「一律寫進去留空」。
                 * 讀它的是 `WebDeposit` 與 `PaymentController::ajaxCreateDeposit()`，
                 * 兩邊都只看 `filled()`。
                 */
                'order_group',
            ],
        ],
    ],

    /*
     * 口語講法 => 對照表代號。
     *
     * ⚠ 需求方把自家主系統那一組叫「**selfpay**」（2026-10-20），但對照表上
     * 沒有這個代號（檔案是 `SelfApollo`）—— 實測模型遇到「selfpay 的排程」
     * 會回 `channel: null`，變成「我聽不懂你要哪個通道」。
     *
     * 這份會同時餵進 prompt（讓模型對得到）**也會**在比對時再查一次
     * （萬一模型照抄別名）—— 兩道都做，因為漏接的下場是功能叫不出來。
     */
    'ALIASES' => [
        'selfpay' => 'Self',
    ],

    /*
     * 同一件事在代收與代付用不同的 index。
     *
     * ⚠ **自家主系統（self）固定是：代收走 `self_cashier`、代付走 `self`**
     * （需求方 2026-10-20）。所以不管他說的是 self 還是 self_cashier，
     * 產出時都要按這張表換成對的那一個 —— 兩個代號的欄位本來就不一樣
     * （`self` 有代付的 `check_url`、`self_cashier` 沒有），用錯就接不起來。
     *
     * 格式：對照表代號 => [代收用哪個, 代付用哪個]
     */
    'SCOPE_INDEX' => [
        'Self' => [
            'deposit'  => 'SelfCashier',
            'withdraw' => 'Self',
        ],
        'SelfCashier' => [
            'deposit'  => 'SelfCashier',
            'withdraw' => 'Self',
        ],
    ],

    /*
     * 可以從 `api_url` 推出來的欄位（後綴）。
     *
     * ⚠ **自家主系統的這兩個網址是固定的**（需求方 2026-10-20：「一定要對」）：
     * - `check_url` ＝ `api_url` ＋ `/api/withdraw/info`
     *   → `routes/api.php` 的 `withdraw/info`（`PaymentController@withdrawInfo`），
     *     `SelfApollo::checkWithdrawStatus()` 打的就是它
     * - `ping_url` ＝ `api_url` ＋ `/api/agent/info`
     *   → `routes/api.php` 的 `agent/info`（`UserController@apiAuthInfo`），
     *     `SelfApollo::getBalance()` 打的就是它
     *
     * ⚠ 這種能算出來的東西**不要讓人手打** —— 打錯一個字母，代付查單會一直
     * 查不到而看起來像上游沒回應。
     *
     * ⚠ 只有**這個通道的那一種真的有這個欄位**時才會填（例如
     * `self_cashier` 是代收、沒有 `check_url`，就不會冒出來）。
     */
    'DERIVED' => [
        'Self' => [
            'check_url' => '/api/withdraw/info',
            'ping_url'  => '/api/agent/info',
        ],
        'SelfCashier' => [
            'ping_url' => '/api/agent/info',
        ],
    ],

    /*
     * 固定值的欄位：這個通道這一欄就是這個值，不必問人。
     *
     * ⚠ `self_cashier` 的 `not_show` 固定 `overtime_at`
     * （需求方 2026-10-20）。主專案建立代收通道時的基底
     * （`PaymentController::resolveSpiderSetting()`）與 Gmail 憑證產出的
     * `CASHIER_NOT_SHOW` 都是這個值 —— 收銀台不要顯示逾時時間。
     *
     * ⚠ 跟 `DERIVED` 一樣只在**那一種真的有這個欄位**時才填。
     */
    'FIXED' => [
        'SelfCashier' => [
            'not_show' => 'overtime_at',
        ],
    ],

    /*
     * 開關型欄位：值只有「有」與「沒有」兩種意義。
     *
     * ⚠ **這些欄位的值不能照抄人話**。主系統判斷它們只用
     * `filled()`／`blank()`，所以 `"order_group": "開"` 也會被當成啟用
     * ——「能跑」但資料是錯的，下一個看到的人會以為可以填中文。
     * 實測模型真的會把「同單多組要開」抄成 `"開"`，所以在解析器那一側
     * 一律改寫成 `"1"`，不靠 prompt 自律。
     */
    'FLAGS' => ['order_group'],

    /*
     * 幾個代號需要解釋，不然看名字猜不到差別。
     *
     * key 用對照表的代號（類別名），不是 snake 的 index。
     */
    'NOTES' => [
        'Self'        => '自家主系統：代收固定用 self_cashier、代付固定用 self',
        'SelfCashier' => '自家主系統，自行包裝收銀台畫面 ＝ 用別人的收銀台',
        'SelfHold'    => '代付專用：開單直接進「等待重送」，不讀任何設定',
        'Cashier'     => '建立代收通道時的預設 index，Gmail 爬蟲也掛在它底下',
    ],

    /*
     * 哪些通道做代收、哪些做代付。
     *
     * 判斷依據是**有沒有那一種方法**：有 `getPay*`／`generateDepositRecord`／
     * `getRate` ＝ 做代收；有含 withdraw 的方法 ＝ 做代付。
     *
     * ⚠ 這份存在的理由是**不要產出沒用的東西**：有人問「Sbs 的代收排程」時，
     * 照樣組一份出來的話，他會拿著一份這個通道根本不做的設定去貼。
     *
     * ⚠ 上游常把代收與代付當成兩個產品（`Teimshen` 只做代付、
     * `TeimshenCashier` 只做代收），所以「同一家只有一種」是常態，不是掃錯。
     */
    'TYPES' => [
        // 只做代收（31 個）
        'deposit' => [
            'A8', 'Bes', 'BonnyfunVa', 'Bridge', 'Cashier', 'Ccat', 'Chno', 'Ecpay',
            'Family', 'Fangs', 'Goldblue', 'GoldblueVa', 'Hp', 'Jko', 'JxpayCashier',
            'Luckypay', 'Mm', 'Mt', 'Mycash', 'NewMt', 'OnepayCashier', 'RbCashier',
            'SelfCashier', 'Speed', 'TeimshenCashier', 'ThaCashier', 'Totoken',
            'XCashier', 'Xigua', 'XinVa', 'XingxinCashier',
        ],

        // 只做代付（12 個）
        'withdraw' => [
            'Bgp', 'Dwpay', 'Fhc', 'Fuwin', 'Jxpay', 'Kpay', 'Sbs', 'SelfHold',
            'Teimshen', 'Treasure', 'X', 'Xingxin',
        ],

        // 兩種都做（21 個）
        'both' => [
            'Baoyuan', 'Bonnyfun', 'Ckpay', 'Gameone', 'Gz', 'Hz', 'Jat', 'King',
            'Liuba', 'Lnw', 'Maxhk', 'NewMm', 'Onepay', 'Plus', 'Self', 'Sunny',
            'Tcs', 'Trc20Usdt', 'TwoOneCashier', 'Xin', 'Yongxin',
        ],
    ],

    /*
     * 64 個通道。
     *
     * ⚠ `Apollo` 本身不是通道（它是基底，見上面的 `BASE`）。
     */
    'CHANNELS' => [
        'A8' => [
            'deposit' => ['required' => ['iv', 'key', 'product_id', 'user_id'], 'optional' => []],
        ],
        'Baoyuan' => [
            'shared'  => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
            'deposit' => ['required' => ['channel', 'template'], 'optional' => []],
        ],
        'Bes' => [
            'deposit' => ['required' => ['iv', 'key', 'product_id'], 'optional' => []],
        ],
        'Bgp' => [
            'shared'   => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'Bonnyfun' => [
            'shared' => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
        ],
        'BonnyfunVa' => [
            'shared'  => ['required' => ['key'], 'optional' => []],
            'deposit' => ['required' => ['agent'], 'optional' => []],
        ],
        'Bridge' => [
            'shared'  => ['required' => ['token'], 'optional' => []],
            'deposit' => ['required' => ['key'], 'optional' => ['is_show_ibon']],
        ],
        'Cashier' => [
            'deposit' => ['required' => ['protocol'], 'optional' => []],
        ],
        'Ccat' => [
            'shared'  => ['required' => ['api_password', 'api_username'], 'optional' => []],
            'deposit' => ['required' => ['api_url', 'content', 'token_url', 'virtual_code'], 'optional' => []],
        ],
        'Chno' => [
            'shared'  => ['required' => ['iv', 'key'], 'optional' => []],
            'deposit' => ['required' => ['username'], 'optional' => ['apply_currency_id', 'locale']],
        ],
        'Ckpay' => [
            'shared'   => ['required' => ['api_url', 'app_id', 'app_key', 'ping_url'], 'optional' => ['currency']],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'Dwpay' => [
            'shared'   => ['required' => ['iv', 'key', 'ping_url', 'user_id'], 'optional' => []],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'Ecpay' => [
            'shared' => ['required' => ['agent', 'iv', 'key'], 'optional' => []],
        ],
        'Family' => [
            'deposit' => ['required' => ['api_password', 'api_url', 'api_username', 'family_cashier_url', 'tax_id', 'termino'], 'optional' => ['cashier_url']],
        ],
        'Fangs' => [
            'shared'  => ['required' => ['api_key', 'api_secret', 'webhook_secret'], 'optional' => []],
            'deposit' => ['required' => ['provider_ids'], 'optional' => []],
        ],
        'Fhc' => [
            'shared'   => ['required' => ['HashIV', 'HashKey', 'ValidateKey', 'ping_url'], 'optional' => []],
            'withdraw' => ['required' => ['Password'], 'optional' => []],
        ],
        'Fuwin' => [
            'shared'   => ['required' => ['key'], 'optional' => []],
            'withdraw' => ['required' => ['appSecret', 'check_url', 'merchant_no'], 'optional' => []],
        ],
        'Gameone' => [
            'shared'   => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'Goldblue' => [
            'shared'  => ['required' => ['key'], 'optional' => []],
            'deposit' => ['required' => ['agent'], 'optional' => []],
        ],
        'GoldblueVa' => [
            'shared'  => ['required' => ['key'], 'optional' => []],
            'deposit' => ['required' => ['agent'], 'optional' => []],
        ],
        'Gz' => [
            'shared' => ['required' => ['hash_iv', 'hash_key', 'merchant_id'], 'optional' => []],
        ],
        'Hp' => [
            'shared'  => ['required' => ['key'], 'optional' => []],
            'deposit' => ['required' => ['api_url', 'uid'], 'optional' => []],
        ],
        'Hz' => [
            'shared'   => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
            'deposit'  => ['required' => ['api_url'], 'optional' => ['pay_mode_id']],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'Jat' => [
            'shared'   => ['required' => ['iv', 'key', 'ping_url', 'validate_key'], 'optional' => []],
            'deposit'  => ['required' => ['product_id', 'user_id'], 'optional' => []],
            'withdraw' => ['required' => ['withdraw_password'], 'optional' => []],
        ],
        'Jko' => [
            'shared' => ['required' => [], 'optional' => ['overtime_minutes']],
        ],
        'Jxpay' => [
            'shared' => ['required' => ['iv', 'key', 'ping_url', 'user_id'], 'optional' => []],
        ],
        'JxpayCashier' => [
            'shared'  => ['required' => ['iv', 'key', 'ping_url', 'user_id'], 'optional' => []],
            'deposit' => ['required' => ['real_name'], 'optional' => []],
        ],
        'King' => [
            'shared'   => ['required' => ['iv', 'key', 'ping_url'], 'optional' => []],
            'deposit'  => ['required' => ['product_id'], 'optional' => []],
            'withdraw' => ['required' => ['MerchantNumber', 'validate_key', 'withdraw_password'], 'optional' => []],
        ],
        'Kpay' => [
            'shared'   => ['required' => ['key', 'merchant', 'ping_url'], 'optional' => []],
            'withdraw' => ['required' => ['api_url', 'code'], 'optional' => []],
        ],
        'Liuba' => [
            'shared' => ['required' => ['Apis', 'HashID', 'HashKey', 'api_url'], 'optional' => []],
        ],
        'Lnw' => [
            'shared'   => ['required' => ['api_url', 'prefix', 'token', 'token_url'], 'optional' => []],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'Luckypay' => [
            'deposit' => ['required' => ['agent', 'key'], 'optional' => []],
        ],
        'Maxhk' => [
            'shared'   => ['required' => ['SecureIV', 'SecureKey', 'api_url', 'key', 'ping_url', 'sn'], 'optional' => []],
            'deposit'  => ['required' => [], 'optional' => ['fps_bank_name']],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'Mm' => [
            'deposit' => ['required' => ['iv', 'key', 'product_id', 'user_id'], 'optional' => []],
        ],
        'Mt' => [
            'shared'  => ['required' => ['iv', 'key'], 'optional' => []],
            'deposit' => ['required' => ['token'], 'optional' => ['bank_id']],
        ],
        'Mycash' => [
            'deposit' => ['required' => ['choose_payment', 'iv', 'key', 'product_id'], 'optional' => ['cashier_url']],
        ],
        'NewMm' => [
            'shared'   => ['required' => ['iv', 'key', 'merchant_id', 'ping_url', 'token'], 'optional' => []],
            'deposit'  => ['required' => [], 'optional' => ['bank_id']],
            'withdraw' => ['required' => ['withdraw_type'], 'optional' => []],
        ],
        'NewMt' => [
            'deposit' => ['required' => ['iv', 'key', 'product_id', 'user_id'], 'optional' => []],
        ],
        'Onepay' => [
            'shared'  => ['required' => ['iv', 'key', 'ping_url', 'user_id'], 'optional' => []],
            'deposit' => ['required' => ['return_url'], 'optional' => ['channel']],
        ],
        'OnepayCashier' => [
            'shared'  => ['required' => ['iv', 'key', 'ping_url', 'user_id'], 'optional' => []],
            'deposit' => ['required' => ['real_name'], 'optional' => ['channel']],
        ],
        'Plus' => [
            'shared'   => ['required' => ['api_url', 'appid', 'key', 'ping_url'], 'optional' => []],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'RbCashier' => [
            'shared'  => ['required' => ['iv', 'key'], 'optional' => []],
            'deposit' => ['required' => ['pay_bank', 'username'], 'optional' => []],
        ],
        'Sbs' => [
            'withdraw' => ['required' => ['key'], 'optional' => []],
        ],
        'Self' => [
            'shared'   => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'SelfCashier' => [
            'shared' => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
        ],
        /*
         * ⚠ 一個欄位都不讀（只有 `generateWithdrawRecord()`，把單設成
         * 等待重送）。所以產出只會有 `index` —— 那是正確的，不是掃漏了。
         */
        'SelfHold' => [
            'withdraw' => ['required' => [], 'optional' => []],
        ],
        'Speed' => [
            'shared'  => ['required' => ['api_key'], 'optional' => []],
            'deposit' => ['required' => ['category_code', 'merchant_code'], 'optional' => []],
        ],
        'Sunny' => [
            'shared'   => ['required' => ['iv', 'key', 'merchant_account', 'ping_url', 'secret'], 'optional' => []],
            'deposit'  => ['required' => ['payment_method_key', 'product_id', 'store_user_name', 'store_user_phone'], 'optional' => []],
            'withdraw' => ['required' => ['withdraw_password'], 'optional' => []],
        ],
        'Tcs' => [
            'shared' => ['required' => ['api_url', 'key', 'ping_url', 'user_account'], 'optional' => []],
        ],
        'Teimshen' => [
            'shared' => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
        ],
        'TeimshenCashier' => [
            'shared'  => ['required' => ['agent', 'key'], 'optional' => []],
            'deposit' => ['required' => [], 'optional' => ['template']],
        ],
        'ThaCashier' => [
            'deposit' => ['required' => [], 'optional' => ['template']],
        ],
        'Totoken' => [
            'deposit' => ['required' => ['cashier_url', 'currency', 'key'], 'optional' => []],
        ],
        'Trc20Usdt' => [
            'deposit' => ['required' => ['rate', 'rate_default', 'rate_edit', 'rate_keep_sec'], 'optional' => []],
        ],
        'Treasure' => [
            'shared'   => ['required' => ['iv', 'key'], 'optional' => []],
            'withdraw' => ['required' => ['check_url', 'clientCode'], 'optional' => []],
        ],
        'TwoOneCashier' => [
            'shared'   => ['required' => ['SecureIV', 'SecureKey', 'api_url', 'key', 'ping_url', 'sn'], 'optional' => []],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'X' => [
            'shared' => ['required' => ['iv', 'key', 'ping_url', 'user_id'], 'optional' => []],
        ],
        'XCashier' => [
            'shared'  => ['required' => ['key'], 'optional' => []],
            'deposit' => ['required' => ['channel', 'iv', 'real_name', 'user_account', 'user_id'], 'optional' => []],
        ],
        'Xigua' => [
            'shared'  => ['required' => ['key'], 'optional' => []],
            'deposit' => ['required' => ['api_url', 'mchId', 'wayCode'], 'optional' => []],
        ],
        'Xin' => [
            'shared'   => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
            'deposit'  => ['required' => ['channel_id'], 'optional' => []],
            'withdraw' => ['required' => ['check_url'], 'optional' => []],
        ],
        'XinVa' => [
            'shared'  => ['required' => ['agent', 'key', 'ping_url'], 'optional' => []],
            'deposit' => ['required' => ['channel_id'], 'optional' => []],
        ],
        'Xingxin' => [
            'shared'   => ['required' => ['agent', 'iv', 'key', 'ping_url'], 'optional' => []],
            'withdraw' => ['required' => ['payment_type'], 'optional' => []],
        ],
        'XingxinCashier' => [
            'shared'  => ['required' => ['iv', 'key'], 'optional' => []],
            'deposit' => ['required' => ['agent', 'payment_type'], 'optional' => []],
        ],
        'Yongxin' => [
            'shared'   => ['required' => ['api_key', 'api_url', 'client_id', 'ping_url', 'user_id_number'], 'optional' => []],
            'withdraw' => ['required' => ['check_url', 'phone'], 'optional' => []],
        ],
    ],
];
