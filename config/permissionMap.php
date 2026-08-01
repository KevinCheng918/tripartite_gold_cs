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

];
