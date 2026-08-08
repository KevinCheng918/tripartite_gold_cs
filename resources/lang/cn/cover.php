<?php

return [
    'nav_label'             => '代班管理',
    'tab_my_covers'         => '我的代班',
    'tab_pending'           => '待审核',
    'tab_all'               => '所有记录',

    'field_requester'       => '原班人',
    'field_cover_user'      => '代班人',
    'field_cover_time'      => '代班时段',
    'field_reason'          => '原因',
    'field_cover_status'    => '代班人回应',
    'field_admin_status'    => '管理者审核',
    'field_date'            => '排班日期',
    'field_shift'           => '班别',

    'status_pending'        => '待确认',
    'status_approved'       => '已同意',
    'status_rejected'       => '已拒绝',
    'admin_pending'         => '待审核',
    'admin_approved'        => '已核准',
    'admin_rejected'        => '已驳回',

    'action_request'        => '申请代班',
    'action_approve'        => '同意',
    'action_reject'         => '拒绝',
    'action_admin_approve'  => '核准',
    'action_admin_reject'   => '驳回',

    'modal_request_title'   => '申请代班',
    'modal_cancel'          => '取消',
    'modal_confirm'         => '确认',
    'confirm_hint'          => '此操作无法撤回，请确认',

    'requested'             => '代班申请已送出',
    'cover_user_approved'   => '已同意代班',
    'cover_user_rejected'   => '已拒绝代班',
    'admin_review_approved' => '已核准代班',
    'admin_review_rejected' => '已驳回代班',

    'msg' => [
        'required'              => '此栏位为必填',
        'assignment_not_found'  => '排班记录不存在',
        'user_not_found'        => '员工不存在',
        'invalid_time_format'   => '时间格式不正确（HH:mm）',
        'invalid_status'        => '无效的状态值',
        'not_own_assignment'    => '只能对自己的排班申请代班',
        'cannot_cover_self'     => '不能找自己代班',
        'not_cover_user'        => '只有代班人可以回应',
        'already_responded'     => '此代班请求已回应过',
        'cover_user_not_approved' => '代班人尚未同意，无法审核',
        'already_reviewed'      => '此代班请求已审核过',
        'request_failed'        => '代班申请失败',
        'respond_failed'        => '代班回应失败',
        'review_failed'         => '代班审核失败',
    ],
];
