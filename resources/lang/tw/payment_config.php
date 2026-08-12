<?php

return [
    'nav_label'   => '繳款設定',
    'page_title'  => '繳款設定',
    'subtitle'    => '設定各系統的繳款資訊與文案模板',

    'field_system'     => '系統',
    'field_title'      => '繳款方式',
    'field_content'    => '繳款資訊',
    'field_template'   => '文案模板',
    'field_image'      => '繳款圖片',
    'field_status'     => '狀態',
    'field_sort'       => '排序',

    'template_hint'    => '可用變數：{station} 站台、{amount} 金額、{month} 月份、{due_date} 繳款日、{content} 繳款資訊。用 <code>文字</code> 包住可讓 Telegram 點擊複製',
    'template_example' => "【繳款通知】\n站台：{station}\n月份：{month}\n金額：<code>{amount}</code>\n\n{content}",

    'status_active'   => '啟用',
    'status_disabled' => '停用',

    'action_create' => '新增繳款設定',
    'action_edit'   => '編輯',
    'action_delete' => '刪除',
    'action_copy'   => '複製文案',
    'action_send'   => '發送通知',

    'msg' => [
        'created'       => '繳款設定已新增',
        'create_failed' => '新增失敗',
        'updated'       => '繳款設定已更新',
        'update_failed' => '更新失敗',
        'deleted'       => '繳款設定已刪除',
        'delete_failed' => '刪除失敗',
        'copied'        => '文案已複製',
        'sent'          => '通知已發送',
        'send_failed'   => '發送失敗',
        'no_config'     => '此系統尚未設定繳款資訊',
        'no_telegram'   => '此站台未設定 Telegram 群組',
    ],
];
