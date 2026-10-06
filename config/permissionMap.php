<?php

/*
|--------------------------------------------------------------------------
| Permission Keyword Map
|--------------------------------------------------------------------------
|
| Registry of every permission keyword in the system, grouped by feature
| area. Each keyword maps to a lang key (resources/lang/{locale}/permission.php)
| so labels flow through the tw -> cn -> en sync convention.
|
| New features top up this file with their own top-level group; they do
| not modify existing groups.
|
*/

return [

    'dashboard' => [
        'label' => 'permission.group.dashboard',
        'keywords' => [
            'dashboard.usdt_rate' => 'permission.dashboard.usdt_rate',
        ],
    ],

    'account' => [
        'label' => 'permission.group.account',
        'keywords' => [
            'account.view' => 'permission.account.view',
            'account.create' => 'permission.account.create',
            'account.update' => 'permission.account.update',
            'account.assign_permission' => 'permission.account.assign_permission',
            'login_log.view' => 'permission.login_log.view',
        ],
    ],

    'shift' => [
        'label' => 'permission.group.shift',
        'keywords' => [
            'shift.view' => 'permission.shift.view',
            'shift.update' => 'permission.shift.update',
            'shift.assign' => 'permission.shift.assign',
            'shift.swap' => 'permission.shift.swap',
            'shift.delete' => 'permission.shift.delete',
            'shift.cover' => 'permission.shift.cover',
            'shift.cover_review' => 'permission.shift.cover_review',
        ],
    ],

    'attendance' => [
        'label' => 'permission.group.attendance',
        'keywords' => [
            'attendance.view' => 'permission.attendance.view',
            'attendance.clock' => 'permission.attendance.clock',
            'attendance.report' => 'permission.attendance.report',
            'attendance.amend' => 'permission.attendance.amend',
            'attendance.amend_review' => 'permission.attendance.amend_review',
        ],
    ],

    'station' => [
        'label' => 'permission.group.station',
        'keywords' => [
            'station.view'   => 'permission.station.view',
            'station.create' => 'permission.station.create',
            'station.update'       => 'permission.station.update',
            'station.topup_view'   => 'permission.station.topup_view',
            'station.topup_apply'  => 'permission.station.topup_apply',
            'station.topup_approve' => 'permission.station.topup_approve',
        ],
    ],

    'vm' => [
        'label' => 'permission.group.vm',
        'keywords' => [
            'vm.view'           => 'permission.vm.view',
            'vm.create'         => 'permission.vm.create',
            'vm.update'         => 'permission.vm.update',
            'vm.billing_view'   => 'permission.vm.billing_view',
            'vm.billing_upload' => 'permission.vm.billing_upload',
            'vm.billing_approve' => 'permission.vm.billing_approve',
        ],
    ],

    'payment_config' => [
        'label' => 'permission.group.payment_config',
        'keywords' => [
            'payment_config.view'   => 'permission.payment_config.view',
            'payment_config.manage' => 'permission.payment_config.manage',
        ],
    ],

    'telegram_chat' => [
        'label' => 'permission.group.telegram_chat',
        'keywords' => [
            'telegram_chat.reply'     => 'permission.telegram_chat.reply',
            'telegram_chat.assign'    => 'permission.telegram_chat.assign',
            'telegram_chat.broadcast' => 'permission.telegram_chat.broadcast',
            'telegram_chat.delete'    => 'permission.telegram_chat.delete',
            /*
             * 不跟 reply 綁一起：設定誰不自動回覆會改變系統對客人的自動行為，
             * 影響範圍比「回一則訊息」大，該由管理者單獨指派。
             */
            'telegram_chat.ignore_manage' => 'permission.telegram_chat.ignore_manage',
        ],
    ],

    /*
     * 通知設定（內部支援群組、話題分流、班表通知）。
     *
     * 跟 setting（全域設定）分開：那邊是 Claude 憑證與用量，這邊是「通知發到哪、
     * 發給誰」。也跟 shift（排班）分開 —— 能排班的人不一定該改通知收件人。
     */
    'notification' => [
        'label' => 'permission.group.notification',
        'keywords' => [
            'notification.view'   => 'permission.notification.view',
            'notification.manage' => 'permission.notification.manage',
        ],
    ],

    'setting' => [
        'label' => 'permission.group.setting',
        'keywords' => [
            'setting.view'   => 'permission.setting.view',
            'setting.manage' => 'permission.setting.manage',
        ],
    ],

    'staff_manage' => [
        'label' => 'permission.group.staff_manage',
        'keywords' => [
            'staff_manage.view' => 'permission.staff_manage.view',
            'staff_manage.edit' => 'permission.staff_manage.edit',
            /*
             * 登記自己的消耗品領用／使用。**每個內勤都要勾**，
             * 不然他們連自己用掉幾張卡都登記不了。
             *
             * 跟 edit 分開：edit 是「能改別人的、能維護品項」，那是管理者的事。
             */
            'staff_manage.consumable_log' => 'permission.staff_manage.consumable_log',

            /*
             * 看**所有人**的消耗品。沒有這個權限的人只看得到自己的 ——
             * 範圍限制在 Controller 強制套用，不是靠前端不顯示。
             *
             * 給主管以上。用 keyword 而不是判 `level <= LEADER`：
             * 權限一律由 permissionMap 控制，寫死身份會讓權限表看不出全貌。
             */
            'staff_manage.consumable_view_all' => 'permission.staff_manage.consumable_view_all',
        ],
    ],

    'project' => [
        'label' => 'permission.group.project',
        'keywords' => [
            'project.view' => 'permission.project.view',
            'project.edit' => 'permission.project.edit',
        ],
    ],

    'shared_file' => [
        'label' => 'permission.group.shared_file',
        'keywords' => [
            'shared_file.view'   => 'permission.shared_file.view',
            'shared_file.upload' => 'permission.shared_file.upload',
            'shared_file.delete' => 'permission.shared_file.delete',
        ],
    ],

    'finance' => [
        'label' => 'permission.group.finance',
        'keywords' => [
            'finance.view' => 'permission.finance.view',
            'finance.edit' => 'permission.finance.edit',
        ],
    ],

    'leave_request' => [
        'label' => 'permission.group.leave_request',
        'keywords' => [
            'leave_request.apply'  => 'permission.leave_request.apply',
            'leave_request.review' => 'permission.leave_request.review',
        ],
    ],

    'quick_reply' => [
        'label' => 'permission.group.quick_reply',
        'keywords' => [
            'quick_reply.view' => 'permission.quick_reply.view',
            'quick_reply.edit' => 'permission.quick_reply.edit',
        ],
    ],

    'daily_rate' => [
        'label' => 'permission.group.daily_rate',
        'keywords' => [
            'daily_rate.view'   => 'permission.daily_rate.view',
            'daily_rate.manage' => 'permission.daily_rate.manage',
        ],
    ],

    'task_board' => [
        'label' => 'permission.group.task_board',
        'keywords' => [
            'task_board.view'           => 'permission.task_board.view',
            'task_board.create'         => 'permission.task_board.create',
            'task_board.update'         => 'permission.task_board.update',
            'task_board.delete'         => 'permission.task_board.delete',
            'task_board.delete_comment'  => 'permission.task_board.delete_comment',
        ],
    ],

];
