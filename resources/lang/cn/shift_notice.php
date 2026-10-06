<?php

return [
    'nav_label'  => '班表通知',
    'page_title' => '班表通知',
    'subtitle'   => '每天早上自动私信今日班表',

    'section_label' => '通讯管理',

    'manager_title' => '完整班表收件人',
    'manager_desc'  => '每天 :time 私信今日完整班表（每个班次是谁、哪个班没人）。可以勾多位，没勾任何人时不会发送。',
    'manager_field'  => '收件人（可多选）',
    'manager_all'    => '全选',
    'manager_hint'   => '标示「未绑定」的同事收不到消息，请他先私信机器人一次。全部取消勾选就不发送。',
    'manager_unbound' => '未绑定',

    'personal_title' => '个人班表',
    'personal_desc'  => '今天有班的同事，每天 :time 会各自收到自己那一份（只有自己的班别与时间，不会看到其他人）。这部分自动发送，不需要设置。',

    'bind_title' => '为什么有人收不到',
    'bind_step1' => 'Telegram 不允许机器人主动私信「从来没跟它对话过」的人。',
    'bind_step2' => '请同事在 Telegram 找到客服机器人，私信任何一句话（例如「hi」）。',
    'bind_step3' => '发送后系统会自动记录，隔天早上就收得到班表了。',
    'bind_note'  => '只需要做一次。在群组里发言不算，一定要是私信。',

    'action_save'    => '保存',
    'action_saving'  => '保存中…',
    'action_test'    => '测试发送',
    'action_testing' => '发送中…',
    'test_hint'      => '会立刻把今天的完整班表私信给上面勾选的收件人。',

    'msg' => [
        'saved'             => '已保存',
        'save_failed'       => '保存失败，请稍后再试',
        'manager_invalid'   => '收件人格式不正确',
        'manager_not_found' => '找不到这个账号',
        'test_sent'         => '测试消息已发出 :sent 则，请确认 Telegram 有收到',
        'test_partial'      => '已发出 :sent 则，但这几位没收到：:failed（请他们先私信机器人一次）',
        'test_no_manager'   => '请先勾选收件人并保存',
        'test_not_bound'    => '勾选的同事都还没私信过机器人，所以收不到消息',
        'test_failed'       => '发送失败，请确认同事没有屏蔽机器人',
    ],
];
