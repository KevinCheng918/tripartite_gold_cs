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

    'field_system'       => '系統',
    'field_action'       => '操作',

    // 操作
    'action_search'        => '搜尋',
    'action_reset'         => '重置',
    'action_cancel'        => '取消',
    'action_confirm'       => '確認',
    'action_upload'        => '上傳',
    'action_reupload'      => '重新上傳',
    'action_collapse'      => '— 折疊 —',
    'action_expand'        => '— 展開 —',
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
    'generate_billing_month' => '帳單月份',
    'generate_billing_hint'  => '只能產生本月或之後的帳單',

    // 選單／輸入提示
    'all_stations'        => '全部站台',
    'select_station'      => '選擇站台',
    'select_image'        => '選擇圖片',
    'search_station_ph'   => '搜尋站台...',
    'model_type_ph'       => '例：AWS t3.medium',
    'spec_ph'             => '例：2C4G 50GB',

    // 總覽統計
    'overview_title'    => '虛擬機總覽',
    'overview_subtitle' => '即時掌握各系統運作狀態',
    'uncategorized'     => '未分類',
    'total_fee'         => '總費用',
    'total_count'       => '總計 :count 台',

    // 列表／明細
    'no_data'           => '暫無資料',
    'days_unit'         => ':days 天',
    'billing_day_text'  => '每月 :day 日',
    'proof_label'       => '繳款證明：',
    'sending'           => '發送中...',
    'sent'              => '已發送',

    // 確認視窗
    'confirm_title'      => '操作確認',
    'confirm_toggle'     => '確定要:action？',
    'confirm_mark_paid'  => '確定要標記此帳單為已收款？',
    'confirm_approve'    => '確定要審核通過此繳款？',
    'mismatch_title'     => '金額差異確認',
    'mismatch_hint'      => '以下帳單金額與目前費用不同，是否更新？',
    'mismatch_old'       => '原金額',
    'mismatch_new'       => '新金額',
    'generated_count'    => '已新增 :count 筆帳單',
    'updated_count'      => '，已更新 :count 筆金額',

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
        'month_cannot_be_past' => '只能產生本月或之後的帳單',
        'month_format_invalid' => '月份格式不正確',
        'action_failed'    => '操作失敗',
        'done'             => '完成',
        'station_not_found' => '站台不存在',
        'max_string'       => ':field 不可超過 :value 字元',
        'numeric_required' => ':field 必須為數字',
        'billing_day_range' => '帳單日必須是 1 到 :max 之間的數字',
    ],
];
