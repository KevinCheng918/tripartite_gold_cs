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

    // 期間：自己選起訖，底下一排快捷鈕（今日／昨日／本週／上週／本月／上月）
    'date_from' => '開始日期',
    'date_to'   => '結束日期',
    'search'    => '查詢',

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

    // 還沒人處理的那幾題：摘要後面接一句，列上另外標紅
    'remind_pending_badge'   => '仍未處理',
    'remind_pending_summary' => '還有 :n 題仍未處理',
    'remind_pending_none'    => '被提醒過的都已經處理完了',
    'remind_by_ticket' => '依題目',
    'remind_by_user'   => '依人員',
    'remind_question'  => '問題',
    'remind_group'     => '客人群組',
    'remind_times'     => '催幾次',
    'remind_status'    => '狀態',
    'remind_user'      => '同仁',
    'remind_tickets'   => '涉及題數',
    'remind_no_target' => '（沒 tag 到任何人）',

    /*
     * 「標記為已處理」：報表上那個紅標唯一的出口。
     *
     * ⚠ 確認文字要講明它**不會回覆客人** —— 這顆按鈕只是把單結掉，
     * 按的人很容易以為系統會順便幫他回。
     */
    'remind_action'       => '操作',
    'remind_mark_handled' => '標記為已處理',
    'remind_confirm_mark' => '確定把這一題標記為「客服已自行處理」嗎？系統會停止提醒，並在支援群組通知一聲。這不會回覆客人，也不會加入題庫。',
    'confirm_ok'          => '確定',
    'confirm_cancel'      => '取消',
    'remind_manager_note' => '依人員不列主管以上 —— 他們是第三次之後才被一起 tag 的，不是該回那張單的人。',

    'empty'      => '這段期間沒有資料',
    'unit_min'   => ':n 分鐘',
    'unit_hour'  => ':n 小時',
    'unit_day'   => ':n 天',
    'unit_times' => ':n 次',

    'msg' => [
        'load_failed'     => '載入失敗，請稍後再試',
        'date_invalid'    => '日期格式不正確',
        'range_required'  => '請選擇開始與結束日期',
        'range_reversed'  => '結束日期不能早於開始日期',
        'range_too_long'  => '一次最多查 :days 天，請縮短期間',

        // 標記為已處理
        'ticket_handled'  => '已標記為處理完畢，系統不會再提醒這一題',
        'ticket_missing'  => '找不到這張求助單，可能已經被刪除了',
        'ticket_done'     => '這張單剛剛已經被處理過了，重新查詢就會看到最新狀態',
        'handle_failed'   => '標記失敗，請稍後再試',
    ],
];
