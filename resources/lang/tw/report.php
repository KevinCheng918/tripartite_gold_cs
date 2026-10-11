<?php

/**
 * 報表（內務管理 → 報表）
 *
 * 把原本只在 Telegram 看得到的統計搬到後台。
 */

return [
    'nav_label'  => '報表',
    'page_title' => '報表',
    'subtitle'   => '打卡報表、超時提醒統計',

    'tab_attendance' => '打卡報表',
    'tab_remind'     => '超時提醒統計',

    // 期間切換
    'period_daily'   => '日報',
    'period_weekly'  => '週報',
    'period_monthly' => '月報',
    'prev'           => '上一期',
    'next'           => '下一期',
    'current'        => '最近一期',

    // 打卡報表
    'att_user'      => '同仁',
    'att_days'      => '出勤天數',
    'att_normal'    => '正常',
    'att_late'      => '遲到',
    'att_early'     => '早退',
    'att_absent'    => '曠工',
    'att_amend'     => '補打卡',
    'att_leave'     => '請假',
    'att_overtime'  => '加班',
    'att_detail_hint' => '點一列可以看那位同仁的出勤明細。',

    // 超時提醒統計
    'remind_summary'   => '共 :tickets 題被提醒、累計 :times 次，其中 :escalated 題催超過 :escalate_at 次',
    'remind_by_ticket' => '依題目',
    'remind_by_user'   => '依人員',
    'remind_question'  => '問題',
    'remind_group'     => '客人群組',
    'remind_times'     => '催幾次',
    'remind_status'    => '狀態',
    'remind_user'      => '同仁',
    'remind_tickets'   => '涉及題數',
    'remind_no_target' => '（沒 tag 到任何人）',
    'remind_manager_note' => '依人員不列主管以上 —— 他們是第三次之後才被一起 tag 的，不是該回那張單的人。',

    'empty'      => '這段期間沒有資料',
    'unit_min'   => ':n 分鐘',
    'unit_hour'  => ':n 小時',
    'unit_day'   => ':n 天',
    'unit_times' => ':n 次',

    'msg' => [
        'load_failed'  => '載入失敗，請稍後再試',
        'type_invalid' => '期間類型不正確',
        'date_invalid' => '日期格式不正確',
    ],
];
