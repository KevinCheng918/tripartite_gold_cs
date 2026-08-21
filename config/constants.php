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
            'ADMIN'    => 0,
            'BOSS'     => 1,
            'LEADER'   => 2,
            'ENGINEER' => 3,
            'CS'       => 4,
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

    'STATION' => [
        'STATUS' => [
            'ACTIVE'   => 1, // 啟用
            'FROZEN'   => 2, // 凍結
            'DISABLED' => 0, // 停用
        ],
    ],

    'VM' => [
        'POWER' => [
            'ON'  => 1,
            'OFF' => 0,
        ],
        'STATUS' => [
            'ACTIVE'   => 1,
            'DISABLED' => 0,
        ],
        'BILLING' => [
            'UNPAID'   => 0,
            'PAID'     => 1,
            'PENDING'  => 2, // 待審核（已上傳繳款證明）
            'GRACE_DAYS' => 3,
        ],
    ],

    'TASK' => [
        'STATUS' => [
            'PENDING'     => 1,
            'IN_PROGRESS' => 2,
            'TESTING'     => 3,
            'IN_REVIEW'   => 4,
            'RESOLVED'    => 5,
            'ARCHIVED'    => 6,
        ],
        'PRIORITY' => [
            'LOW'    => 1,
            'MEDIUM' => 2,
            'HIGH'   => 3,
            'URGENT' => 4,
        ],
    ],

    'PROJECT' => [
        'STATUS' => [
            'ACTIVE'   => 1,
            'DISABLED' => 0,
        ],
    ],

    'PAGINATION' => [
        'DEFAULT' => 10,
        'USER' => 10,
    ],

    'FINANCE' => [
        'EXPENSE_TYPE' => [
            'MISC'   => 'misc',
            'SERVER' => 'server',
        ],
        'CATEGORY' => [
            'office'       => '辦公用品',
            'transport'    => '交通',
            'meal'         => '餐費',
            'communication' => '通訊',
            'subscription' => '軟體訂閱',
            'other'        => '其他',
            'server'       => '伺服器',
        ],
        'CURRENCY' => ['TWD', 'USD', 'USDT'],
    ],

];
