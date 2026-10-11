<?php

/**
 * 共用驗證規則
 *
 * 對齊主系統 tripartite_gold 的 config/rules.php 風格，
 * 透過 config('rules.XXX') 取值。
 */

return [
    'USER_ACCOUNT_REGEX'  => 'regex:/^[A-Za-z0-9\_]{4,20}$/',
    'USER_PASSWORD_REGEX' => 'regex:/^[A-Za-z0-9!@#$%^&*()_+\-=\[\]{}|;:,.<>?\/?]{8,}$/',

    /*
     * Telegram 帳號（不含 @）。用來在內部群組 tag 人。
     */
    'TELEGRAM_USERNAME_REGEX' => 'regex:/^@?[A-Za-z0-9_]{5,32}$/',

    /*
     * Telegram 群組的 chat_id。
     *
     * 群組是**負數**，所以不能用 integer 規則（會把負號擋掉），得用 regex。
     */
    'TELEGRAM_CHAT_ID_REGEX' => 'regex:/^-?\d{5,20}$/',

    /*
     * 點數欄位的數值上限。
     *
     * station.credits 與 station.credit_alert_threshold 都是 decimal(15,2)，
     * 超過這個值寫進去會被 MySQL 截斷（非 strict mode）或直接報錯。
     *
     * 告警門檻的驗證有三個入口（繳款設定的全域門檻、站台新增、站台更新），
     * 各自寫一次魔術數字遲早會改一邊漏兩邊。
     */
    'STATION_CREDIT_MAX' => 99999999999999,

    /*
     * 要送到 Telegram 的公版字數上限。
     *
     * Telegram 單則訊息的硬上限是 4096，這裡抓一半 —— 公版裡的變數
     * （站台名、匯率、點數…）代換後只會更長，留餘裕才不會在送出當下才爆。
     *
     * 目前有兩個公版用它（站台餘點告警、每日匯率報價），各寫一次就會
     * 改一邊忘一邊。
     */
    'TELEGRAM_TEMPLATE_MAX' => 2000,

    /*
     * 上傳檔案禁止的副檔名
     *
     * 上傳目的地在 storage/app/public 底下、對外可直接存取，
     * 若讓可執行或腳本類檔案進來，等於開了一條執行任意程式碼的路。
     *
     * 任務看板附件與 Telegram 傳檔共用同一份，各自複製一份遲早會改一邊漏一邊。
     */
    'UPLOAD_BLOCKED_EXTENSIONS' => [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr',
        'sh', 'bash', 'zsh', 'ps1',
        'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'pl',
        'htaccess', 'htpasswd',
    ],

    /*
     * 報表的期間類型。
     *
     * ⚠ 必須是白名單而不是自由字串：這個值會被拿去決定區間怎麼算，
     * 認不得的值會靜悄悄落到「日」那條 —— 使用者看到的是錯的期間，
     * 卻不會有任何錯誤訊息。
     *
     * 值對應 `RemindReportService::TYPE_*` 與 `AttendanceReportService::TYPE_*`。
     */
    'REPORT_PERIOD_TYPE_IN' => 'in:daily,weekly,monthly',
];
