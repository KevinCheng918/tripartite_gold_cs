<?php

return [
    'tab_title'       => '請假管理',
    'tab_my_leave'    => '我的請假',
    'tab_review'      => '請假審核',

    'action_apply'    => '申請請假',

    'field_user'      => '申請人',
    'field_date'      => '日期',
    'field_type'      => '類型',
    'field_time'      => '時段',
    'field_reason'    => '原因',
    'field_status'    => '狀態',

    /*
     * 審核紀錄：誰審的、什麼時候審的。
     *
     * ⚠ 通過與拒絕都要顯示（需求方 2026-10-12）—— 被拒絕的人更需要知道
     * 是什麼時候被回絕的，才判斷得出要不要重新申請。
     */
    'reviewed_by_at'  => ':name · :time 審核',
    'reviewed_at'     => ':time 審核',

    'type_full_day'   => '整天',
    'type_hours'      => '時段',

    'status_pending'  => '待審核',
    'status_approved' => '已通過',
    'status_rejected' => '已拒絕',

    'msg' => [
        'submitted'        => '請假申請已送出',
        'approved'         => '請假已通過',
        'rejected'         => '請假已拒絕',
        'overlap'          => '該日期區間已有請假申請',
        'already_reviewed' => '此請假已審核過',
        'on_leave_today'   => '今日已請假，無法打卡上班',
        'leave_blocked'    => '該日有已通過的請假，無法排班',
    ],
];
