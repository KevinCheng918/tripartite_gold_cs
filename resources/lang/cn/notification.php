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
    'tab_task'   => '任务卡通知',
    'tab_attendance' => '打卡报表',
    'tab_approval'   => '申请审核',
    'tab_finance'    => '财务申请',
    'tab_wallet'     => '钱包转帐',
    'tab_followup'   => '待跟进事项',

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
    'report_title'     => '超时提醒统计',
    'report_desc'      => '每天 :time 把前一天的超时提醒统计私信出去。勾选的人收「全部人的」（依题目、依人员），被提醒到的同事另外会各自收到「自己那份」，不需要设置。',
    'report_period'    => '同一份名单也会收到周报表（每周一 :time 报上周）与月报表（每月 1 号 :time 报上个月），时间跟日报一致。周报与月报只发完整版，不会逐人发个人版。',
    'report_user'      => '完整统计私信给（可多选）',
    'report_user_hint' => '建议勾主管以上。标示「未绑定」的人收不到，请他先私信机器人一次。',
    'report_test'      => '测试发送',
    'report_test_hint' => '会立刻把昨天的完整统计私信给上面勾选的人。只发完整版，不会打扰昨天被提醒到的同事。',
    'report_personal_title' => '个人统计',
    'report_personal_desc'  => '昨天被提醒到的同事，每天 :time 会各自收到「自己那份」（我被催了哪几题）。这部分自动发送，不需要设置；没被提醒到的人不会收到。',

    // ===== 求助单逾时升级 =====
    /*
     * 求助单逾时升级（需求方 2026-11-08）。
     *
     * ⚠ `escalation_trigger_hint` 一定要写出「必须小于提醒次数上限」：
     * 设得比上限高的话，提醒会在升级之前就停止，电话永远不会响**而且不报错**。
     */
    'escalation_title'   => '逾时升级（打电话）',
    'escalation_desc'    => '求助单催到指定次数还没有人处理时，用 Telegram 拨电话给当班人员把他叫起来，同时私讯告诉他是哪一张单。拨满 :calls 通仍然没有人处理，就改私讯下面勾选的人。⚠ 电话只会响、没有语音内容（接起来会断线）—— 要处理什么写在私讯里。⚠ 判断「处理了没」一律看那张单的状态，不是看电话有没有被接起。',
    'escalation_enabled' => '启用逾时升级',
    'escalation_enabled_hint' => '⚠ 这一项**预设是关的**。它会拨电话也会私讯，请先把下面的收件人勾好、确认拨号设定完成再打开。',
    'escalation_trigger'      => '催到第几次开始升级',
    'escalation_trigger_hint' => '以现行的提醒间隔，第 10 次大约是开单后 100 分钟。⚠ **必须小于「最多提醒几次」（目前是 :max）** —— 设得比它高的话，提醒会在升级之前就停止，电话永远不会响。',
    'escalation_ring'      => '电话响几秒',
    'escalation_ring_hint' => '超过约 90 秒 Telegram 会自己挂断，填更大没有用。越长越可能被接起来，而接起来会直接断线。',
    'escalation_interval'      => '两通之间隔几分钟',
    'escalation_interval_hint' => '最多拨 :calls 通（这个数字不开放调整）。⚠ 间隔太短会让机器人帐号被 Telegram 判定为骚扰而停权，所以不接受小于 3 分钟。',
    'escalation_manager'      => '拨不通时私讯给（可多选）',
    'escalation_manager_hint' => ':calls 通电话都拨完、那张单仍然没有人处理时，立刻私讯这些人。标示「未绑定」的人收不到，请他先私讯机器人一次。',
    'escalation_test'      => '测试拨号',
    'escalation_test_user' => '拨给谁',
    'escalation_test_hint' => '会**真的拨一通**给选定的同事、响 :seconds 秒。请先告知他，不然他会被吓到。⚠ 只列出有填 Telegram 帐号的人 —— 没填的拨不出去。',
    'escalation_call_missing' => '拨号功能尚未设定完成（需要 Telegram 应用程式凭证与登入档）—— 在那之前只会私讯，不会拨电话。',

    // ===== 班别交接 =====
    /*
     * 班别交接（需求方 2026-11-08）。
     *
     * ⚠ `handover_interval_hint` 一定要写明「没有次数上限」：这个功能会
     * 一直催到有人交接，设定页不讲的话，填 5 分钟的人不会意识到那是
     * 「每 5 分钟 tag 一群人直到有人动」。
     */
    'handover_title'   => '班别交接',
    'handover_desc'    => '每一班都要在交接话题打交接事项。两班回讯时间重叠的那一小时就是交接窗口，系统会在下一班回讯开始前几分钟，在交接话题同时 tag 两边 —— 上一班「请打交接事项」、下一班「你要上线了，请先看交接」。那一班的人在交接话题发讯息就算完成。⚠ 上一班当天没有人排班时**不会催**，改成跟下一班说一声、并引用上一份交接（大夜班目前没人就是这种情况，那时请看系统每天发的「早班待接手清单」）。',
    'handover_enabled' => '启用交接提醒',
    'handover_enabled_hint' => '关闭时完全不发交接讯息，已经记下的交接纪录会留着。⚠ 这一项**预设是开的**（交接是每班都要做的事）。',
    'handover_lead'      => '提前几分钟开始提醒',
    'handover_lead_hint' => '从「下一班回讯开始时间」往前算。例如午班回讯 13:00 开始、填 5，就是 12:55 发第一则。',
    'handover_interval'      => '没交接时每隔几分钟再催',
    'handover_interval_hint' => '⚠ **没有次数上限** —— 会一直催到那一班的人在交接话题发讯息为止（没事情交接的话回一句「没有」就结束）。所以这个数字就是「会被吵多凶」的唯一控制项，建议不要填太小。第 :escalate 次起会连主管一起 tag。',
    'handover_topic_missing' => '还没在「支援群组与话题」分页把「班别交接」勾到任何话题 —— 在那之前这个功能不会动作（避免在群组主区催一件无法被完成的事）。',

    // ===== 自动回复自动恢复 =====
    /*
     * 自动回复自动恢复（需求方 2026-11-07）。
     *
     * ⚠ `resume_minutes` 的 label 一定要写「预设」：真正生效的分钟数是客服
     * 在聊天视窗 modal 当场填的，这里只是那个栏位的预填值。
     */
    'resume_title'   => '自动回复自动恢复',
    'resume_desc'    => '客服取消勾选自动回复改人工处理，处理完常常忘记勾回来 —— 那个对话从此一直是人工。开启后，转人工的对话静置够久就会自动把自动回复勾回来，并到支援群组通知、tag 当初关掉的人。⚠ 只会恢复「关闭时选了暂时转人工」的对话：从没开过自动回复的对话、以及选了永久关闭的对话都不会被碰到。',
    'resume_enabled' => '启用自动恢复',
    'resume_enabled_hint' => '关闭时完全不会改动任何对话的开关，已经记下来的关闭纪录会留着。',
    'resume_minutes'      => '静置分钟数的预设值',
    'resume_minutes_hint' => '⚠ 这**只是聊天视窗那个 modal 的预填值**，不是全域生效的时间 —— 真正用哪个数字由关掉的客服当场决定（他才知道这一通要处理多久）。可填 :min ～ :max 分钟。',

    // ===== 待跟进事项 =====
    'followup_title'   => '待跟进事项',
    'followup_desc'    => '对话里有人说「稍等、晚点给你」就记成一笔待跟进事项。对话安静一段时间后，系统会到支持群组 tag 当班人员问「要不要跟进」：选要跟进就发消息问客人进度（我方自己说的则改成 tag 当班人员接手），选不跟了就结案。没有人按按钮时会每隔一段时间再问一次，第 :escalate 次起同时 tag 主管与老板。',
    'followup_enabled' => '启用待跟进事项',
    'followup_enabled_hint' => '关闭时完全不侦测、不发消息，已经记下来的事项会留着。',
    'followup_keywords'      => '等待词（一行一个）',
    'followup_keywords_hint' => '命中其中一个词才会进一步请 AI 确认「是不是真的在说稍后提供」，所以宁可宽一点。留白就用系统预设的这一组：',
    'followup_quiet'         => '安静多久后询问（分钟）',
    'followup_quiet_hint'    => '对方说完「稍等」之后，对话安静超过这个时间才去问当班人员。客人后来又说话会重新计时 —— 他还在线上聊的时候系统不会插进来。',
    'followup_interval'      => '没人决定时每隔（分钟）',
    'followup_interval_hint' => '没有人按按钮时，每隔这个时间再问一次。第 :escalate 次起会同时 tag 主管与老板。',
    'followup_max'           => '最多询问几次',
    'followup_max_hint'      => '到这个次数仍没有人按按钮就停止询问，并发最后一则通知主管与老板（按钮仍然有效）。追问客人的轮数不受这个限制。',
    'followup_stale'         => '几天没结果就能收掉',
    'followup_stale_hint'    => '填 0 表示不自动收（预设）。填天数之后，要清理时执行 followup:close-stale 才会真的收，排程不会自动跑。',
    'followup_report_user'      => '每日统计私信给（可多选）',
    'followup_report_user_hint' => '每天 :time 把前一天的待跟进事项统计私信出去（新开几笔、结案几笔、还挂着几笔、拖最多次的是谁）。标示「未绑定」的人收不到，请他先私信机器人一次。',

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

    // ===== 任务卡通知 =====
    'task_title'          => '任务卡总览收件人',
    'task_desc'           => '每天 :time 私信一份全部人的任务卡总览（每人各有几项已过期、今日到期、进行中）。可以勾多位，没勾任何人时不会发送。',
    'task_user'           => '收件人（可多选）',
    'task_user_hint'      => '标示「未绑定」的同事收不到消息，请他先私信机器人一次。勾选的人不会再另外收到个人版。',
    'task_test'           => '测试发送',
    'task_test_hint'      => '会立刻把今天的总览私信给上面勾选的人。只发总览，不会打扰其他同事。',
    'task_personal_title' => '个人任务卡',
    'task_personal_desc'  => '每位在职同事每天 :time 会各自收到自己的那一份：已过期几项、今日到期几项、进行中几项，以及要留意的卡片清单。手上没卡的人收到的是一句轻松的话。这部分自动发送，不需要设置。',
    'task_count_note'     => '一张卡可以指派给多个人，所以各人的数字加起来会大于卡片总张数 —— 那是对的，他们每个人都该知道。',

    // 打卡报表
    'attendance_title'          => '打卡报表收件人',
    'attendance_desc'           => '周报表 :weekly 统计上周一到上周日，月报表 :monthly 统计上个月。两份都会私讯一份全部人的统计（谁全勤、谁迟到早退旷工几次几分钟、谁加班多久）。可以勾多位，没勾任何人时不会发送。',
    'attendance_user'           => '收件人（可多选）',
    'attendance_user_hint'      => '周报与月报共用这一份名单。标示「未绑定」的同事收不到讯息，请他先私讯机器人一次。勾选的人不会再另外收到个人版。',
    'attendance_test'           => '测试发送',
    'attendance_test_hint'      => '会立刻把上周的完整报表私讯给上面勾选的人。只发完整版，不会打扰其他同事。',

    'approval_title'           => '申请审核通知收件人',
    'approval_desc'            => '有人申请请假、补打卡、代班时立刻私讯通知；审核完（不管通过或拒绝）再通知一次。可以勾多位，没勾任何人时不会发送（申请本身照样成立）。',
    'approval_user'            => '收件人（可多选）',
    'approval_user_hint'       => '标示「未绑定」的同事收不到讯息，请他先私讯机器人一次。',
    'approval_test'            => '测试发送',
    'approval_test_hint'       => '会立刻私讯一则测试讯息给上面勾选的人。这种通知是有人申请时才触发的，没办法补发先前的。',
    'approval_events_title'    => '会通知哪些事',
    'approval_event_leave'     => '请假：申请时、审核通过或拒绝时',
    'approval_event_amendment' => '补打卡：申请时、审核通过或拒绝时',
    'approval_event_cover'     => '代班：申请时、代班人同意或拒绝时、主管核准或驳回时',
    'approval_swap_hint'       => '换班不在通知范围内：换班没有主管审核那一关，被换的同事答应就直接生效，通知了也没有需要处理的事。',
    'finance_title'           => '财务申请通知收件人',
    'finance_desc'            => '有人上传虚拟机缴款证明、申请补点或扣点时立刻私讯通知；审核完再通知一次。可以勾多位，没勾任何人时不会发送（上传与申请本身照样成立）。',
    'finance_user'            => '收件人（可多选）',
    'finance_user_hint'       => '标示「未绑定」的同事收不到讯息，请他先私讯机器人一次。',
    'finance_test'            => '测试发送',
    'finance_test_hint'       => '会立刻私讯一则测试讯息给上面勾选的人。这种通知是有人送件时才触发的，没办法补发先前的。',
    'finance_events_title'    => '会通知哪些事',
    'finance_event_proof'     => '虚拟机缴款证明：上传时、审核确认收款时',
    'finance_event_topup'     => '补点／扣点：申请时、审核通过或拒绝时（通过但主系统没加成功会另外提醒）',
    'finance_split_hint'      => '这份名单跟「申请审核」是分开的：那边是人事（请假、补打卡、代班），这边是钱（缴款、补点）。想收其中一种的人可以只勾一边。',
    'wallet_title'            => '钱包转帐通知',
    'wallet_desc'             => '监听的 TRON 钱包只要有 USDT（或其他 TRC20 代币）转入或转出，就立刻私讯通知，内容包含时间、金额、币别、From、To 与交易 hash，并附上可以直接点开的 Tronscan 交易连结。只通知，不会做任何动作。',
    'wallet_enabled'          => '启用钱包转帐通知',
    'wallet_addresses'        => '要监听的钱包（地址／备注名称）',
    'wallet_address_add'      => '新增一个钱包',
    'wallet_address_hint'     => 'TRON 地址是 T 开头、34 码。备注名称会出现在通知里（例如「公司主钱包」），不填也可以。',
    'wallet_address_placeholder' => 'TRON 地址（T 开头）',
    'wallet_label_placeholder'   => '备注名称（选填）',
    'wallet_min_amount'       => '最低通知金额',
    'wallet_min_amount_hint'  => '低于这个金额的转帐不会通知（但仍然会留记录）。填 0 表示全部都通知。',
    'wallet_user'             => '收件人（可多选）',
    'wallet_user_hint'        => '标示「未绑定」的同事收不到讯息，请他先私讯机器人一次。',
    'wallet_test'             => '测试发送',
    'wallet_test_hint'        => '会立刻私讯一则测试讯息给上面勾选的人。这种通知是链上有转帐才触发的，没办法补发先前的。',
    'wallet_events_title'     => '会通知哪些事',
    'wallet_event_in'         => '有人转 USDT 或其他 TRC20 代币进来',
    'wallet_event_out'        => '从这个钱包转出去',
    'wallet_baseline_hint'    => '⚠️ 新增一个钱包后，第一次检查只会把目前的记录抓回来当基准，不会发通知 —— 否则链上几千笔历史转帐会一次全部送出。从第二次检查（约一分钟后）开始才会通知。',
    'wallet_dust_hint'        => '⚠️ TRON 上有「地址投毒」的手法：对方从长得很像的地址转 0.000001 USDT 进来，诱使你下次复制到错的地址。最低通知金额就是用来挡这种的，建议至少填 1。',
    'attendance_personal_title' => '个人打卡报表',
    'attendance_personal_desc'  => '每位在职同事都会各自收到自己的那一份：迟到、早退、旷工的次数与时间，补打卡次数，请假几号到几号，加班总时数。没有迟到、早退、旷工的人收到的是一句勉励的话。这部分自动发送，不需要设定。',
    'attendance_leave_note'     => '完整版每人一行分三种：全勤（没有迟到早退旷工，而且没请假）、有请假（报请假多久 + 其余无状况）、有状况（报次数与时间）。请假不算出状况，但也不算全勤。',

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
        'conversation_followup'      => '待跟进事项',
        'conversation_followup_hint' => '有人说「稍等」之后对话就安静了，机器人来问当班人员要不要跟进。靠消息上的按钮决定，不需要引用回复',
        'ticket_handover'        => '早班待接手清单',
        'ticket_handover_hint'   => '每天 7:30 把还没人处理的问题交接给当天早班',
        'shift_notice'           => '班表没收到的人',
        'shift_notice_hint'      => '今日班表私信不出去时，把没收到的同事汇总成一则。班表本身是私信，不会进群组',
        'vm_payment'             => '虚拟机缴款',
        'vm_payment_hint'        => '每天 9:30 的缴款通知：站台没设群组时退回内部、有待审核时催内部审核',
        'station_credit'         => '站台余点告警',
        'station_credit_hint'    => '决定汇率后下一个整点发的余点告警与补点消息，站台没设群组时退回内部',

        /*
         * ⚠ 这一项的语意跟其他不一样：其他是「这类通知**发到**哪」，
         * 这一项是「哪些话题**可以排**」。
         */
        // key 仍是 scheduled_reminder，只改顯示文字（理由見 tw）
        'task_change'             => '任务卡异动',
        'task_change_hint'        => '任务卡有建立、状态、指派人员、参与人、到期日、优先顺序、专案站台、封存还原的变更时通知。'
            . '⚠ 同一张卡连续的异动会**合并成一则**（安静几分钟后才发），不是改一次发一次；'
            . '描述、标题与勾选清单打勾**不通知**（太频繁）',

        /*
         * 自动回复自己勾回来了（需求方 2026-11-07）。
         *
         * ⚠ hint 要提醒**不要勾在「AI 转来的问题」那个话题**：那里是客服要
         * 引用回复作答的作业区，混进纯告知会让那区的「有新讯息」失去意义。
         */
        'auto_reply_resume'      => '自动回复已恢复',
        'auto_reply_resume_hint' => '对话转人工后静置够久，系统自动把自动回复勾回来时通知，并 tag 当初关掉的人。'
            . '⚠ 建议跟「早班待接手清单」放同一个话题；**不要**勾在「AI 转来的问题」那一个（那里要留给需要引用回复的求助单）',

        /*
         * 班别交接（需求方 2026-11-08）。
         *
         * ⚠ 这一项**双向**：除了「通知发到这里」，同仁在这个话题发言还会被
         * 当成「完成交接」。hint 一定要写出来，不然会有人在这里闲聊而
         * 误消掉提醒。
         */
        'shift_handover'      => '班别交接',
        'shift_handover_hint' => '交接时间到了的提醒（同时 tag 上下两班）。'
            . '⚠ 这一项不只是「发到这里」—— **那一班的人在这个话题发讯息就算完成交接**，'
            . '所以请勾一个专门做交接的话题，不要跟闲聊混在一起',

        'scheduled_reminder'      => '小帮手',
        'scheduled_reminder_hint' => '⚠ 这一项是「哪些话题可以 tag 小帮手下指令」，不是「发到哪里」——'
            . '小帮手一律回在你说话的那个话题。一个都没勾＝所有话题都能用',
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

        'followup_keywords_invalid' => '等待词总长度不可超过 2000 字',
        'followup_minutes_required' => '请填写询问时间',
        'followup_minutes_invalid'  => '询问时间须介于 1 到 1440 分钟',
        'followup_max_required'     => '请填写最多询问几次',
        'followup_max_invalid'      => '询问次数须介于 1 到 200 次',
        'followup_stale_required'   => '请填写几天没结果就能收掉（不自动收请填 0）',
        'followup_stale_invalid'    => '天数须介于 0 到 365 天',

        'call_test_sent'        => '已拨给 :name，请确认他的手机有响（没有语音内容，接起来会断线，那是正常的）',
        'call_test_not_ready'   => '拨号设定还没完成 —— 请先在伺服器上执行 php artisan telegram:call-login',
        'call_test_no_user'     => '找不到这个人，或他没有填 Telegram 帐号（没填就拨不出去）',
        'call_test_privacy'     => '拨不到 :name —— 他的 Telegram「谁可以打电话给我」设成仅联络人。请用这个机器人帐号把他加进联络人，或请他改设定',
        'call_test_not_found'   => '找不到 :name 的 Telegram 帐号 —— 请确认他填的 username 正确，或先用机器人帐号把他加进联络人',
        'call_test_no_session'  => '机器人还没登入 —— 请在伺服器上执行 php artisan telegram:call-login',
        'call_test_no_telethon' => '拨号用的 Python 找不到 telethon —— 多半是 .env 的 TG_CALL_PYTHON 没指向那个 venv（要绝对路径）',
        'call_test_error'       => '拨给 :name 失败：:error',
        // 这一句不能带 :name／:error —— 前端把它当「连不上后端」的退路，那时候没有值可以填
        'call_test_failed'      => '测试拨号失败',
        'escalation_trigger_required' => '请填写催到第几次开始升级',
        'escalation_trigger_invalid'  => '升级门槛须介于 2 到 200 次',
        'escalation_trigger_too_high' => '升级门槛必须小于「最多提醒几次」（目前是 :max）—— 否则提醒会在升级之前就停止，电话永远不会响',
        'escalation_ring_required'    => '请填写电话响几秒',
        'escalation_ring_invalid'     => '响铃秒数须介于 5 到 90 秒',
        'escalation_interval_required' => '请填写两通之间隔几分钟',
        'escalation_interval_invalid'  => '拨号间隔须介于 3 到 60 分钟（太短会让机器人帐号被判定为骚扰）',
        'remind_max_below_escalation'  => '提醒次数上限必须大于逾时升级的门槛（目前是第 :trigger 次）—— 否则提醒会在升级之前就停止，电话永远不会响',

        'handover_lead_required'     => '请填写提前几分钟开始提醒',
        'handover_lead_invalid'      => '提前时间须介于 1 到 60 分钟',
        'handover_interval_required' => '请填写没交接时每隔几分钟再催',
        'handover_interval_invalid'  => '再催间隔须介于 5 到 1440 分钟（这个功能没有次数上限，所以不接受小于 5 分钟）',

        'resume_minutes_required' => '请填写预设的静置分钟数',
        'resume_minutes_range'    => '静置分钟数须介于 :min 到 :max 分钟',
        'report_user_not_found' => '找不到这个账号',
        'report_test_sent'      => '测试统计已发出 :sent 则，请确认 Telegram 有收到',
        'report_test_partial'   => '已发出 :sent 则，但这几位没收到：:failed（请他们先私信机器人一次）',
        'report_test_no_user'   => '请先勾选收件人并保存',
        'report_test_not_bound' => '勾选的同事都还没私信过机器人，所以收不到消息',
        'report_test_failed'    => '发送失败，请确认同事没有屏蔽机器人',

        // 任务卡
        'task_user_not_found' => '找不到这个账号',
        'task_test_sent'      => '测试总览已发出 :sent 则，请确认 Telegram 有收到',
        'task_test_partial'   => '已发出 :sent 则，但这几位没收到：:failed（请他们先私信机器人一次）',
        'task_test_no_user'   => '请先勾选收件人并保存',
        'task_test_not_bound' => '勾选的同事都还没私信过机器人，所以收不到消息',
        'task_test_failed'    => '发送失败，请确认同事没有屏蔽机器人',

        'attendance_user_not_found' => '找不到这个账号',
        'attendance_test_sent'      => '测试报表已送出 :sent 则，请确认 Telegram 有收到',
        'attendance_test_partial'   => '已送出 :sent 则，但这几位没收到：:failed（请他们先私讯机器人一次）',
        'attendance_test_no_user'   => '请先勾选收件人并储存',
        'attendance_test_not_bound' => '勾选的同事都还没私讯过机器人，所以收不到讯息',
        'attendance_test_failed'    => '发送失败，请确认同事没有屏蔽机器人',

        'approval_user_not_found' => '找不到这个账号',
        'approval_test_sent'      => '测试讯息已送出 :sent 则，请确认 Telegram 有收到',
        'approval_test_partial'   => '已送出 :sent 则，但这几位没收到：:failed（请他们先私讯机器人一次）',
        'approval_test_no_user'   => '请先勾选收件人并储存',
        'approval_test_not_bound' => '勾选的同事都还没私讯过机器人，所以收不到讯息',
        'approval_test_failed'    => '发送失败，请确认同事没有屏蔽机器人',
        'finance_user_not_found'  => '找不到这个账号',
        'finance_test_sent'       => '测试讯息已送出 :sent 则，请确认 Telegram 有收到',
        'finance_test_partial'    => '已送出 :sent 则，但这几位没收到：:failed（请他们先私讯机器人一次）',
        'finance_test_no_user'    => '请先勾选收件人并储存',
        'finance_test_not_bound'  => '勾选的同事都还没私讯过机器人，所以收不到讯息',
        'finance_test_failed'     => '发送失败，请确认同事没有屏蔽机器人',
        'wallet_user_not_found'   => '找不到这个账号',
        'wallet_address_required' => '请填写钱包地址，或把整列删掉',
        'wallet_address_invalid'  => '钱包地址格式不对（TRON 地址是 T 开头、34 码）',
        'wallet_address_too_many' => '监听的钱包太多了，最多 :max 个',
        'wallet_label_too_long'   => '备注名称最多 50 个字',
        'wallet_min_amount_invalid' => '最低通知金额请填 0 以上的数字',
        'wallet_test_sent'        => '测试讯息已送出 :sent 则，请确认 Telegram 有收到',
        'wallet_test_partial'     => '已送出 :sent 则，但这几位没收到：:failed（请他们先私讯机器人一次）',
        'wallet_test_no_user'     => '请先勾选收件人并储存',
        'wallet_test_not_bound'   => '勾选的同事都还没私讯过机器人，所以收不到讯息',
        'wallet_test_failed'      => '发送失败，请确认同事没有屏蔽机器人',

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
