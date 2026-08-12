<?php

return [
    'nav_label'   => '缴款设定',
    'page_title'  => '缴款设定',
    'subtitle'    => '设定各系统的缴款资讯与文案模板',

    'field_system'     => '系统',
    'field_title'      => '缴款方式',
    'field_content'    => '缴款资讯',
    'field_template'   => '文案模板',
    'field_image'      => '缴款图片',
    'field_status'     => '状态',
    'field_sort'       => '排序',

    'template_hint'    => '可用变量：{station} 站台、{amount} 金额、{month} 月份、{due_date} 缴款日、{content} 缴款资讯。用 <code>文字</code> 包住可让 Telegram 点击复制',
    'template_example' => "【缴款通知】\n站台：{station}\n月份：{month}\n金额：<code>{amount}</code>\n\n{content}",

    'status_active'   => '启用',
    'status_disabled' => '停用',

    'action_create' => '新增缴款设定',
    'action_edit'   => '编辑',
    'action_delete' => '删除',
    'action_copy'   => '复制文案',
    'action_send'   => '发送通知',

    'msg' => [
        'created'       => '缴款设定已新增',
        'create_failed' => '新增失败',
        'updated'       => '缴款设定已更新',
        'update_failed' => '更新失败',
        'deleted'       => '缴款设定已删除',
        'delete_failed' => '删除失败',
        'copied'        => '文案已复制',
        'sent'          => '通知已发送',
        'send_failed'   => '发送失败',
        'no_config'     => '此系统尚未设定缴款资讯',
        'no_telegram'   => '此站台未设定 Telegram 群组',
    ],
];
