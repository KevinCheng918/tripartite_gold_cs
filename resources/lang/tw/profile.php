<?php

return [
    'title'          => '個人設定',
    'field_account'  => '帳號',
    'field_nickname' => '暱稱',
    'field_password' => '密碼',
    'password_hint'  => '不修改請留空',
    'field_telegram_username' => 'Telegram 帳號',
    'telegram_username_hint'  => '自動回覆的求助單超時時，系統會在內部支援群組 @ 你。留空則不會被 tag。',
    'telegram_username_ph'    => '例：amy_chen（不含 @）',
    'telegram_bind_label'      => 'Telegram 綁定',
    'telegram_bind_ready'      => '已綁定',
    'telegram_bind_ready_hint' => '您會在 Telegram 收到班表、任務卡與統計通知。需要改綁請找管理者。',
    'telegram_bind_action'     => '產生綁定碼',
    'telegram_bind_hint'       => '按下後會產生一組碼，到 Telegram 私訊客服機器人貼上即可完成綁定。綁定後才收得到班表與任務卡通知。',

    /*
     * 閒置自動登出（需求方 2026-11-22）。
     *
     * ⚠ 提示要講明這是「閒置」才算 —— 不是「登入滿 N 分鐘就登出」。
     * 也要講明它是在這台瀏覽器上倒數的，不要讓人以為是強制的安全機制。
     */
    'field_session_timeout'   => '閒置多久自動登出',
    'session_timeout_hint'    => '只在您完全沒有操作（沒點、沒打字）時開始計時；選「不登出」就維持現在的樣子。這是方便用的設定，不是安全機制。',
    'session_timeout_never'   => '不登出',
    'session_timeout_minutes' => ':value 分鐘',
    'session_timeout_hours'   => ':value 小時',

    'msg' => [
        'update_success'      => '個人資訊已更新',
        // 閒置登出時導回登入頁要說一聲，不然他會以為自己被系統踢了
        'idle_logout'         => '太久沒有操作，已自動登出',
        'update_failed'       => '個人資訊更新失敗',
        'full_width_password' => '密碼含全形或不支援的字元（含空白），請改用半形英數與符號',
    ],
];
