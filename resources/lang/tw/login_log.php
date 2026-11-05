<?php

return [
    'nav_label'  => '登入紀錄',
    'page_title' => '登入紀錄',
    'subtitle'   => '查看所有帳號的登入與登入失敗紀錄',

    'field_account'     => '帳號',
    'field_ip'          => 'IP 位址',
    'field_is_success'  => '登入結果',
    'field_device'      => '登入裝置',
    'field_fail_reason' => '失敗原因',
    'field_created_at'  => '登入時間',
    'field_start_date'  => '起始日期',
    'field_end_date'    => '結束日期',

    'status_success' => '成功',
    'status_failed'  => '失敗',

    'field_os'      => '作業系統',
    'field_browser' => '瀏覽器',

    /*
     * 裝置類型（需求方 2026-11-05 裁示：這一欄顯示桌機／手機／平板）。
     *
     * ⚠ 這三個字串同時用在登入紀錄表格與 Telegram 登入通知 ——
     * `UserAgentPresenter` 是唯一的產出處，所以兩邊永遠一致。
     */
    'device_desktop' => '桌機',
    'device_mobile'  => '手機',
    'device_tablet'  => '平板',

    'filter_all'     => '全部',
    'filter_success' => '成功',
    'filter_failed'  => '失敗',
];
