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
    // 期間比的是開單日期，留白＝不限期間
    'filter_date_from'  => '開單日期（起）',
    'filter_date_to'    => '開單日期（迄）',
    'filter_search'     => '查詢',
    'filter_reset'      => '清除條件',

    // 表格
    'col_source'  => '來源',
    'col_group'   => '對話',
    'col_sender'  => '誰說的',
    'col_text'    => '原句',
    'col_opened_at' => '開單時間',
    'col_waited'  => '等多久',
    // 「等多久」是從最後一次重新計時起算，不是從開單 —— 對方中途說過話就會往後推
    'col_waited_hint' => '從最後一次對方說話起算。對方在等待期間又說話會重新計時，所以可能比開單時間晚很多。',
    'col_counts'  => '已拖／已追問',
    'col_status'  => '狀態',
    'col_decided' => '誰決定的',
    'col_action'  => '操作',

    'counts'      => ':waits 次／:rounds 次',

    /*
     * 等多久（天／小時／分，只取最大的兩個單位）。
     *
     * ⚠ 這幾句**前端自己算**（`public/js/followup.js`）：頁面開著不動時
     * 後端給的數字會停住，所以用瀏覽器的時間算。樣板放語系檔，
     * 不要寫死在 JS 裡 —— 那會讓簡中與英文的使用者看到中文。
     */
    'waited_days'  => ':days 天 :hours 小時',
    'waited_hours' => ':hours 小時 :minutes 分',
    'waited_mins'  => ':minutes 分鐘',
    'keyword'     => '命中：:keyword',
    'empty'       => '目前沒有符合條件的待追蹤事項',
    'never_asked' => '還沒問過',

    // 操作
    'action_follow' => '追蹤',
    // 現在不決定，過一陣子再問一次
    'action_defer'  => '繼續等',
    'action_close'  => '結案',

    'confirm_follow_customer' => '確定要追蹤嗎？系統會發訊息問客人後續進度。',
    'confirm_follow_staff'    => '確定要追蹤嗎？系統會在支援群組 tag 當班人員接手處理，不會發訊息給客人。',
    // 要講明「還會再問」與「次數照算」——否則看起來跟結案或無限拖沒兩樣
    'confirm_defer'           => '這筆先不決定嗎？系統會把下一次詢問往後推，安靜一段時間後再問一次。不會發訊息給客人，也不會結案；已經問過的次數照算，所以不會無限延後。',
    'confirm_close'           => '確定結案嗎？這個話題就結束了，不會再提醒。',
    'confirm_ok'              => '確定',
    'confirm_cancel'          => '取消',

    'msg' => [
        'not_found'       => '這筆待追蹤事項已經處理過了',
        'decided'         => '已處理',
        'decide_required' => '請指定要追蹤、繼續等還是結案',
        'decide_failed'   => '處理失敗，請稍後再試',
        'load_failed'     => '載入失敗，請稍後再試',
        'date_invalid'    => '日期格式不正確',
        'range_required'  => '請選擇完整的開始與結束日期',
        'range_reversed'  => '結束日期不能早於開始日期',
    ],
];
