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
];
