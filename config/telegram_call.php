<?php

/**
 * Telegram userbot 響鈴（求助單逾時升級用）
 *
 * ⚠ Bot API **沒有**語音通話能力，所以這裡用的是 **user 帳號**（userbot）
 * 走 MTProto，由 `tools/tg_call.py` 這支 Python sidecar 執行 ——
 * PHP 做不到 MTProto 語音（MadelineProto 已移除 VoIP 且需 PHP 8.2+）。
 *
 * ⚠ 只「響」不串音訊：`phone.requestCall` 讓對方手機響，數秒後
 * `phone.discardCall`。電話的價值在於穿得過靜音與勿擾，而那只需要響；
 * 該做什麼在 Telegram 訊息裡已經寫了十次。
 *
 * 詳見 .claude/knowledge/features/ticket-overdue-escalation.md
 */

return [

    /*
     * Python 直譯器與 sidecar 腳本。
     *
     * ⚠ 容器裡要裝得到 telethon（`pip3 install telethon`）。沒裝的話
     * sidecar 會回 `TELETHON_NOT_INSTALLED`，而不是整個 crash。
     */
    'python' => env('TG_CALL_PYTHON', 'python3'),
    'script' => base_path('tools/tg_call.py'),

    /*
     * my.telegram.org 申請的應用程式憑證。
     *
     * ⚠ 這是**應用程式**層級的憑證，不是帳號層級 —— 可以用 A 帳號申請、
     * 拿去登入 B 帳號。2026-11-09 實測：my.telegram.org 的
     * `Too many attempts` **擋的是來源 IP 不是帳號**，同網路換帳號一樣被擋，
     * 改用手機 4G 熱點就過了。
     *
     * ⚠ 放 .env 不放這裡：api_hash 等同密碼。
     */
    'api_id'   => env('TG_CALL_API_ID'),
    'api_hash' => env('TG_CALL_API_HASH'),

    /*
     * Telethon session 檔路徑（**不含** `.session` 副檔名）。
     *
     * ⚠ **必須先人工跑一次互動登入產生**（要收驗證碼）。排程不會、也不該
     * 停下來等人輸入驗證碼 —— sidecar 找不到 session 就直接回
     * `SESSION_NOT_FOUND`。
     *
     * 放 storage 是因為那裡本來就可寫；⚠ 這個檔等同登入憑證，
     * 不要進版控（storage 已在 .gitignore 的管轄範圍）。
     */
    'session' => env('TG_CALL_SESSION', storage_path('app/telegram/call_session')),

    /*
     * sidecar 的逾時秒數。
     *
     * ⚠ 一定要大於「響鈴秒數」，否則 Process 會在它掛斷之前就被砍掉 ——
     * 那會留下一通沒有被正常 discard 的通話。響 30 秒 + 連線與收尾的餘裕。
     */
    'timeout' => (int) env('TG_CALL_TIMEOUT', 90),
];
