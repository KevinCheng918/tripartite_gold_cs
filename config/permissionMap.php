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
            'account.delete' => 'permission.account.delete',
            'account.assign_role' => 'permission.account.assign_role',
        ],
    ],

    'role' => [
        'label' => 'permission.group.role',
        'keywords' => [
            'role.view' => 'permission.role.view',
            'role.create' => 'permission.role.create',
            'role.update' => 'permission.role.update',
            'role.delete' => 'permission.role.delete',
            'role.assign_permission' => 'permission.role.assign_permission',
        ],
    ],

];
