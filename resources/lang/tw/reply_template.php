<?php

return [
    'nav_label'  => '對客話術',
    'page_title' => '對客話術',
    'subtitle'   => '自動回覆送給客人的訊息內容',

    'intro'       => '這是客人唯一看得到的東西。答案內容取自題庫原文，這裡設定的是包在外層的語氣。',
    'signature_hint' => '送出時會自動在結尾加上署名 :signature，模板本身不用寫。',

    'full'       => '完整版',
    'short'      => '精簡版',
    'full_hint'  => '距離上次自動回覆超過 :minutes 分鐘時使用。',
    'short_hint' => '連續對話時使用。',

    'answer'  => '命中題庫',
    'wait'    => '題庫裡沒有（稍等）',
    'support' => '支援群組的回答轉給客人',

    'answer_desc'  => '從題庫挑到答案時送出。開頭那句由 AI 依客人的話生成，這裡只放答案外框。',
    'wait_desc'    => 'AI 的承接句無法使用時的退路，同時會把問題轉到內部支援群組。',
    'support_desc' => '同仁在支援群組回答後，按下「回覆客人」時送出。',

    'canned_warning' => '⚠️ 在這裡加固定的問候語或結尾語，客人連著問就會看到一模一樣的句子。開頭已由 AI 承接，建議只留 {答案}。',

    'var_required' => '必須包含 :value',

    'action_save'   => '儲存',
    'action_saving' => '儲存中...',

    'msg' => [
        'saved'         => '話術已更新',
        'save_failed'   => '話術更新失敗',
        'load_failed'   => '話術載入失敗',
        'required'      => '話術模板不能留空，否則會送不出訊息',
        'max'           => '話術模板不可超過 :value 字',
        'placeholder_missing' => '模板必須保留 :value 變數，否則客人會收到沒有內容的訊息',
    ],
];
