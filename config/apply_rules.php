<?php

/**
 * 開單 API 的欄位規則與錯誤碼（快照）
 *
 * 客人貼開單 request 過來時，照這份表逐條預排查。
 *
 * ⚠ **這是快照，不是即時的。** 來源是主專案：
 * - `app/Http/Requests/ApiWithdrawApplyRequest.php`（代付的驗證規則與錯誤碼對應）
 * - `app/Http/Requests/ApiDepositApplyRequest.php`（代收）
 * - `config/errors.php`（錯誤碼）
 * - `database/migrations/*_create_withdraw_records_table.php`（資料庫欄位長度）
 * - `database/migrations/*_create_deposit_records_table.php`
 *
 * 客服系統讀不到主專案的檔案（不同部署），主專案改了驗證規則或欄位長度，
 * 這邊要**重新掃一次**。
 *
 * ⚠⚠ **一定要兩層都查：`rule` 與 `db`。**
 *
 * 這是整份表存在的理由。`-1 订单異常` 是 controller 最外層
 * `catch (\Exception)` 掉出來的通用錯誤 —— 欄位驗證失敗都有各自的專屬碼
 * （`-3`、`-4`、`-18`…），所以**只看驗證規則永遠解釋不了 `-1`**。
 *
 * 真正的 `-1` 來源是**驗證放行、但寫不進資料庫**的那段落差：
 * 例如 `bank_branch_code` 驗 `max:15` 而欄位是 `varchar(10)` ——
 * 11~15 個字過得了驗證，`INSERT` 時爆掉，拋的是 PDO 例外不是
 * `ApiErrorException`，於是變成 `-1`。
 *
 * 需求方 2026-10-21 提供的實例就是這一種：`bank_branch_code` 填了
 * 「台北富邦鼓山分行(6823)」共 14 字（正確值是 `6823`）。
 *
 * 掃描時間：2026-10-21
 *
 * @see .claude/knowledge/features/apply-error-diagnose.md
 */
