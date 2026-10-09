<?php

return [
    'title'          => '个人设定',
    'field_account'  => '账号',
    'field_nickname' => '昵称',
    'field_password' => '密码',
    'password_hint'  => '不修改请留空',
    'field_telegram_username' => 'Telegram 帐号',
    'telegram_username_hint'  => '自动回复的求助单超时时，系统会在内部支援群组 @ 你。留空则不会被 tag。',
    'telegram_username_ph'    => '例：amy_chen（不含 @）',
    'telegram_bind_label'      => 'Telegram 绑定',
    'telegram_bind_ready'      => '已绑定',
    'telegram_bind_ready_hint' => '您会在 Telegram 收到班表、任务卡与统计通知。需要改绑请找管理者。',
    'telegram_bind_action'     => '产生绑定码',
    'telegram_bind_hint'       => '按下后会产生一组码，到 Telegram 私信客服机器人贴上即可完成绑定。绑定后才收得到班表与任务卡通知。',

    'msg' => [
        'update_success'      => '个人信息已更新',
        'update_failed'       => '个人信息更新失败',
        'full_width_password' => '密码含全角或不支持的字元（含空白），请改用半角英数与符号',
    ],
];
