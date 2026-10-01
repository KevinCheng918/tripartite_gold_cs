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
    ],
];
