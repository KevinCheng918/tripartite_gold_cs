<?php

return [
    'nav_label'   => '全局设置',
    'page_title'  => '全局设置',
    'subtitle'    => 'Claude 凭证、内部支援群组与对客话术',

    // Claude 主要设置
    'claude_title'        => 'Claude（自动回复）',
    'claude_token'        => '订阅 Token',
    'claude_token_hint'   => '在自己电脑执行 claude setup-token 生成，粘贴后会先验证再保存。留空代表沿用现有的。',
    'claude_model'        => '模型',
    'claude_verified_at'  => '最后验证成功',
    'claude_not_set'      => '尚未设置',

    // 如何获取订阅 Token
    'claude_howto_title'   => '如何获取订阅 Token？',
    'claude_howto_install' => '1. 在自己的电脑安装 Claude Code（三种方式择一）：',
    'claude_howto_login'   => '2. 执行 claude 并依浏览器提示登录。需要 Pro、Max、Team 或 Enterprise 订阅，免费的 claude.ai 帐号不支持。',
    'claude_howto_token'   => '3. 登录后执行下列命令生成长效 Token：',
    'claude_howto_paste'   => '4. 依画面指示完成授权，把终端输出的 Token 复制粘贴到上方栏位后保存。系统会先验证再存，验不过不会动到原本的设置。',
    'claude_howto_note'    => '这把 Token 绑定执行者的订阅帐号 —— 那个人的订阅到期或取消，Token 就会失效。',

    // 备援
    'fallback_title'       => 'Claude（备援 API）',
    'fallback_desc'        => '订阅额度用尽时自动接手。会实际产生 API 费用，请设置每日上限。',
    'fallback_enabled'     => '启用备援',
    'fallback_api_key'     => 'API Key',
    'fallback_api_key_hint' => '从 Claude Console 获取。留空代表沿用现有的。',
    'fallback_model'       => '备援模型',
    'fallback_daily_limit' => '每日调用上限',
    'fallback_daily_limit_hint' => '0 代表不限制。超过上限后一律改走人工。',
    'fallback_used_today'  => '今日已用',

    // 如何获取 API Key
    'fallback_howto_title'  => '如何获取 API Key？',
    'fallback_howto_open'   => '1. 前往 Claude Console 并登录：',
    'fallback_howto_create' => '2. 左侧菜单进入 Settings → API Keys，点「Create Key」。',
    'fallback_howto_paste'  => '3. 复制生成的密钥粘贴到上方栏位后保存。密钥只会完整显示一次，关掉窗口就看不到了。',
    'fallback_howto_note'   => 'API 与订阅是分开计费的两件事 —— 帐户要先有余额，备援才用得起来。',

    // 内部支援群组
    'support_title'         => '内部支援群组',
    'support_desc'          => '题库里找不到答案时，问题会转到这个群组请自己人回答。',
    'support_chat_id'       => '群组 chat_id',
    'support_chat_id_hint'  => '群组的 chat_id 是负数。Bot 必须已加入群组，且 Group Privacy 要关闭。',
    'support_system'        => '使用的 Bot',
    'support_system_default' => '默认 Bot（.env）',
    'support_remind_first'  => '第一次提醒（分钟）',
    'support_remind_first_hint' => '超过这个时间没人回答，tag 当下排班的人员。',
    'support_remind_second' => '第二次提醒（分钟）',
    'support_remind_second_hint' => '再过这个时间还是没人回，tag 主管与老板。两阶段都会跳过工程。',
    'support_test'          => '发送测试消息',

    // 用量
    'usage_title'        => '用量流量',
    'usage_desc'         => '只要有调用到 Claude 就计一次，成功、失败、撞限额都算。',
    'usage_today'        => '今日',
    'usage_month'        => '本月',
    'usage_subscription' => '订阅',
    'usage_fallback'     => '备援 API',
    'usage_calls'        => '调用次数',
    'usage_errors'       => '失败',
    'usage_rate_limited' => '撞限额',
    'usage_avg_duration' => '平均耗时',
    'usage_cost'         => '预估费用',
    'usage_cost_hint'    => '依设置的单价估算，实际金额以 Anthropic Console 为准。',

    'action_save'   => '保存',
    'action_saving' => '保存中...',
    'action_testing' => '测试中...',

    'msg' => [
        'saved'         => '已保存',
        'save_failed'   => '保存失败',
        'load_failed'   => '设置加载失败',
        'token_max'     => '凭证长度过长',
        'token_invalid' => '这把 Token 无法通过验证，原本的设置没有变动',
        'api_key_invalid' => '这把 API Key 无法通过验证，原本的设置没有变动',
        'model_required' => '请选择模型',
        'model_invalid' => '不支持的模型',
        'daily_limit_required' => '请填写每日调用上限',
        'daily_limit_invalid'  => '每日调用上限须为 0 以上的整数',
        'chat_id_invalid' => 'chat_id 格式不正确（群组为负数）',
        'chat_id_is_customer' => '这个群组已经是客服对话，不能当内部支援群组使用（该群组的客户讯息会全部被拦下，收不到也不会自动回覆）。请改用另一个独立群组，或先到 Telegram 客服删除该对话。',
        'system_not_found' => '找不到指定的系统',
        'remind_required' => '请填写提醒时间',
        'remind_invalid'  => '提醒时间须介于 1 到 1440 分钟',
        'support_test_sent'   => '测试消息已发出，请到群组确认',
        'support_test_failed' => '测试消息发送失败，请确认 chat_id、Bot 是否已加入群组、以及 Group Privacy 是否关闭',
    ],
];
