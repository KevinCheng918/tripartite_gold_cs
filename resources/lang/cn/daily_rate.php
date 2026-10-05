<?php

return [
    'nav_label'  => '每日汇率',
    'page_title' => '每日汇率',
    'subtitle'   => '每天上午 9 点在内部支援群组报汇率，由同仁回覆决定当日对客报价',

    'today_title'    => '今日汇率',
    'today_pending'  => '尚未决定',
    'today_hint'     => '汇率还没决定前，客人问汇率会先请他稍候',

    'field_date'           => '日期',
    'field_rate'           => '对客报价',
    'field_reference_rate' => '4H 均价',
    'field_suggested_rate' => '建议报价',
    'field_yesterday_rate' => '上次报价',
    'field_replier'        => '决定者',
    'field_replied_at'     => '决定时间',
    'field_remind_count'   => '提醒次数',

    'template_title'    => '报价公版',
    'template_hint'     => '可用变量：{date} 日期、{reference} 4H 均价、{suggested} 建议报价、{yesterday} 上次报价、{yesterday_date} 上次报价日期',
    'template_preview'  => '预览',
    'template_preview_title' => '同仁会在群组看到的内容',

    'action_edit'    => '修改',
    'action_save'    => '储存',
    'action_preview' => '预览',

    'edit_title' => '设定汇率',
    'edit_hint'  => '手动设定会盖掉群组回覆的结果。可以补登过去的日期，但不能填未来',

    'empty' => '还没有任何汇率纪录',

    // 走势图截图
    'screenshot_title'     => '走势图截图',
    'screenshot_ready'     => '这台机器可以截图',
    'screenshot_missing'   => '这台机器没有可用的 Chrome，报价时只会发文字',
    'screenshot_hint'      => '报价时会附上 MAX 的走势图。截不到图也不影响报价，只是没有图',
    'screenshot_arm_note'  => '这台是 arm64，没有官方的 Linux Chrome，装不起来。正式机（x86_64）才截得到图',
    'screenshot_install'   => '安装方式（x86_64 Linux）：apt-get install -y google-chrome-stable',
    'action_test_shot'     => '测试截图',
    'action_ask_now'       => '立即报价',
    'ask_now_hint'         => '把早上 9 点那套完整跑一次并送到内部群组，可以直接在群组引用回覆测试',
    'ask_now_confirm'      => '会真的发送报价到内部支援群组，并覆盖今天已经报过的那则。确定吗？',

    'msg' => [
        'saved'                 => '汇率已储存',
        'save_failed'           => '汇率储存失败',
        'template_saved'        => '公版已储存',
        'template_save_failed'  => '公版储存失败',
        'date_required'         => '请选择日期',
        'date_future'           => '不能设定未来的汇率',
        'rate_required'         => '请填写汇率',
        'rate_numeric'          => '汇率请填数字',
        'rate_min'              => '汇率必须大于 0',
        'template_required'     => '请填写报价公版',
        'template_max'          => '报价公版不可超过 :value 字',

        'ask_sent'            => '报价已发到内部支援群组（这次没有附图）',
        'ask_sent_with_chart' => '报价已发到内部支援群组，含走势图',
        'ask_no_support_group' => '尚未设定内部支援群组，没有地方可以报',
        'ask_send_failed'      => '报价发送失败，详见 log',

        'screenshot_sent'             => '截图已发到内部支援群组（使用 :binary）',
        'screenshot_no_chrome'        => '这台机器没有可用的 Chrome，无法截图',
        'screenshot_no_support_group' => '尚未设定内部支援群组，没有地方可以发',
        'screenshot_capture_failed'   => '截图失败，详见 log（可能是网站太慢或等待时间不够）',
        'screenshot_send_failed'      => '截到图了但发送失败，详见 log',
    ],
];
