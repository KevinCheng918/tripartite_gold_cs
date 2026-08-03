<?php

/**
 * 系統常數
 *
 * 對齊主系統 tripartite_gold 的 constants.php 風格，
 * 透過 config('constants.XXX') 取值。
 */

return [

    'USER' => [
        'LEVEL' => [
            'ADMIN' => 0,
            'CS'    => 1,
        ],
        'STATUS' => [
            'NORMAL'     => 1,  // 正常：完整功能
            'LOCK'       => 2,  // 鎖定：可登入，不可報班/換班，班別由管理者指派
            'DEACTIVATE' => 0,  // 停用：無法登入
        ],
    ],

    'TELEGRAM' => [
        'DIRECTION' => [
            'INBOUND'  => 1, // 從 Telegram 群組收到的訊息
            'OUTBOUND' => 2, // 從後台發送到 Telegram 的訊息
        ],
        'GROUP_STATUS' => [
            'ACTIVE'   => 1,
            'ARCHIVED' => 0,
        ],
        'ALERT' => [
            'WEEKDAY_MINUTES' => 5,  // 週一至週五未回覆告警閾值
            'WEEKEND_MINUTES' => 30, // 週六日未回覆告警閾值
        ],
        'TTL_DAYS' => 7, // 訊息保留天數
    ],

    'PAGINATION' => [
        'USER' => 20,
    ],

];
