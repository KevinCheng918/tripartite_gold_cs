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

    // 站台余点告警
    'alert_section_title'    => '站台余点告警',
    'alert_section_subtitle' => '每天上午 10 点自动同步各站台的系统余点，低于门槛时发 Telegram 提醒客户补点',
    'alert_field_threshold'  => '告警门槛',
    'alert_field_cooldown'   => '重复告警间隔',
    'alert_field_template'   => '告警公版',
    'alert_threshold_hint'   => '余点低于这个数字就发告警。各站台可在站台管理填自己的门槛，留空才沿用这里。填 0 表示不告警',
    'alert_cooldown_hint'    => '同一个站台几天内不重复告警。填 1 等于每天提醒一次，填 0 表示每次跑到都发',
    'alert_template_hint'    => '可用变量：{station} 站台名称、{credit} 当前余点、{threshold} 告警门槛',
    'alert_target_hint'      => '告警发到站台自己的 Telegram 群组；站台没设群组时会改发到内部支援群组，并标注「没有发给客户」提醒客服手动通知',
    'alert_preview'          => '预览',
    'alert_preview_title'    => '客户会收到的内容',
    'alert_preview_station'  => '范例站台',
    'action_save_alert'      => '储存告警设定',

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

        // 余点告警设定
        'alert_saved'              => '告警设定已储存',
        'alert_save_failed'        => '告警设定储存失败',
        'alert_template_required'  => '请填写告警公版',
        'alert_template_max'       => '告警公版不可超过 :value 字',
        'alert_threshold_required' => '请填写告警门槛',
        'alert_threshold_numeric'  => '告警门槛请填数字',
        'alert_threshold_min'      => '告警门槛不可小于 0',
        'alert_cooldown_required'  => '请填写重复告警间隔',
        'alert_cooldown_integer'   => '重复告警间隔请填整数天数',
        'alert_cooldown_max'       => '重复告警间隔不可超过 :value 天',
    ],
];
