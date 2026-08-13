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
        ],
    ],

    'station' => [
        'label' => 'permission.group.station',
        'keywords' => [
            'station.view'   => 'permission.station.view',
            'station.create' => 'permission.station.create',
            'station.update' => 'permission.station.update',
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
            'telegram_chat.broadcast' => 'permission.telegram_chat.broadcast',
        ],
    ],

];
