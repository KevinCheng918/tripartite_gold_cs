<?php

return [
    'nav_label'  => '对客话术',
    'page_title' => '对客话术',
    'subtitle'   => '自动回复发给客人的消息内容',

    'intro'       => '这是客人唯一看得到的东西。答案内容取自题库原文，这里设置的是包在外层的语气。',
    'signature_hint' => '发送时会自动在结尾加上署名 :signature，模板本身不用写。',

    'full'       => '完整版',
    'short'      => '精简版',
    'full_hint'  => '距离上次自动回复超过 :minutes 分钟时使用。',
    'short_hint' => '连续对话时使用。',

    'answer'  => '命中题库',
    'wait'    => '题库里没有（稍等）',
    'support' => '支援群组的回答转给客人',

    'answer_desc'  => '从题库挑到答案时发送。开头那句由 AI 依客人的话生成，这里只放答案外框。',
    'wait_desc'    => 'AI 的承接句无法使用时的退路，同时会把问题转到内部支援群组。',
    'support_desc' => '同仁在支援群组回答后，按下「回复客人」时发送。',

    'canned_warning' => '⚠️ 在这里加固定的问候语或结尾语，客人连着问就会看到一模一样的句子。开头已由 AI 承接，建议只留 {答案}。',

    'var_required' => '必须包含 :value',

    'action_save'   => '保存',
    'action_saving' => '保存中...',

    'msg' => [
        'saved'         => '话术已更新',
        'save_failed'   => '话术更新失败',
        'load_failed'   => '话术加载失败',
        'required'      => '话术模板不能留空，否则会发不出消息',
        'max'           => '话术模板不可超过 :value 字',
        'placeholder_missing' => '模板必须保留 :value 变量，否则客人会收到没有内容的消息',
    ],
];
