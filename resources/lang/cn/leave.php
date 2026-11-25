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

    'field_leave_type' => '假别',
    'leave_annual'     => '特休',
    'leave_sick'       => '病假',
    'leave_personal'   => '事假',
    'leave_unclassified' => '未分类',

    'quota_title'     => '本年度特休',
    'quota_remaining' => '剩余',
    'quota_entitled'  => '额度',
    'quota_used'      => '已用',
    'quota_carry'     => '上年度递延',
    'quota_period'    => '年度期间',
    'quota_none'      => '尚未取得特休（未满六个月）',
    'unit_hours'      => ':value 小时',
    'unit_days'       => ':value 日',

    'type_full_day'   => '整天',
    'type_hours'      => '时段',

    'status_pending'  => '待审核',
    'status_approved' => '已通过',
    'status_rejected' => '已拒绝',
    // 撤假通过之后原单的状态（⚠ 不是「被拒绝」，是申请人自己收回的）
    'status_cancelled' => '已撤销',

    'msg' => [
        'submitted'        => '请假申请已送出',
        'approved'         => '请假已通过',
        'rejected'         => '请假已拒绝',
        'overlap'          => '该日期区间已有请假申请',
        'quota_exceeded'   => '特休额度不足，目前只剩 :hours 小时',
        // ⚠ 跟上面那句是两种情况：这句是「还没有」，上面那句是「有过但用完了」
        'quota_not_yet'    => '您目前尚未取得特休（受雇未满六个月），这段期间可改申请病假或事假',
        'user_not_found'   => '找不到申请人的账号',
        'already_reviewed' => '此请假已审核过',

        // 撤销请假。⚠「取消」与「撤销」的讯息要分开
        'cancel_pending_done'      => '已取消这笔请假申请',
        'cancel_requested'         => '已送出撤销申请，请等候审核',
        'cancel_approved'          => '撤销请假已通过',
        'cancel_rejected'          => '撤销请假已拒绝',
        'cancel_not_owner'         => '只能撤销自己的请假',
        'cancel_not_pending'       => '这笔请假不是待审核状态，请改用「申请撤销」',
        'cancel_not_approved'      => '只有已通过的请假才需要申请撤销',
        'cancel_already_started'   => '这段假已经开始或已经过去了，没办法撤销',
        'cancel_already_requested' => '这笔请假已经有一笔撤销申请在等候审核',
        'cancel_already_reviewed'  => '这笔撤销申请已经审核过了',
        'cancel_status_invalid'    => '审核结果不正确',
        'cancel_reason_max'        => '撤销原因过长（上限 500 字）',
        'not_found'                => '找不到这笔请假',

        'cancel_action'          => '申请撤销',
        'cancel_pending_action'  => '取消申请',
        'cancel_title'           => '申请撤销请假',
        'cancel_reason_label'    => '撤销原因（可不填）',
        'cancel_review_title'    => '审核撤销请假',
        'cancel_status_badge'    => '撤销审核中',
        'cancel_cancelled_badge' => '已撤销',

        // ⚠ TEMP-CANCEL-KEEP-QUOTA：文案用需求方的说法，不要写成实作语言
        'cancel_restore_quota'      => '加回被扣的特休时数',
        'cancel_restore_quota_hint' => '预设打勾。不打勾就只是单纯销假，这段特休仍然算已经用掉。',
        'on_leave_today'   => '今日已请假，无法打卡上班',
        'leave_blocked'    => '该日有已通过的请假，无法排班',
    ],
];
