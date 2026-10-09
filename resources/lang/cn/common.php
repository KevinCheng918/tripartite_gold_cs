<?php

/**
 * 全站共用的词汇
 *
 * 只放「任何页面都可能用到、而且语意完全相同」的字。
 * 跟某个功能绑在一起的文案请放该功能自己的语系档，不要塞进来。
 */

return [
    /*
     * 日期范围快捷钮。搭配 public/js/common.js 的 window.DateRange，
     * 「本周」「本月」都是完整范围（周一到周日、1 号到月底）。
     */
    'date_range' => [
        'today'      => '今日',
        'yesterday'  => '昨日',
        'this_week'  => '本周',
        'last_week'  => '上周',
        'this_month' => '本月',
        'last_month' => '上月',
    ],

    /*
     * 表格「操作」栏的分段名称。动作按分类黏成几段 btn-group
     * （样式见 public/css/custom.css 的 .row-actions 区块），
     * 这些字是每一段的 `aria-label`。
     *
     * ⚠ 视觉上的分段对读屏幕的人不存在，这个名称是他唯一的线索，
     * 所以每一段都要挂，不能因为「画面上看不到」就省略。
     *
     * 分类名是通用语意，各页面挑用得到的那几个；
     * 跟某个功能绑定的动作名称仍放该功能自己的语系档。
     */
    'row_actions' => [
        'view'   => '查看',
        'manage' => '管理',
        'record' => '纪录',
        'notify' => '通知',
        'danger' => '危险操作',
    ],
];
