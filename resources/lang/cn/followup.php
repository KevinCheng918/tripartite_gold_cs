<?php

return [
    'nav_label'  => '待跟进事项',
    'page_title' => '待跟进事项',
    'subtitle'   => '对话里有人说「稍等、晚点给你」就会记在这里',

    'section_label' => '客服管理',

    'desc' => '平常由当班人员在支持群组按按钮决定；这一页是想看全部、或那则按钮消息被洗掉时的入口。',

    // 筛选
    'filter_source'     => '来源',
    'filter_status'     => '状态',
    'filter_group'      => '对话',
    'filter_all'        => '全部',
    'filter_open_only'  => '只看未结案',
    // 期间比的是开单日期，留白＝不限期间
    'filter_date_from'  => '开单日期（起）',
    'filter_date_to'    => '开单日期（迄）',
    'filter_search'     => '查询',
    'filter_reset'      => '清除条件',

    // 表格
    'col_source'  => '来源',
    'col_group'   => '对话',
    'col_sender'  => '谁说的',
    'col_text'    => '原句',
    'col_opened_at' => '开单时间',
    'col_waited'  => '等多久',
    // 「等多久」是从最后一次重新计时起算，不是从开单 —— 对方中途说过话就会往后推
    'col_waited_hint' => '从最后一次对方说话起算。对方在等待期间又说话会重新计时，所以可能比开单时间晚很多。',
    'col_counts'  => '已拖／已追问',
    'col_status'  => '状态',
    'col_decided' => '谁决定的',
    'col_action'  => '操作',

    'counts'      => ':waits 次／:rounds 次',

    'waited_days'  => ':days 天 :hours 小时',
    'waited_hours' => ':hours 小时 :minutes 分',
    'waited_mins'  => ':minutes 分钟',
    'keyword'     => '命中：:keyword',
    'empty'       => '目前没有符合条件的待跟进事项',
    'never_asked' => '还没问过',

    // 操作
    'action_follow' => '跟进',
    // 现在不决定，过一阵子再问一次
    'action_defer'  => '继续等',
    'action_close'  => '结案',

    'confirm_follow_customer' => '确定要跟进吗？系统会发消息问客人后续进度。',
    'confirm_follow_staff'    => '确定要跟进吗？系统会在支持群组 tag 当班人员接手处理，不会发消息给客人。',
    // 要讲明「还会再问」与「次数照算」——否则看起来跟结案或无限拖没两样
    'confirm_defer'           => '这笔先不决定吗？系统会把下一次询问往后推，安静一段时间后再问一次。不会发消息给客人，也不会结案；已经问过的次数照算，所以不会无限延后。',
    'confirm_close'           => '确定结案吗？这个话题就结束了，不会再提醒。',
    'confirm_ok'              => '确定',
    'confirm_cancel'          => '取消',

    'msg' => [
        'not_found'       => '这笔待跟进事项已经处理过了',
        'decided'         => '已处理',
        'decide_required' => '请指定要跟进、继续等还是结案',
        'decide_failed'   => '处理失败，请稍后再试',
        'load_failed'     => '载入失败，请稍后再试',
        'date_invalid'    => '日期格式不正确',
        'range_required'  => '请选择完整的开始与结束日期',
        'range_reversed'  => '结束日期不能早于开始日期',
    ],
];
