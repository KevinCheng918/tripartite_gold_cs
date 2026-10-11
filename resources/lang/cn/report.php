<?php

/**
 * 报表（内务管理 → 报表）
 *
 * 把原本只在 Telegram 看得到的统计搬到后台。
 */

return [
    'nav_label'  => '报表',
    'page_title' => '报表',
    'subtitle'   => '打卡报表、超时提醒统计',

    'tab_attendance' => '打卡报表',
    'tab_remind'     => '超时提醒统计',

    // 期间切换
    'period_daily'   => '日报',
    'period_weekly'  => '周报',
    'period_monthly' => '月报',
    'prev'           => '上一期',
    'next'           => '下一期',
    'current'        => '最近一期',

    // 打卡报表
    'att_user'      => '同事',
    'att_days'      => '出勤天数',
    'att_normal'    => '正常',
    'att_late'      => '迟到',
    'att_early'     => '早退',
    'att_absent'    => '旷工',
    'att_amend'     => '补打卡',
    'att_leave'     => '请假',
    'att_overtime'  => '加班',
    'att_detail_hint' => '点一行可以看那位同事的出勤明细。',

    // 超时提醒统计
    'remind_summary'   => '共 :tickets 题被提醒、累计 :times 次，其中 :escalated 题催超过 :escalate_at 次',
    'remind_by_ticket' => '依题目',
    'remind_by_user'   => '依人员',
    'remind_question'  => '问题',
    'remind_group'     => '客人群组',
    'remind_times'     => '催几次',
    'remind_status'    => '状态',
    'remind_user'      => '同事',
    'remind_tickets'   => '涉及题数',
    'remind_no_target' => '（没 tag 到任何人）',
    'remind_manager_note' => '依人员不列主管以上 —— 他们是第三次之后才被一起 tag 的，不是该回那张单的人。',

    'empty'      => '这段期间没有资料',
    'unit_min'   => ':n 分钟',
    'unit_hour'  => ':n 小时',
    'unit_day'   => ':n 天',
    'unit_times' => ':n 次',

    'msg' => [
        'load_failed'  => '加载失败，请稍后再试',
        'type_invalid' => '期间类型不正确',
        'date_invalid' => '日期格式不正确',
    ],
];
