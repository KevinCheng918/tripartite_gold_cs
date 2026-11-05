<?php

return [
    'nav_label'  => '登录记录',
    'page_title' => '登录记录',
    'subtitle'   => '查看所有账号的登录与登录失败记录',

    'field_account'     => '账号',
    'field_ip'          => 'IP 地址',
    'field_is_success'  => '登录结果',
    'field_device'      => '登录设备',
    'field_fail_reason' => '失败原因',
    'field_created_at'  => '登录时间',
    'field_start_date'  => '起始日期',
    'field_end_date'    => '结束日期',

    'status_success' => '成功',
    'status_failed'  => '失败',

    'field_os'      => '操作系统',
    'field_browser' => '浏览器',

    // ⚠ 同时用在登录记录表格与 Telegram 登录通知，由 UserAgentPresenter 统一产出
    'device_desktop' => '桌机',
    'device_mobile'  => '手机',
    'device_tablet'  => '平板',

    'filter_all'     => '全部',
    'filter_success' => '成功',
    'filter_failed'  => '失败',
];
