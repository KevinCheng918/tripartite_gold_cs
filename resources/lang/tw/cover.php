<?php

return [
    'nav_label'             => '代班管理',
    'tab_my_covers'         => '我的代班',
    'tab_pending'           => '待審核',
    'tab_all'               => '所有紀錄',

    // 欄位
    'field_requester'       => '原班人',
    'field_cover_user'      => '代班人',
    'field_cover_time'      => '代班時段',
    'field_reason'          => '原因',
    'field_cover_status'    => '代班人回應',
    'field_admin_status'    => '管理者審核',
    'field_date'            => '排班日期',
    'field_shift'           => '班別',

    // 狀態
    'status_pending'        => '待確認',
    'status_approved'       => '已同意',
    'status_rejected'       => '已拒絕',
    'admin_pending'         => '待審核',
    'admin_approved'        => '已核准',
    'admin_rejected'        => '已駁回',

    // 操作
    'action_request'        => '申請代班',
    'action_approve'        => '同意',
    'action_reject'         => '拒絕',
    'action_admin_approve'  => '核准',
    'action_admin_reject'   => '駁回',

    // Modal
    'modal_request_title'   => '申請代班',
    'modal_cancel'          => '取消',
    'modal_confirm'         => '確認',
    'confirm_hint'          => '此操作無法撤回，請確認',

    // 結果
    'requested'             => '代班申請已送出',
    'cover_user_approved'   => '已同意代班',
    'cover_user_rejected'   => '已拒絕代班',
    'admin_review_approved' => '已核准代班',
    'admin_review_rejected' => '已駁回代班',

    // 驗證與錯誤訊息
    'msg' => [
        'required'              => '此欄位為必填',
        'assignment_not_found'  => '排班紀錄不存在',
        'user_not_found'        => '員工不存在',
        'invalid_time_format'   => '時間格式不正確（HH:mm）',
        'invalid_status'        => '無效的狀態值',
        'not_own_assignment'    => '只能對自己的排班申請代班',
        'cannot_cover_self'     => '不能找自己代班',
        'not_cover_user'        => '只有代班人可以回應',
        'already_responded'     => '此代班請求已回應過',
        'cover_user_not_approved' => '代班人尚未同意，無法審核',
        'already_reviewed'      => '此代班請求已審核過',
        'request_failed'        => '代班申請失敗',
        'respond_failed'        => '代班回應失敗',
        'review_failed'         => '代班審核失敗',
    ],
];
