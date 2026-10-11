<?php

return [
    'nav_label'  => '待追蹤事項',
    'page_title' => '待追蹤事項',
    'subtitle'   => '對話裡有人說「稍等、晚點給你」就會記在這裡',

    'section_label' => '客服管理',

    'desc' => '平常由當班人員在支援群組按按鈕決定；這一頁是想看全部、或那則按鈕訊息被洗掉時的入口。',

    // 篩選
    'filter_source'     => '來源',
    'filter_status'     => '狀態',
    'filter_group'      => '對話',
    'filter_all'        => '全部',
    'filter_open_only'  => '只看未結案',
    'filter_search'     => '查詢',
    'filter_reset'      => '清除條件',

    // 表格
    'col_source'  => '來源',
    'col_group'   => '對話',
    'col_sender'  => '誰說的',
    'col_text'    => '原句',
    'col_waited'  => '等多久',
    'col_counts'  => '已拖／已追問',
    'col_status'  => '狀態',
    'col_decided' => '誰決定的',
    'col_action'  => '操作',

    'counts'      => ':waits 次／:rounds 次',
    'keyword'     => '命中：:keyword',
    'empty'       => '目前沒有符合條件的待追蹤事項',
    'never_asked' => '還沒問過',

    // 操作
    'action_follow' => '追蹤',
    'action_close'  => '結案',

    'confirm_follow_customer' => '確定要追蹤嗎？系統會發訊息問客人後續進度。',
    'confirm_follow_staff'    => '確定要追蹤嗎？系統會在支援群組 tag 當班人員接手處理，不會發訊息給客人。',
    'confirm_close'           => '確定結案嗎？這個話題就結束了，不會再提醒。',
    'confirm_ok'              => '確定',
    'confirm_cancel'          => '取消',

    'msg' => [
        'not_found'       => '這筆待追蹤事項已經處理過了',
        'decided'         => '已處理',
        'decide_required' => '請指定要追蹤還是結案',
        'decide_failed'   => '處理失敗，請稍後再試',
        'load_failed'     => '載入失敗，請稍後再試',
    ],
];
