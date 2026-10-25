<?php

return [
    'nav_label'        => '站台列表',
    'page_title'       => '站台管理',
    'subtitle'         => '客人站台清单',
    'section_label'    => '站台管理',
    'field_name'       => '站台名称',
    'field_domain'     => '域名',
    'field_credits'    => '点数',
    'field_telegram'   => 'Telegram 群组',
    'field_status'     => '状态',
    'field_telegram_chat_id' => 'Telegram Chat ID',
    'bot_groups_title'       => '机器人群组列表',

    'action_topup_notice'  => '补点通知',

    // 补点通知按钮 disable 时的说明，缺的栏位名要讲出来
    'topup_notice_disabled' => '尚未设定 :missing，无法发送补点通知',
    'topup_notice_missing'  => [
        'api_url'           => 'API 网址',
        'api_key'           => 'API 金钥',
        'telegram_group_id' => 'Telegram 群组',
    ],

    'field_credit_alert_threshold'       => '余点告警门槛',
    'credit_alert_threshold_placeholder' => '留空沿用全域设定',
    'credit_alert_threshold_hint'        => '这个站台的余点低于此数字就发告警。留空沿用缴款设定页的全域门槛，填 0 表示这个站台不告警',
    'field_note'       => '备注',
    'status_active'    => '启用',
    'status_frozen'    => '冻结',
    'status_disabled'  => '停用',
    'no_data'          => '暂无资料',
    'action_create'    => '新增站台',
    'action_edit'      => '编辑',
    'action_detail'    => '详细',
    /*
     * 2026-10-10 重排操作栏时补的两个词。
     *
     * 原本表格最后两栏的标题都写「同步」—— 一个是最后同步时间、一个是
     * 同步动作所在的操作栏，看起来像重复；时间那栏改叫「最后同步」。
     */
    'action_change_status' => '调整状态',
    'field_synced_at'      => '最后同步',
    'field_action'         => '操作',
    /*
     * 补点列表原本「图片」与「操作」是两栏，操作栏里又混了只读的「备注」。
     * 2026-10-10 把图片与备注并成「附件」一栏（两个小图示钮），
     * 操作栏只留会改资料的通过／拒绝。
     */
    'topup_field_attachment' => '附件',
    'topup_image_count'      => ':count 张',

    // 详细信息 Modal（四格：站台信息 / 功能 / 代收 / 代付）
    'detail' => [
        'section_station'    => '站台信息',
        'section_feature'    => '功能',
        'section_deposit'    => '代收',
        'section_withdraw'   => '代付',
        'shop'               => '商城',
        'score_runner'       => '跑分员',
        'deposit_rate'       => '代收费率',
        'self_deposit_rate'  => '同系统转单代收费率',
        'usdt_deposit'       => 'USDT 代收',
        'atm_deposit'        => 'ATM 代收',
        'cvs_deposit'        => '超商代收',
        'cc_deposit'         => '信用卡',
        'qr_deposit'         => 'QR 代收',
        'withdraw'           => '代付',
        'withdraw_rate'      => '代付费率',
        'self_withdraw_rate' => '同系统转单代付费率',
        'enabled'            => '启用',
        'disabled'           => '未启用',
        'free'               => '不收费',
        'not_enabled'        => '未开启',
        'not_found'          => '找不到站台数据',
    ],

    'msg' => [
        'created'       => '站台已新增',
        'updated'       => '站台已更新',
        'create_failed' => '站台新增失败',
        'update_failed' => '站台更新失败',
        'sync_success'  => '点数同步成功',
        'sync_failed'   => '点数同步失败（API 未设定或连线异常）',

        // 手动补点通知
        'topup_notice_sent'            => '补点通知已发送给客户，当前点数 :credits',
        'topup_notice_above_threshold' => '点数高于门槛，仍依您的指示发送',
        'topup_notice_no_rate'         => '今日汇率尚未决定，只发了余点告警那则',
        'topup_notice_no_template'     => '此系统的缴款设定尚未填写补点讯息，只发了余点告警那则',
        'topup_notice_topup_failed'    => '余点告警已送出，但补点讯息那则发送失败，详见 log',
        'topup_notice_no_group'        => '此站台未设定 Telegram 群组，没有地方可以发',
        'topup_notice_sync_failed'     => '点数同步失败，没有发送（避免送出过期的数字）',
        'topup_notice_send_failed'     => '发送失败，详见 log',
    ],

    'tab_topup'           => '补点记录',
    'topup_title'         => '申请补点/扣点',
    'topup_action_add'    => '加点',
    'topup_action_deduct' => '扣点',
    'topup_credit'        => '系统点数',
    'topup_shop_credit'   => '商城点数',
    'topup_other'              => '其他',
    'topup_other_hint'         => '「其他」不会呼叫主系统 API，点数不会真的加扣，只在这里留一笔记录。备注必填。',
    'topup_other_note_required' => '点数类型选「其他」时，备注必填（否则事后查不出这笔是什么）',
    'topup_field_station'  => '站台',
    'topup_field_action'   => '操作',
    'topup_field_type'     => '点数类型',
    'topup_field_input_type'  => '输入方式',
    'topup_input_usdt'        => 'USDT 换算',
    'topup_input_credit'      => '直接输入点数',
    'topup_input_credit_hint' => '客人直接补点数时使用，此笔不列入均汇率计算',
    'topup_field_twd'         => '台币',
    'topup_field_usdt'     => 'USDT 数量',
    'topup_field_rate'     => '汇率',
    'topup_field_amount'   => '换算点数',
    'topup_field_status'   => '状态',
    'topup_field_requester' => '申请人',
    'topup_field_note'     => '备注',
    'topup_field_date_from' => '开始日期',
    'topup_field_date_to'   => '结束日期',
    'topup_status_pending'   => '待审核',
    'topup_status_completed' => '已完成',
    'topup_status_rejected'  => '已拒绝',
    'topup_status_failed'    => 'API 失败',
    'topup_submitted'      => '补点申请已送出',
    'topup_approved'       => '补点已通过，API 呼叫成功',
    'topup_rejected'       => '补点已拒绝',
    'topup_api_failed'     => '补点 API 呼叫失败',

    'credit_report' => [
        'page_title'     => '点数报表',
        'action'         => '点数报表',
        'date_from'      => '开始日期',
        'date_to'        => '结束日期',
        'search'         => '查询',
        'chart_label'    => '余点',
        'tooltip_source' => '来源',
        'summary_range'  => '期间',
        'summary_first'  => '期初余点',
        'summary_last'   => '期末余点',
        'summary_diff'   => '变化',
        'summary_failed' => '同步失败次数',
        'empty'          => '这段期间没有同步记录。',
        'load_failed'    => '载入失败，请稍后再试',
        'chart_hint'     => '每次同步余点就记一笔（每日排程、手动按同步、小帮手查余点、余点告警、虚拟机试算都算）。图上用阶梯线，因为我们只知道同步当下的数字，两次同步之间的变化无从得知。',
        'failure_title'  => '同步失败的记录',
        'failure_hint'   => '这几次同步没有拿到点数（主系统没有回应），所以曲线上是断点。',
        'failure_at'     => '时间',
        'failure_source' => '来源',
    ],

    'credit_source' => [
        'schedule' => '每日排程',
        'manual'   => '手动同步',
        'command'  => '小帮手查余点',
        'alert'    => '余点告警',
        'afford'   => '虚拟机试算',
    ],
];
