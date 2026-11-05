<?php

return [
    'nav_label'  => 'Login Log',
    'page_title' => 'Login Log',
    'subtitle'   => 'View login and failed login records for all accounts',

    'field_account'     => 'Account',
    'field_ip'          => 'IP Address',
    'field_is_success'  => 'Result',
    'field_device'      => 'Device',
    'field_fail_reason' => 'Fail Reason',
    'field_created_at'  => 'Login Time',
    'field_start_date'  => 'Start Date',
    'field_end_date'    => 'End Date',

    'status_success' => 'Success',
    'status_failed'  => 'Failed',

    'field_os'      => 'OS',
    'field_browser' => 'Browser',

    // ⚠ Shared by the login-log table and the Telegram login notice, produced only by UserAgentPresenter
    'device_desktop' => 'Desktop',
    'device_mobile'  => 'Mobile',
    'device_tablet'  => 'Tablet',

    'filter_all'     => 'All',
    'filter_success' => 'Success',
    'filter_failed'  => 'Failed',
];
