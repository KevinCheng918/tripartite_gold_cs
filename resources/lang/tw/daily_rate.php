<?php

return [
    'nav_label'  => '每日匯率',
    'page_title' => '每日匯率',
    'subtitle'   => '每天上午 9 點在內部支援群組報匯率，由同仁回覆決定當日對客報價',

    'today_title'    => '今日匯率',
    'today_pending'  => '尚未決定',
    'today_hint'     => '匯率還沒決定前，客人問匯率會先請他稍候',

    'field_date'           => '日期',
    'field_rate'           => '對客報價',
    'field_reference_rate' => '4H 均價',
    'field_suggested_rate' => '建議報價',
    'field_yesterday_rate' => '上次報價',
    'field_replier'        => '決定者',
    'field_replied_at'     => '決定時間',
    'field_remind_count'   => '提醒次數',

    'template_title'    => '報價公版',
    'template_hint'     => '可用變數：{date} 日期、{reference} 4H 均價、{suggested} 建議報價、{yesterday} 上次報價、{yesterday_date} 上次報價日期',
    'template_preview'  => '預覽',
    'template_preview_title' => '同仁會在群組看到的內容',

    'action_edit'    => '修改',
    'action_save'    => '儲存',
    'action_preview' => '預覽',

    'edit_title' => '設定匯率',
    'edit_hint'  => '手動設定會蓋掉群組回覆的結果。可以補登過去的日期，但不能填未來',

    'empty' => '還沒有任何匯率紀錄',

    // 走勢圖截圖
    'screenshot_title'     => '走勢圖截圖',
    'screenshot_ready'     => '這台機器可以截圖',
    'screenshot_missing'   => '這台機器沒有可用的 Chrome，報價時只會發文字',
    'screenshot_hint'      => '報價時會附上 MAX 的走勢圖。截不到圖也不影響報價，只是沒有圖',
    'screenshot_arm_note'  => '這台是 arm64，沒有官方的 Linux Chrome，裝不起來。正式機（x86_64）才截得到圖',
    'screenshot_install'   => '安裝方式（x86_64 Linux）：apt-get install -y google-chrome-stable',
    'action_test_shot'     => '測試截圖',
    'action_ask_now'       => '立即報價',
    'ask_now_hint'         => '把早上 9 點那套完整跑一次並送到內部群組，可以直接在群組引用回覆測試',
    'ask_now_confirm'      => '會真的發送報價到內部支援群組，並覆蓋今天已經報過的那則。確定嗎？',

    'msg' => [
        'saved'                 => '匯率已儲存',
        'save_failed'           => '匯率儲存失敗',
        'template_saved'        => '公版已儲存',
        'template_save_failed'  => '公版儲存失敗',
        'date_required'         => '請選擇日期',
        'date_future'           => '不能設定未來的匯率',
        'rate_required'         => '請填寫匯率',
        'rate_numeric'          => '匯率請填數字',
        'rate_min'              => '匯率必須大於 0',
        'template_required'     => '請填寫報價公版',
        'template_max'          => '報價公版不可超過 :value 字',

        'ask_sent'            => '報價已發到內部支援群組（這次沒有附圖）',
        'ask_sent_with_chart' => '報價已發到內部支援群組，含走勢圖',
        'ask_no_support_group' => '尚未設定內部支援群組，沒有地方可以報',
        'ask_send_failed'      => '報價發送失敗，詳見 log',
        'ask_already_asked'    => '今天已經報過了',

        'screenshot_sent'             => '截圖已發到內部支援群組（使用 :binary）',
        'screenshot_no_chrome'        => '這台機器沒有可用的 Chrome，無法截圖',
        'screenshot_no_support_group' => '尚未設定內部支援群組，沒有地方可以發',
        'screenshot_capture_failed'   => '截圖失敗，詳見 log（可能是網站太慢或等待時間不夠）',
        'screenshot_send_failed'      => '截到圖了但發送失敗，詳見 log',
    ],
];
