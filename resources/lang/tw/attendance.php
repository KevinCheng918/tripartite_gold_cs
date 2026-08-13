<?php

return [
    'nav_label'          => '打卡出勤',
    'page_title'         => '打卡出勤',
    'subtitle'           => '上下班打卡與出勤紀錄',

    // 打卡狀態
    'not_clocked'        => '尚未打卡',
    'clocked_in'         => '已上班打卡',
    'clocked_out'        => '已下班打卡',
    'current_status'     => '目前狀態',
    'btn_clock_in'       => '上班打卡',
    'btn_clock_out'      => '下班打卡',

    // 出勤狀態
    'status_incomplete'  => '未完成',
    'status_normal'      => '正常',
    'status_late'        => '遲到',
    'status_early_leave' => '早退',
    'status_late_early'  => '遲到+早退',
    'status_absent'      => '曠工',

    // 欄位
    'field_date'         => '日期',
    'field_clock_in'     => '上班打卡',
    'field_clock_out'    => '下班打卡',
    'field_late'         => '遲到',
    'field_early_leave'  => '早退',
    'field_overtime'     => '加班',
    'field_status'       => '狀態',
    'field_ip'           => 'IP',
    'field_device'       => '裝置',
    'unit_minutes'       => '分鐘',

    // 月報表
    'report_title'       => '月報表',
    'field_user'         => '員工',
    'field_total_days'   => '出勤天數',
    'field_normal_days'  => '正常天數',
    'field_late_count'   => '遲到次數',
    'field_late_total'   => '遲到總分鐘',
    'field_early_count'  => '早退次數',
    'field_early_total'  => '早退總分鐘',
    'field_absent_count' => '曠工次數',
    'field_overtime_total' => '加班總分鐘',
    'tab_my_records'     => '我的出勤',
    'tab_report'         => '月報表',

    // 月份切換
    'detail_title'       => '出勤明細',
    'detail_subtitle'    => '個人每日出勤狀況',
    'back_to_report'     => '返回月報表',
    'this_month'         => '本月',
    'last_month'         => '上個月',

    // 訊息
    'msg' => [
        'already_clocked_in'  => '今日已經上班打卡了',
        'not_clocked_in'      => '尚未上班打卡，無法下班打卡',
        'already_clocked_out' => '今日已經下班打卡了',
        'clock_in_success'    => '上班打卡成功',
        'clock_out_success'   => '下班打卡成功',
        'clock_in_failed'     => '上班打卡失敗',
        'clock_out_failed'    => '下班打卡失敗',
        'previous_not_clocked_out' => '前一班尚未下班打卡，請先完成下班打卡',
        'confirm'             => '確認打卡',
        'cancel'              => '取消',
        'clock_time'          => '打卡時間',
        'confirm_hint'        => '確認後將無法修改，請確認資訊正確',
    ],

    // 補打卡
    'tab_amend'          => '補打卡審核',
    'amend_title'        => '申請補打卡',
    'amend_type_in'      => '補上班卡',
    'amend_type_out'     => '補下班卡',
    'amend_field_date'   => '日期',
    'amend_field_type'   => '類型',
    'amend_field_time'   => '時間',
    'amend_field_reason' => '原因',
    'amend_field_status' => '狀態',
    'amend_status_pending'  => '待審核',
    'amend_status_approved' => '已通過',
    'amend_status_rejected' => '已拒絕',
    'amend_submitted'       => '補打卡申請已送出',
    'amend_approved'        => '補打卡已通過',
    'amend_rejected'        => '補打卡已拒絕',
    'amend_duplicate'       => '已有相同的補打卡申請待審核',
    'amend_already_reviewed' => '此申請已審核過',
    'amend_my_records'      => '我的補打卡申請',
];
