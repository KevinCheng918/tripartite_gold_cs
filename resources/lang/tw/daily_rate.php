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
    ],
];
