<?php

return [
    'nav_label'  => 'Notifications',
    'page_title' => 'Notification Settings',
    'subtitle'   => 'Internal support group, topic routing, shift notices',

    'section_label' => 'Messaging',

    'tab_support' => 'Support Group',
    'tab_topic'   => 'Topic Routing',
    'tab_shift'   => 'Shift Notice',

    'select_all' => 'Select all',
    'unbound'    => 'not linked',

    // ===== Support group =====
    'support_title'          => 'Internal Support Group',
    'support_desc'           => 'When the knowledge base has no answer, the question is forwarded here for the team to answer.',
    'support_chat_id'        => 'Group chat_id',
    'support_chat_id_hint'   => 'Group chat IDs are negative. The bot must be in the group and Group Privacy must be disabled.',
    'support_system'         => 'Bot to use',
    'support_system_default' => 'Default bot (.env)',
    'support_test'           => 'Send test message',

    // ===== Ticket reminders =====
    'remind_title'         => 'Overdue ticket reminders',
    'remind_first'         => 'First reminder (minutes)',
    'remind_first_hint'    => 'Tags the staff currently on shift if nobody has answered within this time of the ticket opening.',
    'remind_interval'      => 'Then every (minutes)',
    'remind_interval_hint' => 'Reminds again every this many minutes after the first one, until the ticket is handled. From reminder :escalate onward, managers and owners are tagged as well (engineers are always skipped).',
    'remind_max'           => 'Maximum reminders',
    'remind_max_hint'      => 'Stops reminding at this count and sends one final notice to managers and owners. Prevents a barrage overnight when nobody is on shift.',

    // ===== Daily summary =====
    'report_title'     => 'Daily reminder summary',
    'report_desc'      => 'Yesterday\'s overdue-reminder summary is sent by direct message at :time daily. The people ticked here receive the summary for *everyone* (by ticket and by person); anyone who was reminded also receives their own personal summary automatically, with no setup.',
    'report_user'      => 'Full summary goes to (multiple)',
    'report_user_hint' => 'Managers and above is the usual choice. Staff marked "not linked" cannot receive it — ask them to message the bot once.',

    // ===== Topic routing =====
    'topic_title'         => 'Topic Routing',
    'topic_desc'          => 'When the group has Telegram Topics enabled, different notifications can go to different topics. Add a topic, then tick which notifications it should receive. Anything not ticked anywhere goes to the group\'s General area.',
    'topic_general'       => 'General area',
    'topic_name'          => 'Topic name (for reference)',
    'topic_name_ph'       => 'e.g. Scheduled notices',
    'topic_thread_id'     => 'Topic id',
    'topic_types'         => 'Notifications this topic receives',
    'topic_add'           => 'Add topic',
    'topic_remove'        => 'Remove',
    'topic_empty'         => 'No topics configured yet — every notification goes to the General area.',
    'topic_single_badge'  => 'one topic only',
    'topic_test'          => 'Send test',
    'topic_test_hint'     => 'Sends one test message to each topic (including the General area). Telegram rejects the whole message when a topic id is wrong, so always test before considering this done.',

    'topic_howto_title' => 'How to find a topic id',
    'topic_howto_1'     => 'Open the topic you want to configure in Telegram.',
    'topic_howto_2'     => 'Type this inside the topic:',
    'topic_howto_3'     => 'The bot replies with that topic\'s id — put the number in the field on the left.',
    'topic_howto_note'  => 'It must be typed INSIDE the topic. Typing it in the General area replies "there is no topic id here".',

    // ===== Shift notice =====
    'shift_title'          => 'Full roster recipients',
    'shift_desc'           => 'The full roster (who is on each shift, which shift has nobody) is sent by direct message at :time daily. Pick as many recipients as you like; nothing is sent while none are ticked.',
    'shift_user'           => 'Recipients (multiple)',
    'shift_user_hint'      => 'Staff marked "not linked" cannot receive messages — ask them to message the bot once. Untick everyone to stop sending.',
    'shift_test'           => 'Send test',
    'shift_test_hint'      => 'Sends today\'s full roster to everyone ticked above right now.',
    'shift_personal_title' => 'Personal roster',
    'shift_personal_desc'  => 'Everyone rostered today receives their own entry at :time (their shift and hours only, never anyone else\'s). This is automatic and needs no setup.',

    'bind_title' => 'Why some people receive no direct messages',
    'bind_step1' => 'Telegram does not let a bot message someone who has never talked to it.',
    'bind_step2' => 'Ask them to open the support bot on Telegram and send any message (e.g. "hi").',
    'bind_step3' => 'The system records it automatically, and messages arrive the next morning.',
    'bind_note'  => 'Only needed once. Posting in a group does not count — it must be a direct message.',

    'action_save'    => 'Save',
    'action_saving'  => 'Saving…',
    'action_testing' => 'Sending…',

    'msg' => [
        'saved'       => 'Saved',
        'save_failed' => 'Save failed, please try again later',

        // Support group
        'chat_id_invalid'     => 'Invalid chat_id format (group IDs are negative)',
        'chat_id_is_customer' => 'This group is already a customer conversation and cannot be used as the internal support group (all of its customer messages would be intercepted, never delivered and never auto-replied). Please use a separate group, or remove that conversation in Telegram CS first.',
        'system_not_found'    => 'System not found',
        'support_test_sent'   => 'Test message sent, please check the group',
        'support_test_failed' => 'Failed to send. Check the chat_id, that the bot is in the group, and that Group Privacy is disabled',

        // Reminders
        'remind_required'      => 'Please enter the reminder interval',
        'remind_invalid'       => 'Reminder interval must be between 1 and 1440 minutes',
        'remind_max_required'  => 'Please enter the maximum number of reminders',
        'remind_max_invalid'   => 'Maximum reminders must be between 1 and 200',
        'report_user_not_found' => 'Account not found',

        // Topics
        'topic_too_many'       => 'Too many topics',
        'topic_name_too_long'  => 'Topic name is too long',
        'thread_id_required'   => 'Please enter the topic id',
        'thread_id_invalid'    => 'Topic id must be an integer greater than 0',
        'thread_id_duplicate'  => 'Topic id :id appears twice — do not configure the same topic more than once',
        'notice_type_invalid'  => 'Invalid notification type',
        'type_single_only'     => '":type" relies on staff replying by quote, so it can only be ticked on one topic',
        'topic_test_sent'      => ':sent test message(s) sent, please check each topic in the group',
        'topic_test_partial'   => ':sent sent, but these could not be delivered: :failed (the topic id may be wrong, or the topic was deleted)',
        'topic_test_failed'    => 'Nothing could be sent — check the group chat_id on the previous tab first',

        // Shift
        'manager_invalid'        => 'Invalid recipient',
        'manager_not_found'      => 'Account not found',
        'shift_test_sent'        => ':sent test message(s) sent, please check Telegram',
        'shift_test_partial'     => ':sent sent, but these people did not receive it: :failed (ask them to message the bot once)',
        'shift_test_no_manager'  => 'Please tick at least one recipient and save first',
        'shift_test_not_bound'   => 'None of the ticked recipients has messaged the bot yet, so they cannot receive messages',
        'shift_test_failed'      => 'Send failed, please check the bot is not blocked',
    ],
];
