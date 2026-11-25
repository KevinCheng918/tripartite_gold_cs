<?php

return [
    'nav_label'          => 'Telegram 客服',

    // sidebar 那颗数字的语音标签（读屏软件念得出它是什么）
    'unread_aria'        => '有未读讯息的对话数',
    'page_title'         => 'Telegram 客服聊天',
    'subtitle'           => '即时对话管理',
    'group_list'         => '对话列表',
    'no_groups'          => '目前没有对话群组',
    'no_messages'        => '尚无消息',
    'input_placeholder'  => '输入回复消息...',
    'input_placeholder_wide' => '输入回复消息...（可直接粘贴截图）',
    'btn_send'           => '发送',
    'btn_emoji'          => '表情',
    'emoji_cat_face'     => '表情',
    'emoji_cat_gesture'  => '手势',
    'emoji_cat_object'   => '物件',
    'emoji_cat_other'    => '其他',
    'btn_attachment'     => '档案',
    'btn_cancel'         => '取消',
    'send_file_title'    => '传送档案',
    'send_image_title'   => '传送图片',
    'send_file_caption'  => '说明文字（选填）',
    'btn_add_more'       => '新增',
    'btn_save'           => '保存',

    /*
     * 改掉／撤回已经送出去的讯息（需求方 2026-11-25）。
     *
     * ⚠ 两段提示文字要讲清楚后果，不要只写「确定吗」——
     * 编辑客人那边会留下「已编辑」的痕迹，撤回则是整则消失、拿不回来，
     * 客服要先知道自己按下去会发生什么事。
     */
    'action_edit_sent'   => '编辑讯息',
    'edit_hint'          => '改完客人那边会显示「已编辑」，所以不适合用来掩盖说错的话；说错请撤回再重发一则。',
    'action_recall_sent' => '撤回讯息',
    'recall_confirm'     => '确定要把这一则从客人的对话里收回吗？',
    'recall_hint'        => '客人那边会整则消失、而且收不回来；后台仍然留着纪录，主管查得到。',
    // ⚠ 这是气泡上的**标签**（跟 ignore_badge 同一类），不要放进 `msg` ——
    //    放进去前端就得写 `i18n.msg.recalled_badge`，第一版就是这样漏掉的
    'recalled_badge'     => '已撤回',
    'recalled_badge_by'  => '已由 :name 撤回',

    /*
     * 打 `@` 叫人。
     *
     * ⚠ 「（用名字叫）」是给**没有 Telegram 帐号**的人看的 —— 那种人要用
     * user id 做成链接才叫得到，客服会好奇为什么他没有 @xxx。
     */
    'mention_no_username' => '（用名字叫）',
    'mention_staff'       => '我们',
    'mention_unknown'     => '（不明成员）',
    'btn_edit_image'     => '编辑图片',
    'edit_image_hint'    => '点图片可以裁切、涂鸦、遮住不该给客人看的部分',

    /*
     * 传图前的编辑器。
     *
     * ⚠ `editor_*` 由 image-editor.js 以字符串组出 key（工具栏是循环产生的），
     * grep 不到字面，**不要当成没用到删掉**。
     */
    'editor_title'       => '编辑图片',
    'editor_crop'        => '裁切',
    'editor_rotate'      => '旋转',
    'editor_pen'         => '画笔',
    'editor_arrow'       => '箭头',
    'editor_rect'        => '方框',
    'editor_text'        => '文字',
    'editor_mosaic'      => '马赛克',
    // ⚠ 填的是目前选的颜色，不是固定黑色 —— 所以不叫「涂黑」
    'editor_blackout'    => '遮色块',
    'editor_undo'        => '复原',
    'editor_reset'       => '重设',
    'editor_done'        => '完成',
    'editor_crop_apply'  => '套用裁切',
    'editor_crop_cancel' => '取消裁切',
    'editor_text_placeholder' => '输入文字后按 Enter',
    'editor_width'       => '粗细',
    'editor_flatten_hint' => '裁切或旋转之后，前面画的东西就固定了，无法再复原',
    'btn_file'           => '文件',
    'btn_remove_image'   => '移除',
    'drop_hint'          => '放开以加入附件',
    'download_file'      => '下载档案',
    /*
     * ⚠ 两个版本都要留着：客人自己在 Telegram 改自己那句话时，
     * 我们只知道「被改过」、不知道是谁（那是他家的帐号），
     * 这时只能用没有名字的那一版。
     */
    'message_edited'     => '已编辑',
    'message_edited_by'  => '已由 :name 编辑',
    'action_quote'       => '引用回覆',
    'action_cancel_quote' => '取消引用',
    'media_photo'        => '[图片]',
    'media_sticker'      => '[贴图]',
    'media_video'        => '[影片]',
    'media_audio'        => '[语音讯息]',
    'btn_quick_reply'    => '快速回复',
    'qr_search'          => '搜寻问题...',
    'qr_back'            => '返回',
    'qr_fill'            => '填入输入框',
    'qr_send'            => '直接发送',
    'qr_empty'           => '找不到符合的问题',
    'qr_select_category' => '请选择问题类型',
    'btn_assign'         => '指派',
    'assigned_to'        => '值班客服',
    'unassigned'         => '未指派',
    'select_group'       => '请选择左侧对话',

    'auto_reply'             => '自动回复',
    'auto_reply_placeholder' => '自动回复中，取消勾选才能人工输入',
    'auto_reply_unavailable' => '尚未设置 Claude，请先到「AI 引擎」完成设置',
    'badge_auto'             => '自动',
    'auto_reply_running'     => 'AI 正在查询题库并回覆客人…',

    /*
     * 取消勾选自动回复时问一句的 modal。
     *
     * ⚠ `off_temporary_hint` 一定要写成「对话静置」而不是「N 分钟后」：
     * 客人还在聊的时候两者行为完全不同，而填数字的人预设会理解成固定延迟。
     */
    'off_title'             => '要转成人工回复吗？',
    'off_temporary'         => '暂时转人工',
    'off_temporary_hint'    => '对话静置 :minutes 分钟后，系统会自动把自动回复勾回来（客人还在聊就会继续等，不会中途把 AI 放回来）',
    'off_permanent'         => '永久关闭',
    'off_permanent_hint'    => '这个对话之后一直由人工回复，系统不会自动勾回来',
    'off_minutes_label'     => '静置多久后恢复',
    'off_minutes_unit'      => '分钟',
    'off_minutes_range'     => '可填 :min ～ :max 分钟',
    'off_confirm'           => '确定关闭',
    'off_cancel'            => '取消',

    /*
     * 对话清单的状态字。
     *
     * ⚠ 只有「会自动勾回来」的才显示 —— 永久关闭没有恢复时间，
     * 未勾的勾选框本身就说明它是人工，再写一行只是重复。
     */
    'resume_pending'        => '人工中 · 约 :minutes 分后恢复',
    'resume_pending_soon'   => '人工中 · 即将恢复',

    // 不自动回覆的成员
    'ignore_title'          => '不自动回覆的成员',
    'ignore_btn'            => '设定不自动回覆的成员',
    'ignore_section_ignored' => '已忽略',
    'ignore_section_recent'  => '这个对话发言过的人（近 :days 天）',
    'ignore_section_manual'  => '手动加入（清单里找不到人时用）',
    'ignore_search'         => '搜寻名字或 username',
    'ignore_username'       => '@username',
    'ignore_note'           => '备注（选填）',
    'ignore_action_add'     => '加入',
    'ignore_action_ignore'  => '忽略',
    'ignore_action_restore' => '恢复',
    'ignore_badge_manual'   => '手动',
    'ignore_badge'          => '不自动回覆',
    'ignore_empty_ignored'  => '目前没有设定任何人',
    'ignore_empty_recent'   => '这个对话还没有人发言',
    'ignore_empty_search'   => '找不到符合的人',
    'ignore_set_by'         => ':name 于 :time 设定',
    'ignore_deleted_user'   => '已离职人员',
    'ignore_manual_hint'    => '对方没设 username 的话这里填不了，请等他发言后从上面的清单挑',
    'ignore_wrong_hint'     => 'username 填错不会有任何错误讯息，只是不会生效。可以看讯息上有没有出现「不自动回覆」标签来确认',
    'ignore_hint'           => '名单里的人发言时，讯息照常收、照常通知，只是系统不会自动回覆，需要人工处理',

    // 后台帐号预设不自动回覆，可在这个对话个别打开
    'staff_section'         => '内部人员（预设都不自动回覆）',
    'staff_hint'            => '名单跟着帐号管理走，不在这里增减。打勾＝不自动回覆（预设），取消打勾＝这个对话会自动回覆他。状态为「正常」的帐号才算，锁定与停用的不在名单内',
    'staff_permanent_hint'  => '系统已记下这个人的 Telegram 身分，他改掉 username 也认得',
    'staff_empty'           => '目前没有帐号填写 Telegram 帐号',
    'staff_missing'         => '这 :count 个帐号还没填 Telegram 帐号，他们发言不会被屏蔽：:names',
    'staff_missing_action'  => '请到帐号管理补上',
    'staff_allowed_by'      => ':name 于 :time 打开',
    'staff_allowed_label'   => '这个对话会自动回覆他',

    'msg' => [
        'required'         => '此栏位为必填',
        'group_not_found'  => '群组不存在',
        'content_too_long' => '消息内容过长',
        'reply_failed'     => '回复发送失败',

        // 改掉／撤回已送出的讯息。⚠ 失败原因要分开讲，因为下一步完全不同
        'edit_not_allowed'     => '这一则现在改不动了（只有自己人工送出的纯文字、12 小时内可以改）',
        'edit_not_owner'       => '这是别人送出的讯息，您没有权限改动',
        'edit_content_required' => '内容不能是空的；要整则收回请改用「撤回」',
        'edit_content_max'     => '内容太长（Telegram 上限 4096 字）',
        'edit_too_old'         => 'Telegram 不让改这么旧的讯息了，请改用「撤回」再重发一则',
        'edit_gone'            => '那一则讯息在 Telegram 上已经不存在了（可能已经被删掉）',
        'edit_failed'          => '改不动这一则讯息',
        'edit_failed_reason'   => '改不动这一则讯息：:reason',
        'edit_done'            => '已经改好了，客人那边会显示「已编辑」',

        'recall_not_allowed'   => '这一则现在撤不回了（只有我方送出、48 小时内可以撤）',
        'recall_failed'        => '撤回失败',
        'recall_done'          => '已经撤回，客人那边看不到这一则了',
        'reply_success'    => '回复已发送',
        'assign_failed'    => '指派失败',
        'assign_success'   => '指派成功',
        'reaction_failed'  => '表情回应发送失败',
        'image_too_large'  => '图片超过 5MB，无法传送',
        'edited_too_large' => '编辑后的图片太大，请先裁切一部分再试',
        'mention_keyword_too_long' => '搜索的字太长了',
        'editor_failed'    => '图片编辑器开启失败',
        'image_invalid'    => '只能粘贴图片',
        'file_sending'     => '传送中',
        'qr_load_failed'   => '快速回复选单载入失败',
        'image_send_failed' => '图片传送失败',
        'file_required'     => '请选择档案',
        'file_too_large'    => '档案不可超过 :value MB',
        'file_type_blocked' => '不支援此档案类型',
        'file_send_failed'  => '档案传送失败',
        'auto_reply_unavailable' => '尚未设置 Claude，无法开启自动回复',
        'auto_reply_failed'      => '自动回复开关切换失败',
        'off_mode_invalid'       => '关闭方式不正确',
        'quiet_minutes_required' => '请填写静置多久后恢复自动回复',
        'quiet_minutes_range'    => '静置时间请填 :min ～ :max 分钟',
        'member_not_found'       => '找不到这位成员',
        'member_already_ignored' => '这个 username 已经在名单里了',
        'username_required'      => '请输入 username',
        'username_invalid'       => 'username 格式不正确（英数与底线，5～32 字元）',
        'note_too_long'          => '备注过长',
        'ignore_added'           => '已设为不自动回覆',
        'ignore_restored'        => '已恢复自动回覆',
        'staff_not_found'        => '找不到这个帐号',
        'staff_allowed'         => '已打开，这个对话会自动回覆他',
        'staff_blocked'         => '已收回，这个对话不会自动回覆他',
        'ignore_failed'          => '设定失败',
        'ignore_load_failed'     => '名单载入失败',
    ],
];
