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

    'msg' => [
        'update_success'      => '個人資訊已更新',
        'update_failed'       => '個人資訊更新失敗',
        'full_width_password' => '密碼含全形或不支援的字元（含空白），請改用半形英數與符號',
    ],
];
