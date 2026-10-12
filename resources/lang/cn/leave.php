<?php

return [
    'tab_title'       => '请假管理',
    'tab_my_leave'    => '我的请假',
    'tab_review'      => '请假审核',

    'action_apply'    => '申请请假',

    'field_user'      => '申请人',
    'field_date'      => '日期',
    'field_type'      => '类型',
    'field_time'      => '时段',
    'field_reason'    => '原因',
    'field_status'    => '状态',

    /*
     * 审核纪录：谁审的、什么时候审的。
     *
     * ⚠ 通过与拒绝都要显示（需求方 2026-10-12）—— 被拒绝的人更需要知道
     * 是什么时候被回绝的，才判断得出要不要重新申请。
     */
    'reviewed_by_at'  => ':name · :time 审核',
    'reviewed_at'     => ':time 审核',

    'type_full_day'   => '整天',
    'type_hours'      => '时段',

    'status_pending'  => '待审核',
    'status_approved' => '已通过',
    'status_rejected' => '已拒绝',

    'msg' => [
        'submitted'        => '请假申请已送出',
        'approved'         => '请假已通过',
        'rejected'         => '请假已拒绝',
        'overlap'          => '该日期区间已有请假申请',
        'already_reviewed' => '此请假已审核过',
        'on_leave_today'   => '今日已请假，无法打卡上班',
        'leave_blocked'    => '该日有已通过的请假，无法排班',
    ],
];
