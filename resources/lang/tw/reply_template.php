<?php

return [
    'nav_label'  => '對客話術',
    'page_title' => '對客話術',
    'subtitle'   => '自動回覆送給客人的訊息內容',

    'intro'       => '這是客人唯一看得到的東西。答案內容取自題庫原文，這裡設定的是包在外層的語氣。',
    'signature_hint' => '送出時會自動在結尾加上署名 :signature，模板本身不用寫。',

    'full'       => '完整版',
    'short'      => '精簡版',
    'full_hint'  => '距離上次自動回覆超過 :minutes 分鐘時使用，含問候語。',
    'short_hint' => '連續對話時使用，省略開頭問候避免顯得罐頭。',

    'answer'  => '命中題庫',
    'clarify' => '沒把握，反問客人',
    'wait'    => '題庫裡沒有（稍等）',
    'support' => '支援群組的回答轉給客人',

    'answer_desc'  => '從題庫挑到答案時送出。',
    'clarify_desc' => '同時像好幾題時，客氣地請客人確認是哪一項。',
    'wait_desc'    => '題庫裡沒有答案時送出，同時會把問題轉到內部支援群組。',
    'support_desc' => '同仁在支援群組回答後，按下「回覆客人」時送出。',

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
