<?php

return [
    'nav_label'     => '虛擬機管理',
    'page_title'    => '虛擬機管理',
    'subtitle'      => '虛擬機與帳務紀錄',

    // Tabs
    'tab_servers'   => '虛擬機列表',
    'tab_billing'   => '帳務紀錄',

    // 欄位
    'field_station'      => '站台',
    'field_hostname'     => '主機名稱',
    'field_internal_ip'  => '內網 IP',
    'field_external_ip'  => '外網 IP',
    'field_model_type'   => '機型',
    'field_spec'         => '規格',
    'field_monthly_fee'  => '主機月費',
    'field_vpn_fee'      => 'VPN 費用',
    'field_google_fee'   => 'Google 帳號費用',
    'field_total_fee'    => '總金額',
    'field_billing_day'  => '帳單日',
    'field_power'        => '開關機',
    'field_status'       => '狀態',
    'field_note'         => '備註',
    'field_month'        => '月份',
    'field_amount'       => '金額',
    'field_due_date'     => '應收日',
    'field_paid'         => '收款狀態',
    'field_overdue_days' => '逾期天數',

    // 狀態
    'power_on'       => '開機',
    'power_off'      => '關機',
    'status_active'  => '啟用',
    'status_disabled' => '停用',
    'paid_yes'       => '已收',
    'paid_no'        => '未收',
    'paid_pending'   => '待審核',
    'overdue'        => '逾期',

    // 操作
    'action_create'        => '新增虛擬機',
    'action_edit'          => '編輯',
    'action_toggle_power'  => '切換開關機',
    'action_upload_proof'  => '上傳繳款證明',
    'action_approve'       => '審核通過',
    'action_mark_paid'     => '標記已收',
    'action_view_proof'    => '查看證明',
    'action_generate'      => '產生帳單',

    // 篩選
    'filter_all'     => '全部',
    'filter_unpaid'  => '未收',
    'filter_paid'    => '已收',
    'filter_pending' => '待審核',
    'filter_overdue' => '逾期',

    // 訊息
    'msg' => [
        'create_failed'    => '虛擬機新增失敗',
        'update_failed'    => '虛擬機更新失敗',
        'power_toggled'    => '開關機狀態已切換',
        'toggle_failed'    => '切換失敗',
        'proof_uploaded'   => '繳款證明已上傳，待審核',
        'upload_failed'    => '上傳失敗',
        'approved'         => '審核通過，已標記收款',
        'approve_failed'   => '審核失敗',
        'marked_paid'      => '已標記收款',
        'mark_failed'      => '標記失敗',
        'billing_generated' => '已產生 :count 筆帳單',
        'generate_failed'  => '帳單產生失敗',
    ],
];
