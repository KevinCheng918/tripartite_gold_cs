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

    /*
     * 表格「操作」欄的分段名稱。動作按分類黏成幾段 btn-group
     * （樣式見 public/css/custom.css 的 .row-actions 區塊），
     * 這些字是每一段的 `aria-label`。
     *
     * ⚠ 視覺上的分段對讀螢幕的人不存在，這個名稱是他唯一的線索，
     * 所以每一段都要掛，不能因為「畫面上看不到」就省略。
     *
     * 分類名是通用語意，各頁面挑用得到的那幾個；
     * 跟某個功能綁定的動作名稱仍放該功能自己的語系檔。
     */
    'row_actions' => [
        'view'   => '檢視',
        'manage' => '管理',
        'record' => '紀錄',
        'notify' => '通知',
        'danger' => '危險操作',
    ],
];
