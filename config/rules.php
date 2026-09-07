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
];
