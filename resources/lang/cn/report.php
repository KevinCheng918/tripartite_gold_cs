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

    // 期间：自己选起讫，底下一排快捷钮（今日／昨日／本周／上周／本月／上月）
    'date_from' => '开始日期',
    'date_to'   => '结束日期',
    'search'    => '查询',

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

    // 还没人处理的那几题：摘要后面接一句，列上另外标红
    'remind_pending_badge'   => '仍未处理',
    'remind_pending_summary' => '还有 :n 题仍未处理',
    'remind_pending_none'    => '被提醒过的都已经处理完了',
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
        'load_failed'     => '加载失败，请稍后再试',
        'date_invalid'    => '日期格式不正确',
        'range_required'  => '请选择开始与结束日期',
        'range_reversed'  => '结束日期不能早于开始日期',
        'range_too_long'  => '一次最多查 :days 天，请缩短期间',
    ],
];
