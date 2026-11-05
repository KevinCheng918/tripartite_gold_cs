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

    /*
     * ⚠ **假別**跟上面的 `field_type`（整天／時段）是兩件事，
     * key 不能共用 —— 那一個講的是「請多久」，這一組講的是「請什麼假」。
     */
    'field_leave_type' => '假別',
    'leave_annual'     => '特休',
    'leave_sick'       => '病假',
    'leave_personal'   => '事假',
    /*
     * ⚠ 改版前就存在的假單。**不會出現在申請表單的選項裡**，
     * 只會出現在舊資料上。
     */
    'leave_unclassified' => '未分類',

    // 特休額度（需求方 2026-11-05）
    'quota_title'     => '本年度特休',
    'quota_remaining' => '剩餘',
    'quota_entitled'  => '額度',
    'quota_used'      => '已用',
    'quota_carry'     => '上年度遞延',
    'quota_period'    => '年度期間',
    'quota_none'      => '尚未取得特休（未滿六個月）',
    'unit_hours'      => ':value 小時',
    'unit_days'       => ':value 日',

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
        /*
         * ⚠ 一定要把**還剩多少**講出來。只說「額度不足」的話，同仁得自己
         * 回去翻剩餘數字再換算，多半就直接去問主管了。
         */
        'quota_exceeded'   => '特休額度不足，目前只剩 :hours 小時',
        // ⚠ 跟上面那句是兩種情況：這句是「還沒有」，上面那句是「有過但用完了」
        'quota_not_yet'    => '您目前尚未取得特休（受僱未滿六個月），這段期間可改申請病假或事假',
        'user_not_found'   => '找不到申請人的帳號',
        'already_reviewed' => '此請假已審核過',
        'on_leave_today'   => '今日已請假，無法打卡上班',
        'leave_blocked'    => '該日有已通過的請假，無法排班',
    ],
];
