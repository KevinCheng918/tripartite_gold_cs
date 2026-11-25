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
    // 撤假通過之後原單的狀態（⚠ 不是「被拒絕」，是申請人自己收回的）
    'status_cancelled' => '已撤銷',

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

        /*
         * 撤銷請假（需求方 2026-11-24）。
         *
         * ⚠ 「取消」與「撤銷」的訊息要分開：前者是還沒生效的單按了就掉，
         * 後者要經過同意 —— 用同一句會讓人以為自己已經撤掉了。
         */
        'cancel_pending_done'      => '已取消這筆請假申請',
        'cancel_requested'         => '已送出撤銷申請，請等候審核',
        'cancel_approved'          => '撤銷請假已通過',
        'cancel_rejected'          => '撤銷請假已拒絕',
        'cancel_not_owner'         => '只能撤銷自己的請假',
        'cancel_not_pending'       => '這筆請假不是待審核狀態，請改用「申請撤銷」',
        'cancel_not_approved'      => '只有已通過的請假才需要申請撤銷',
        // ⚠ 比的是開始日：請到一半的假不算「還沒開始」
        'cancel_already_started'   => '這段假已經開始或已經過去了，沒辦法撤銷',
        'cancel_already_requested' => '這筆請假已經有一筆撤銷申請在等候審核',
        'cancel_already_reviewed'  => '這筆撤銷申請已經審核過了',
        'cancel_status_invalid'    => '審核結果不正確',
        'cancel_reason_max'        => '撤銷原因過長（上限 500 字）',
        'not_found'                => '找不到這筆請假',

        // 撤假的畫面文字
        'cancel_action'        => '申請撤銷',
        'cancel_pending_action' => '取消申請',
        'cancel_title'         => '申請撤銷請假',
        'cancel_reason_label'  => '撤銷原因（可不填）',
        'cancel_review_title'  => '審核撤銷請假',
        'cancel_status_badge'  => '撤銷審核中',
        'cancel_cancelled_badge' => '已撤銷',

        /*
         * ⚠⚠ **TEMP-CANCEL-KEEP-QUOTA**：審核視窗那個勾選框。
         *
         * ⚠ 文案用需求方的說法（「加回被扣的時數」），
         * **不要寫成「不再計入已用時數」** —— 後者是實作的語言，
         * 而按這顆的人想的是前者。
         *
         * ⚠ 需求方 2026-11-24：正式站手動處理完就可以把這個勾選拿掉。
         */
        'cancel_restore_quota'      => '加回被扣的特休時數',
        'cancel_restore_quota_hint' => '預設打勾。不打勾就只是單純銷假，這段特休仍然算已經用掉。',
        'on_leave_today'   => '今日已請假，無法打卡上班',
        'leave_blocked'    => '該日有已通過的請假，無法排班',
    ],
];
