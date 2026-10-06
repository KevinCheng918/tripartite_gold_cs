<?php

return [
    'nav_label'  => '通知设置',
    'page_title' => '通知设置',
    'subtitle'   => '支援群组与话题、求助单提醒、班表通知、提醒统计',

    'section_label' => '通讯管理',

    'tab_group'  => '支援群组与话题',
    'tab_remind' => '求助单提醒',
    'tab_shift'  => '班表通知',
    'tab_report' => '超时提醒统计',

    'select_all' => '全选',
    'unbound'    => '未绑定',

    // ===== 内部支援群组 =====
    'support_title'          => '内部支援群组',
    'support_desc'           => '题库里找不到答案时，问题会转到这个群组请自己人回答。',
    'support_chat_id'        => '群组 chat_id',
    'support_chat_id_hint'   => '群组的 chat_id 是负数。Bot 必须已加入群组，且 Group Privacy 要关闭。',
    'support_system'         => '使用的 Bot',
    'support_system_default' => '默认 Bot（.env）',
    'support_test'           => '发送测试消息',

    // ===== 求助单提醒 =====
    'remind_title'         => '求助单超时提醒',
    'remind_desc'          => 'AI 答不出来转到支援群组的问题，没人回答就会一直提醒到有人处理。第 1、2 次 tag 当班人员，第 :escalate 次起同时 tag 主管与老板（都跳过工程）。',
    'remind_first'         => '第一次提醒（分钟）',
    'remind_first_hint'    => '开单后超过这个时间没人回答，tag 当下排班的人员。',
    'remind_interval'      => '之后每隔（分钟）',
    'remind_interval_hint' => '第一次之后每隔这个时间再提醒一次，一直催到问题被处理。第 :escalate 次起会同时 tag 主管与老板（都跳过工程）。',
    'remind_max'           => '最多提醒几次',
    'remind_max_hint'      => '到这个次数仍没处理就停止提醒，并发最后一则通知主管与老板。避免深夜没人值班时被连续轰炸。',

    // ===== 每日统计 =====
    'report_title'     => '每日提醒统计',
    'report_desc'      => '每天 :time 把前一天的超时提醒统计私信出去。勾选的人收「全部人的」（依题目、依人员），被提醒到的同事另外会各自收到「自己那份」，不需要设置。',
    'report_user'      => '完整统计私信给（可多选）',
    'report_user_hint' => '建议勾主管以上。标示「未绑定」的人收不到，请他先私信机器人一次。',
    'report_test'      => '测试发送',
    'report_test_hint' => '会立刻把昨天的完整统计私信给上面勾选的人。只发完整版，不会打扰昨天被提醒到的同事。',
    'report_personal_title' => '个人统计',
    'report_personal_desc'  => '昨天被提醒到的同事，每天 :time 会各自收到「自己那份」（我被催了哪几题）。这部分自动发送，不需要设置；没被提醒到的人不会收到。',

    // ===== 话题分流 =====
    'topic_title'         => '话题分流',
    'topic_desc'          => '群组开了 Telegram 的话题功能时，可以把不同通知分到不同话题。新增话题后勾选它要收哪些通知；没有被任何话题勾到的通知会发到群组主区。',
    'topic_general'       => '群组主区',
    'topic_name'          => '话题名称（备注用）',
    'topic_name_ph'       => '例如：排程通知',
    'topic_thread_id'     => '话题 id',
    'topic_types'         => '这个话题要收哪些通知',
    'topic_add'           => '新增话题',
    'topic_remove'        => '删除',
    'topic_empty'         => '还没有设置任何话题，所有通知都会发到群组主区。',
    'topic_single_badge'  => '只能一个话题',
    'topic_test'          => '测试发送',
    'topic_test_hint'     => '会逐话题各发一则测试消息（含群组主区）。话题 id 填错 Telegram 会整则拒收，所以一定要测过才算设置完成。',

    'topic_howto_title' => '话题 id 怎么取得',
    'topic_howto_1'     => '在 Telegram 打开您要设置的那个话题。',
    'topic_howto_2'     => '在话题里直接输入：',
    'topic_howto_3'     => '机器人会回复那个话题的 id，把数字填到左边即可。',
    'topic_howto_note'  => '一定要在话题「里面」输入。在群组主区输入会回复「这里没有话题 id」。',

    // ===== 班表通知 =====
    'shift_title'          => '完整班表收件人',
    'shift_desc'           => '每天 :time 私信今日完整班表（每个班次是谁、哪个班没人）。可以勾多位，没勾任何人时不会发送。',
    'shift_user'           => '收件人（可多选）',
    'shift_user_hint'      => '标示「未绑定」的同事收不到消息，请他先私信机器人一次。全部取消勾选就不发送。',
    'shift_test'           => '测试发送',
    'shift_test_hint'      => '会立刻把今天的完整班表私信给上面勾选的收件人。',
    'shift_personal_title' => '个人班表',
    'shift_personal_desc'  => '今天有班的同事，每天 :time 会各自收到自己那一份（只有自己的班别与时间，不会看到其他人）。这部分自动发送，不需要设置。',

    'bind_title' => '为什么有人收不到私信',
    'bind_step1' => 'Telegram 不允许机器人主动私信「从来没跟它对话过」的人。',
    'bind_step2' => '请同事在 Telegram 找到客服机器人，私信任何一句话（例如「hi」）。',
    'bind_step3' => '发送后系统会自动记录，隔天早上就收得到了。',
    'bind_note'  => '只需要做一次。在群组里发言不算，一定要是私信。',

    'action_save'    => '保存',
    'action_saving'  => '保存中…',
    'action_testing' => '发送中…',

    /*
     * 话题分流可以勾的通知类型。
     *
     * ⚠ 名称要写「**群组里会收到什么**」，不是「这是哪个功能」。
     * 班表通知本身是私信、不会进群组，进群组的只有「谁没收到」那则汇总 ——
     * 原本写「班表通知异常」会让人以为是另一个功能。
     */
    'notice_type' => [
        'auto_reply_ticket'      => 'AI 转来的问题',
        'auto_reply_ticket_hint' => '题库找不到答案时转出来的求助单、超时提醒、处理结果。客服在这里引用回复作答',
        'daily_rate'             => '每日汇率报价',
        'daily_rate_hint'        => '每天 9:00 的报价、没决定时每 30 分的提醒、决定后的结果。要引用回复决定',
        'ticket_handover'        => '早班待接手清单',
        'ticket_handover_hint'   => '每天 7:30 把还没人处理的问题交接给当天早班',
        'shift_notice'           => '班表没收到的人',
        'shift_notice_hint'      => '今日班表私信不出去时，把没收到的同事汇总成一则。班表本身是私信，不会进群组',
        'vm_payment'             => '虚拟机缴款',
        'vm_payment_hint'        => '每天 9:30 的缴款通知：站台没设群组时退回内部、有待审核时催内部审核',
        'station_credit'         => '站台余点告警',
        'station_credit_hint'    => '每天 10:00 的余点告警与补点消息，站台没设群组时退回内部',
    ],

    'msg' => [
        'saved'       => '已保存',
        'save_failed' => '保存失败，请稍后再试',

        // 支援群组
        'chat_id_invalid'     => 'chat_id 格式不正确（群组的 id 是负数）',
        'chat_id_is_customer' => '这个 chat_id 已经出现在 Telegram 客服的对话列表里，不能同时当内部支援群组（那个对话的消息会全部被拦掉、不会送达也不会自动回复）。请先到「Telegram 客服」把该笔对话删除，再回来保存。'
            . '｜常见情况：支援群组开启「话题」功能被升级成 supergroup 之后，旧 chat_id 失效的那段时间，系统认不得它，就把它当成新客户建了一笔对话。那一笔删掉即可。',
        'system_not_found'    => '找不到该系统',
        'support_test_sent'   => '测试消息已发出，请到群组确认',
        'support_test_failed' => '测试消息发送失败，请确认 chat_id、Bot 是否已加入群组、以及 Group Privacy 是否关闭',
        'chat_id_migrated'    => '这个群组已经升级成 supergroup（开启「话题」功能时会发生），chat_id 换了。新的是 :id —— 请把上面的 chat_id 改成这组再保存。',

        // 提醒
        'remind_required'      => '请填写提醒时间',
        'remind_invalid'       => '提醒时间须介于 1 到 1440 分钟',
        'remind_max_required'  => '请填写最多提醒几次',
        'remind_max_invalid'   => '提醒次数须介于 1 到 200 次',
        'report_user_not_found' => '找不到这个账号',
        'report_test_sent'      => '测试统计已发出 :sent 则，请确认 Telegram 有收到',
        'report_test_partial'   => '已发出 :sent 则，但这几位没收到：:failed（请他们先私信机器人一次）',
        'report_test_no_user'   => '请先勾选收件人并保存',
        'report_test_not_bound' => '勾选的同事都还没私信过机器人，所以收不到消息',
        'report_test_failed'    => '发送失败，请确认同事没有屏蔽机器人',

        // 话题
        'topic_too_many'       => '话题数量超过上限',
        'topic_name_too_long'  => '话题名称太长',
        'thread_id_required'   => '请填写话题 id',
        'thread_id_invalid'    => '话题 id 必须是大于 0 的整数',
        'thread_id_duplicate'  => '话题 id :id 重复了，同一个话题不要设置两次',
        'notice_type_invalid'  => '通知类型不正确',
        'type_single_only'     => '「:type」需要客服引用回复才能运作，只能勾在一个话题',
        'topic_test_sent'      => '已发出 :sent 则测试消息，请到群组各话题确认',
        'topic_test_partial'   => '已发出 :sent 则，但这几个发不出去：:failed（话题 id 可能填错或话题已删除）',
        'topic_test_failed'    => '测试消息发不出去，请先确认上一个分页的群组 chat_id 设置正确',

        // 班表
        'manager_invalid'        => '收件人格式不正确',
        'manager_not_found'      => '找不到这个账号',
        'shift_test_sent'        => '测试消息已发出 :sent 则，请确认 Telegram 有收到',
        'shift_test_partial'     => '已发出 :sent 则，但这几位没收到：:failed（请他们先私信机器人一次）',
        'shift_test_no_manager'  => '请先勾选收件人并保存',
        'shift_test_not_bound'   => '勾选的同事都还没私信过机器人，所以收不到消息',
        'shift_test_failed'      => '发送失败，请确认同事没有屏蔽机器人',
    ],
];
