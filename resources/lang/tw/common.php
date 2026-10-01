<?php

/**
 * 全站共用的詞彙
 *
 * 只放「任何頁面都可能用到、而且語意完全相同」的字。
 * 跟某個功能綁在一起的文案請放該功能自己的語系檔，不要塞進來。
 */

return [
    /*
     * 日期範圍快捷鈕。搭配 public/js/common.js 的 window.DateRange，
     * 「本週」「本月」都是完整範圍（週一到週日、1 號到月底）。
     */
    'date_range' => [
        'today'      => '今日',
        'yesterday'  => '昨日',
        'this_week'  => '本週',
        'last_week'  => '上週',
        'this_month' => '本月',
        'last_month' => '上月',
    ],
];