return [
    /*
     * 從 `請求URL` 認出是哪一種開單。
     *
     * ⚠ 代收與代付的規則**不一樣**（最明顯的是 `order_sn` 的正則），
     * 判錯邊就會給出完全錯誤的診斷。
     */
    'ENDPOINTS' => [
        '/api/withdraw' => 'withdraw',
        '/api/deposit'  => 'deposit',
    ],

    'FLOW_LABEL' => [
        'withdraw' => '代付開單',
        'deposit'  => '代收開單',
    ],

    /*
     * 錯誤碼的意義。
     *
     * ⚠ `-1` 要特別講明「不是欄位驗證失敗」，不然看的人會照著欄位去改，
     * 改完還是一樣錯。
     */
    'CODES' => [
        'withdraw' => [
            -1  => '通用錯誤（订单異常）',
            -2  => '商戶訂單號已存在',
            -3  => '商戶訂單號格式異常',
            -4  => '金額異常',
            -5  => '銀行名稱必填',
            -6  => '銀行代碼必填',
            -7  => '銀行帳號必填',
            -8  => '商戶餘額不足',
            -9  => '分行名稱必填',
            -10 => '分行代碼必填',
            -11 => '姓名必填',
            -12 => '身分證必填',
            -13 => '電話必填',
            -14 => '銀行名稱格式異常',
            -15 => '銀行代碼格式異常',
            -16 => '銀行帳號格式異常',
            -17 => '分行名稱格式異常',
            -18 => '分行代碼格式異常',
            -19 => '電話格式異常',
        ],
        'deposit' => [
            -1  => '通用錯誤（订单異常）',
            -2  => '商戶訂單號已存在',
            -3  => '商戶訂單號格式異常',
            -4  => '金額異常',
            -5  => '銀行名稱必填',
            -6  => '銀行代碼必填',
            -7  => '銀行帳號必填',
            -8  => '姓名必填',
            -9  => '繳費內容必填',
            -10 => '類型異常',
            -11 => '超商繳費類型異常',
            -12 => '同單多組銀行資料異常',
            -13 => '付款人電話必填',
            -14 => '身分證必填',
            -15 => '付款人 email 必填',
            -16 => '付款人唯一識別碼必填',
            -17 => '付款人 IP 必填',
            -18 => '付款人地址必填',
            -19 => '分行代碼必填',
            -20 => '超商繳費代碼必填',
            -21 => '分行名稱必填',
        ],
    ],

    /*
     * 不在上面兩張表裡的（中介層與通用的 `ApiErrorException`）。
     *
     * ⚠ 這些**也不是欄位問題**，跟 `-1` 一樣不該叫客人去改欄位。
     */
    'GLOBAL_CODES' => [
        10001 => '加簽異常（sign 算錯，或 api_key 不對）',
        10002 => '代理尚未授權',
        10003 => '支付異常（取不到可用通道，或金額不在通道區間）',
        10004 => '請求頻率異常',
        10005 => '黑名單異常資料',
        10006 => '代收類型未開啟',
        10007 => '金額異常',
        10008 => '欄位異常',
    ],

    /*
     * 「通用錯誤」有哪些 —— 這幾個碼一律不報欄位問題，只給客服看。
     */
    'GENERIC_CODES' => [-1, 10003, 10004, 10005],

    /*
     * 欄位規則。
     *
     * 每個欄位：
     * - `label`     ：對客人講的名字
     * - `required`  ：必填（`conditional` ＝ 看類型／設定才必填，不當成缺漏報）
     * - `regex`     ：格式
     * - `max`       ：Laravel 的長度上限（違規 → `code_format`）
     * - `db`        ：資料庫欄位上限（**過驗證但超過這個就會變 -1**）
     * - `db_type`   ：`date` 這種要檢查格式的
     * - `code_blank`／`code_format`：違規時 API 會回的碼
     * - `doc_max`／`doc_example`／`doc_hint`：**商戶 API 文件上寫的**規格
     *
     * ⚠⚠ **跟客人講的一律是 `doc_*`，不是 `max` 也不是 `db`**
     * （需求方 2026-10-21：「應該要回文件上寫的長度是多少，然後範例是什麼」）。
     *
     * 三個數字常常不一樣，而**文件才是雙方的合約**：
     *
     * | | 文件 | Laravel | 資料庫 |
     * |---|---|---|---|
     * | `bank_branch_code` | **10** | 15 | 10 |
     *
     * 文件寫 10、資料庫也是 10 —— 中間那個 `max:15` 才是異常值。
     * 所以客人填 14 碼是**他違反文件規格**，不是我們的問題：
     * 要回「文件上限 10 碼、範例 0015」，不是「看起來應該是 6823」
     * （那樣講像是我們在幫他猜，還顯得系統有毛病）。
     *
     * 來源：`resources/views/docs/master.blade.php`（代收段與代付段各一份表）。
     *
     * ⚠ `max` 比 `db` 大的欄位，中間那段就是「會變 -1」的區間。
     * ⚠ 沒有 `max` 但有 `db` 的欄位（`user_name`、`user_id_number`…）
     *   **整個驗證層都沒管**，超長一律 -1。
     */
    'FIELDS' => [
        'withdraw' => [
            'order_sn' => [
                'label'       => '商戶訂單號',
                'required'    => true,
                'regex'       => '/^[A-Za-z0-9_]{10,50}$/',
                // ⚠ 文件寫的是「下底線(_)」，代收那邊是「連字號(-)」
                'doc_hint'    => '長度 10~50，只能用英文(a-zA-Z)、數字(0-9)、下底線(_)',
                'doc_example' => 'AD1234567890',
                'db'          => 255,
                'code_blank'  => -3,
                'code_format' => -3,
            ],
            'amount' => [
                'label'       => '金額',
                'required'    => true,
                'regex'       => '/^\d+(\.\d{1,6})?$/',
                'doc_hint'    => '僅允許整數，額度範圍請參考後台設定',
                'doc_example' => '1000',
                'code_blank'  => -4,
                'code_format' => -4,
            ],
            'bank_name' => [
                'label'       => '銀行名稱',
                'required'    => true,
                'max'         => 30,
                'db'          => 50,
                'doc_max'     => 30,
                'doc_example' => '中國信託',
                'code_blank'  => -5,
                'code_format' => -14,
            ],
            'bank_code' => [
                'label'       => '銀行代碼',
                'required'    => true,
                'max'         => 10,
                'db'          => 10,
                'doc_max'     => 10,
                'doc_example' => '822',
                'code_blank'  => -6,
                'code_format' => -15,
            ],
            'bank_account' => [
                'label'       => '銀行帳號',
                'required'    => true,
                'max'         => 50,
                'db'          => 50,
                'doc_max'     => 50,
                'doc_example' => '000011112222',
                'code_blank'  => -7,
                'code_format' => -16,
            ],
            'bank_branch_name' => [
                'label'       => '分行名稱',
                'max'         => 30,
                'db'          => 30,
                'doc_max'     => 30,
                'doc_example' => '敦北分行',
                'code_format' => -17,
            ],
            /*
             * ⚠ **這就是需求方那個案例的兇手。**
             *
             * 文件 10、Laravel `max:15`、欄位 `varchar(10)` ——
             * 11~15 字過得了驗證但插不進去，於是回通用的 `-1`。
             * 跟客人講的是**文件的 10**（他違反的是文件規格）。
             */
            'bank_branch_code' => [
                'label'       => '分行代碼',
                'max'         => 15,
                'db'          => 10,
                'doc_max'     => 10,
                'doc_example' => '0015',
                'code_format' => -18,
                'expect'      => 'code',
            ],
            'user_phone' => [
                'label'       => '電話',
                'max'         => 20,
                'db'          => 50,
                'doc_max'     => 20,
                'doc_example' => '0912345678',
                'code_format' => -19,
            ],
            // 以下幾個驗證層完全沒管長度，超過資料庫上限就是 -1
            'user_name' => [
                'label' => '姓名',
                'db'    => 50,
            ],
            'user_id_number' => [
                'label' => '身分證',
                'db'    => 20,
            ],
            'user_birthday' => [
                'label'       => '生日',
                'db_type'     => 'date',
                // 文件寫的型別是 date('Y-m-d')
                'doc_hint'    => '日期格式 Y-m-d',
                'doc_example' => '1990-03-15',
            ],
            'client_ip' => [
                'label' => '請求 IP',
                'db'    => 50,
            ],
            'notify_url' => [
                'label' => '回調網址',
                'db'    => 255,
            ],
            'note' => [
                'label' => '備註',
                'db'    => 255,
            ],
        ],

        'deposit' => [
            /*
             * ⚠ **代收允許 `-`、代付允許 `_`** —— 兩邊都回 `-3`，看錯誤碼看不出
             * 差別。同一組訂單號很可能代收過得去、代付過不去。
             */
            'order_sn' => [
                'label'       => '商戶訂單號',
                'required'    => true,
                'regex'       => '/^[A-Za-z0-9\-]{10,50}$/',
                // ⚠ 代收文件寫的是「連字號(-)」，跟代付的「下底線(_)」不同
                'doc_hint'    => '長度 10~50，只能用英文(a-zA-Z)、數字(0-9)、連字號(-)',
                'doc_example' => 'AD1234567890',
                'db'          => 100,
                'code_blank'  => -3,
                'code_format' => -3,
            ],
            'amount' => [
                'label'       => '金額',
                'required'    => true,
                'regex'       => '/^\d+(\.\d{1,6})?$/',
                'doc_hint'    => '僅允許整數，額度範圍請參考後台設定',
                'doc_example' => '1000',
                'code_blank'  => -4,
                'code_format' => -4,
            ],
            'bank_account' => [
                'label'       => '銀行帳號',
                'regex'       => '/^\d+$/',
                'doc_hint'    => '只能是數字',
                'doc_max'     => 50,
                'doc_example' => '000011112222',
                'db'          => 255,
                'code_format' => -7,
            ],
            'bank_name' => [
                'label'       => '銀行名稱',
                'max'         => 30,
                'db'          => 50,
                'doc_max'     => 30,
                'doc_example' => '中國信託',
                'code_format' => -5,
            ],
            'bank_code' => [
                'label'       => '銀行代碼',
                'max'         => 10,
                'db'          => 10,
                'doc_max'     => 10,
                'doc_example' => '822',
                'code_format' => -6,
            ],
            'bank_branch_name' => [
                'label'       => '分行名稱',
                'max'         => 30,
                'db'          => 30,
                'doc_max'     => 30,
                'doc_example' => '敦北分行',
                'code_format' => -21,
            ],
            'bank_branch_code' => [
                'label'       => '分行代碼',
                'max'         => 10,
                'db'          => 10,
                'doc_max'     => 10,
                'doc_example' => '0015',
                'code_format' => -19,
                'expect'      => 'code',
            ],
            'bank_data_json' => [
                'label'       => '同單多組的銀行資料',
                'expect'      => 'json',
                'doc_hint'    => 'JSON 陣列，每一組要有 bank_name／bank_code／bank_account',
                'code_format' => -12,
            ],
            'pay_content' => [
                'label'       => '繳費內容',
                'required'    => 'conditional',
                'max'         => 100,
                'db'          => 255,
                'doc_max'     => 100,
                'code_blank'  => -9,
                'code_format' => -9,
            ],
            'cvs_payment' => [
                'label'       => '超商繳費類型',
                'required'    => 'conditional',
                'in'          => ['711', 'Family', 'HiLife', 'OK'],
                'db'          => 20,
                'doc_example' => '711',
                'code_blank'  => -20,
                'code_format' => -11,
            ],
            'user_phone' => [
                'label'       => '電話',
                'required'    => 'conditional',
                'db'          => 255,
                'doc_example' => '0912345678',
                'code_blank'  => -13,
            ],
            'user_name' => [
                'label'      => '姓名',
                'required'   => 'conditional',
                'db'         => 50,
                'code_blank' => -8,
            ],
            'user_id_number' => [
                'label' => '身分證',
                'db'    => 20,
            ],
            'user_birthday' => [
                'label'       => '生日',
                'db_type'     => 'date',
                'doc_hint'    => '日期格式 Y-m-d',
                'doc_example' => '1990-03-15',
            ],
            'email' => [
                'label' => '付款人 email',
                'db'    => 255,
            ],
            'address' => [
                'label' => '付款人地址',
                'db'    => 255,
            ],
            'client_ip' => [
                'label' => '請求 IP',
                'db'    => 50,
            ],
            'notify_url' => [
                'label' => '回調網址',
                'db'    => 255,
            ],
        ],
    ],

    /*
     * 各通道在開單時額外要求的欄位（`Apollo::verifyParamsRequired()`）。
     *
     * ⚠ 沒填的話回的是**各自的專屬碼**（`-9`／`-10`／`-12`…），**不是 `-1`**
     * —— 掃過 47 組，沒有一組會掉進 `-1`。所以這份表的用途是「告訴客人這個
     * 通道還要帶什麼」，不是用來解釋 `-1`。
     *
     * ⚠ **客服系統不知道某個站台用哪個通道**（那在主系統的 `payment` 表），
     * 所以只能當成參考資訊，不能拿來斷定缺漏。
     */
    'CHANNEL_REQUIRED' => [
        'withdraw' => [
            'Tcs'           => ['bank_name', 'bank_code', 'bank_account', 'user_name', 'user_id_number'],
            'Hz'            => ['bank_account', 'bank_name', 'user_name', 'bank_branch_name'],
            'NewMm'         => ['bank_code', 'user_name', 'bank_name', 'bank_branch_name', 'user_phone'],
            'Sbs'           => ['bank_code', 'bank_account', 'user_name', 'bank_branch_name'],
            'Yongxin'       => ['user_name', 'user_phone', 'bank_name', 'bank_branch_name', 'bank_code', 'bank_account'],
            'Xingxin'       => ['bank_code', 'bank_account', 'user_name', 'bank_branch_name', 'bank_branch_code'],
            'TwoOneCashier' => ['bank_code', 'bank_account', 'user_name', 'bank_branch_name', 'bank_branch_code'],
            'Bgp'           => ['bank_account', 'user_name', 'bank_branch_code', 'bank_branch_name'],
            'Maxhk'         => ['bank_branch_code', 'user_name'],
            'Treasure'      => ['bank_branch_code', 'user_id_number'],
            'Baoyuan'       => ['bank_code', 'bank_account', 'user_name'],
            'Fuwin'         => ['bank_account', 'bank_code', 'user_name'],
            'Gameone'       => ['bank_name', 'bank_account', 'bank_code', 'user_name'],
            'Gz'            => ['bank_code', 'bank_account', 'user_name'],
            'Jat'           => ['bank_account', 'user_name'],
            'King'          => ['bank_account', 'user_name'],
            'Liuba'         => ['bank_name', 'bank_account'],
            'Plus'          => ['bank_name', 'bank_account'],
            'Teimshen'      => ['bank_code', 'bank_account', 'user_name'],
            'Trc20Usdt'     => ['bank_account', 'user_name'],
        ],
        'deposit' => [
            'Bonnyfun'        => ['email', 'user_uid', 'user_id_number', 'user_phone', 'user_name'],
            'BonnyfunVa'      => ['email', 'user_uid', 'user_id_number', 'user_phone', 'user_name'],
            'Liuba'           => ['client_ip', 'bank_code', 'bank_account', 'user_name', 'user_phone', 'user_id_number'],
            'Tcs'             => ['bank_name', 'bank_code', 'bank_account', 'user_name', 'user_phone', 'user_id_number'],
            'Yongxin'         => ['user_name', 'user_phone', 'bank_name', 'bank_code', 'bank_account'],
            'Hz'              => ['user_name', 'user_phone', 'user_id_number'],
            'Sunny'           => ['user_phone', 'bank_account', 'bank_name', 'bank_code', 'user_name'],
            'Gameone'         => ['user_name', 'user_phone', 'bank_branch_code'],
            'Ccat'            => ['user_phone', 'email'],
            'Hp'              => ['user_phone', 'user_name'],
            'Speed'           => ['user_phone', 'user_name'],
            'Baoyuan'         => ['user_name', 'bank_code', 'bank_account'],
            'Cashier'         => ['bank_name', 'bank_code', 'bank_account'],
            'Self'            => ['bank_name', 'bank_code', 'bank_account'],
            'SelfCashier'     => ['bank_name', 'bank_code', 'bank_account'],
            'RbCashier'       => ['bank_name', 'bank_code', 'bank_account'],
            'ThaCashier'      => ['bank_name', 'bank_code', 'bank_account'],
            'TeimshenCashier' => ['user_name', 'bank_code', 'bank_account'],
            'Gz'              => ['bank_code', 'bank_account', 'user_name'],
            'King'            => ['bank_account', 'user_name'],
            'Jko'             => ['cvs_payment'],
            'Luckypay'        => ['user_phone'],
            'Mycash'          => ['user_phone'],
            'A8'              => ['user_name'],
            'Bes'             => ['user_name'],
            'Mm'              => ['user_name'],
            'TwoOneCashier'   => ['user_name'],
        ],
    ],
];
