<?php

return [
    'nav_label'  => 'Notifications',
    'page_title' => 'Notification Settings',
    'subtitle'   => 'Support group & topics, ticket reminders, shift notices, summaries',

    'section_label' => 'Messaging',

    'tab_group'  => 'Group & Topics',
    'tab_remind' => 'Ticket Reminders',
    'tab_shift'  => 'Shift Notice',
    'tab_report' => 'Reminder Summary',
    'tab_task'   => 'Task Cards',
    'tab_attendance' => 'Attendance Reports',
    'tab_approval'   => 'Approvals',
    'tab_finance'    => 'Finance',
    'tab_wallet'     => 'Wallet',
    'tab_followup'   => 'Follow-ups',
    'tab_dm_wipe'    => 'DM wipe',

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
    'remind_desc'          => 'Questions the AI could not answer are forwarded to the support group, and reminders continue until somebody handles them. Reminders 1 and 2 tag the staff on shift; from reminder :escalate onward managers and owners are tagged as well (engineers are always skipped).',
    'remind_first'         => 'First reminder (minutes)',
    'remind_first_hint'    => 'Tags the staff currently on shift if nobody has answered within this time of the ticket opening.',
    'remind_interval'      => 'Then every (minutes)',
    'remind_interval_hint' => 'Reminds again every this many minutes after the first one, until the ticket is handled. From reminder :escalate onward, managers and owners are tagged as well (engineers are always skipped).',
    'remind_max'           => 'Maximum reminders',
    'remind_max_hint'      => 'Stops reminding at this count and sends one final notice to managers and owners. Prevents a barrage overnight when nobody is on shift.',

    // ===== Daily summary =====
    'report_title'     => 'Overdue reminder summary',
    'report_period'    => 'The same list also receives a weekly report (Mondays at :time, covering last week) and a monthly report (the 1st at :time, covering last month) — same time as the daily one. Weekly and monthly reports send the full version only, no personal copies.',
    'report_desc'      => 'Yesterday\'s overdue-reminder summary is sent by direct message at :time daily. The people ticked here receive the summary for *everyone* (by ticket and by person); anyone who was reminded also receives their own personal summary automatically, with no setup.',
    'report_user'      => 'Full summary goes to (multiple)',
    'report_user_hint' => 'Managers and above is the usual choice. Staff marked "not linked" cannot receive it — ask them to message the bot once.',
    'report_test'      => 'Send test',
    'report_test_hint' => 'Sends yesterday\'s full summary to everyone ticked above right now. Only the full version — nobody who was reminded yesterday is disturbed.',
    'report_personal_title' => 'Personal summary',
    'report_personal_desc'  => 'Anyone who was reminded yesterday receives their own summary at :time (which tickets they were chased on). This is automatic and needs no setup; people who were not reminded receive nothing.',

    // ===== Overdue Escalation =====
    /*
     * Overdue ticket escalation (requested 2026-11-08).
     *
     * ⚠ `escalation_trigger_hint` must state that it has to be lower than the
     * reminder cap: set it higher and reminders stop before escalation ever
     * happens — the phone never rings **and nothing reports an error**.
     */
    'escalation_title'   => 'Overdue Escalation (phone call)',
    'escalation_desc'    => 'When a ticket has been chased this many times with nobody handling it, the system places a Telegram call to the staff on shift to wake them up, and sends them a direct message saying which ticket it is. After :calls calls with still nobody handling it, the people ticked below are messaged instead. ⚠ The call only rings — there is no voice content (answering it drops the line); what needs doing is in the direct message. ⚠ "Handled or not" is always judged by the ticket status, never by whether the call was answered.',
    'escalation_enabled' => 'Enable overdue escalation',
    'escalation_enabled_hint' => '⚠ This one is **off by default**. It places calls and sends direct messages — tick the recipients below and confirm the calling setup first.',
    'escalation_trigger'      => 'Escalate after this many reminders',
    'escalation_trigger_hint' => 'At the current reminder interval, the 10th is roughly 100 minutes after the ticket opened. ⚠ **Must be lower than "Maximum reminders" (currently :max)** — set it higher and reminders stop before escalation, so the phone never rings.',
    'escalation_ring'      => 'Ring for (seconds)',
    'escalation_ring_hint' => 'Telegram ends the call itself after about 90 seconds, so larger values do nothing. The longer it rings the more likely it is answered — and answering drops the line.',
    'escalation_interval'      => 'Minutes between calls',
    'escalation_interval_hint' => 'At most :calls calls (that number is not adjustable). ⚠ Too short a gap gets the bot account flagged as harassment and suspended, so under 3 minutes is not accepted.',
    'escalation_manager'      => 'If calls go unanswered, message (multiple)',
    'escalation_manager_hint' => 'Once all :calls calls are done and the ticket is still unhandled, these people are messaged immediately. Anyone marked "not linked" cannot receive it — ask them to message the bot once.',
    'escalation_test'      => 'Test call',
    'escalation_test_user' => 'Call',
    'escalation_test_hint' => 'This **really places a call** to the person selected and rings for :seconds seconds. Tell them first or they will be startled. ⚠ The list matches who actually gets called: staff who **can be rostered and have a Telegram username**. Without a username they cannot be called, and engineer accounts are never rostered so they are not listed.',
    'escalation_call_missing' => 'Calling is not fully configured yet (it needs Telegram application credentials and a login file) — until then only direct messages are sent, no calls.',

    // ===== Direct message wipe (requested 2026-11-09) =====
    'dm_wipe_title'        => 'Wipe bot-to-staff direct messages',
    'dm_wipe_desc'         => 'Deletes the direct messages the bot sent to staff from Telegram (they disappear on the staff side too). Covers every notification: overdue escalation, shifts, clock-in, task cards, approvals, finance, wallet, follow-ups and login notices.',
    'dm_wipe_limit'        => '⚠ Telegram only allows deleting messages sent less than :hours hours ago. Anything older **can never be deleted** — that is what each person\'s "expired" count below means. The only way to clear those is to ask the staff member to delete the conversation themselves.',
    'dm_wipe_scope'        => '⚠ This protects against **someone getting hold of a staff member\'s phone or account**. It does not protect against a leaked bot token — whoever holds the token cannot read past messages anyway.',
    'dm_wipe_auto'         => 'Automatic wipe',
    'dm_wipe_auto_hint'    => 'Once on, the scheduler deletes direct messages older than the retention window every 10 minutes. ⚠ **The first run clears what is already there**, including notices staff have not read — the direct message IS the notification.',
    'dm_wipe_keep'         => 'Retain the last N hours',
    'dm_wipe_keep_hint'    => 'Messages within this window are left alone by the automatic wipe. ⚠ Max :max hours: anything past :hours hours can no longer be deleted, so setting it too close makes the automatic wipe look enabled while clearing nothing. Manual wipe **ignores this setting**.',
    'dm_wipe_target'       => 'Whose messages to wipe',
    'dm_wipe_action'       => 'Wipe now',
    'dm_wipe_action_hint'  => 'A manual wipe deletes **every** still-deletable direct message for that person regardless of the retention window — for departures and lost phones. ⚠ Irreversible, and any notice they have not read goes with it.',
    'dm_wipe_counts'       => ':pending deletable / :expired expired',
    'dm_wipe_confirm'      => 'Wipe :name\'s direct messages?',
    'dm_wipe_confirm_body' => 'This deletes the bot\'s direct messages from Telegram, including on their side and including unread notices. The action is irreversible and is recorded in the audit log.',
    'dm_wipe_log_title'    => 'Recent wipes',
    'dm_wipe_log_empty'    => 'No wipes recorded yet.',
    'dm_wipe_log_head'     => 'Time / operator / target / result',
    'dm_wipe_source_manual' => 'Manual',
    'dm_wipe_source_auto'  => 'Scheduled',
    'dm_wipe_no_permission' => 'You do not have permission to wipe direct messages.',


    // ===== Shift Handover =====
    /*
     * Shift handover (requested 2026-11-08).
     *
     * ⚠ `handover_interval_hint` must spell out that there is NO cap on the
     * number of reminders: whoever types 5 minutes would not otherwise realise
     * it means tagging a group of people every 5 minutes until someone acts.
     */
    'handover_title'   => 'Shift Handover',
    'handover_desc'    => 'Every shift must post its handover notes in the handover topic. The hour where two shifts\' reply windows overlap is the handover window; a few minutes before the incoming shift starts replying, the system tags both sides in the handover topic — the outgoing shift ("please post your handover") and the incoming one ("you are about to go live, read the handover first"). It counts as done once someone from that shift posts in the topic. ⚠ When the outgoing shift has nobody rostered that day there is **no nagging**; instead the incoming shift gets one notice quoting the previous handover (that is the current situation for the overnight shift, in which case read the daily "Morning Handover List").',
    'handover_enabled' => 'Enable handover reminders',
    'handover_enabled_hint' => 'While off, no handover message is ever sent; existing handover records are kept. ⚠ This one is **on by default** (every shift is expected to hand over).',
    'handover_lead'      => 'Start reminding this many minutes early',
    'handover_lead_hint' => 'Counted back from the incoming shift\'s reply start time. If the afternoon shift starts replying at 13:00 and this is 5, the first message goes out at 12:55.',
    'handover_interval'      => 'Repeat every (minutes) until handed over',
    'handover_interval_hint' => '⚠ **There is no cap** — it keeps nagging until someone from that shift posts in the handover topic (with nothing to hand over, a one-word "none" ends it). So this number is the only control over how noisy it gets; do not set it too low. Managers are tagged as well from reminder :escalate onward.',
    'handover_topic_missing' => '"Shift Handover" is not ticked on any topic yet (see the "Support Group & Topics" tab) — until it is, this feature stays inactive, so it never nags in the group\'s main area about something that cannot be completed there.',

    // ===== Auto Reply Auto-Resume =====
    /*
     * Auto-resume for auto reply (requested 2026-11-07).
     *
     * ⚠ The `resume_minutes` label must say "default": the number that actually
     * applies is typed by the agent in the chat window modal; this is only the
     * pre-filled value for that field.
     */
    'resume_title'   => 'Auto Reply Auto-Resume',
    'resume_desc'    => 'Agents uncheck auto reply to handle a conversation manually and then forget to check it back on, leaving that conversation manual forever. When enabled, a conversation that was switched to manual is re-enabled once it stays idle long enough, with a notice to the support group tagging whoever turned it off. ⚠ Only conversations turned off as "temporarily manual" are restored: conversations that never had auto reply on, and those turned off permanently, are never touched.',
    'resume_enabled' => 'Enable auto-resume',
    'resume_enabled_hint' => 'While off, no conversation switch is ever changed; existing turn-off records are kept.',
    'resume_minutes'      => 'Default idle minutes',
    'resume_minutes_hint' => '⚠ This is **only the pre-filled value for the chat window modal**, not a global setting — the number that applies is chosen by the agent turning it off (they know how long that case takes). Between :min and :max minutes.',

    // ===== Pending follow-ups =====
    'followup_title'   => 'Pending Follow-ups',
    'followup_desc'    => 'When someone in a chat says "hold on, I\'ll send it later", it is recorded as a pending follow-up. Once the chat has been quiet for a while, the system tags the on-duty staff in the support group and asks whether to follow up: choosing to follow up messages the customer about progress (or, when one of our own staff made the promise, tags the on-duty staff to pick it up instead), and choosing to drop it closes the item. If nobody presses a button it asks again at a fixed interval, and from the :escalate reminder onwards it also tags leaders and the boss.',
    'followup_enabled' => 'Enable pending follow-ups',
    'followup_enabled_hint' => 'When off, nothing is detected and no messages are sent. Items already recorded are kept.',
    'followup_keywords'      => 'Waiting phrases (one per line)',
    'followup_keywords_hint' => 'A phrase only triggers a second check where the AI confirms whether it really is a promise to provide something later, so err on the generous side. Leave blank to use the built-in set:',
    'followup_quiet'         => 'Ask after this much quiet (minutes)',
    'followup_quiet_hint'    => 'The on-duty staff are only asked once the chat has been quiet for this long after the promise. Anything the customer says restarts the timer — the system will not interrupt while they are still talking.',
    'followup_interval'      => 'Ask again every (minutes)',
    'followup_interval_hint' => 'While nobody has pressed a button, ask again at this interval. From the :escalate reminder onwards leaders and the boss are tagged as well.',
    'followup_max'           => 'Maximum times to ask',
    'followup_max_hint'      => 'After this many unanswered asks the system stops and sends a final notice to leaders and the boss (the buttons still work). The number of times the customer is chased is not limited by this.',
    'followup_stale'         => 'Days before an item can be closed out',
    'followup_stale_hint'    => 'Use 0 for never closing automatically (the default). With a number set, items are only closed when you run followup:close-stale — no schedule does it for you.',
    'followup_report_user'      => 'Send the daily summary to (multiple)',
    'followup_report_user_hint' => 'At :time each day the previous day\'s follow-up summary is sent by DM (opened, closed, still open, and who stalled the most). Anyone marked "not bound" cannot receive it — ask them to DM the bot once.',

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

    // ===== Task card notice =====
    'task_title'          => 'Task overview recipients',
    'task_desc'           => 'A team-wide task overview (how many overdue, due today and in progress each person has) is sent by direct message at :time daily. Pick as many recipients as you like; nothing is sent while none are ticked.',
    'task_user'           => 'Recipients (multiple)',
    'task_user_hint'      => 'Staff marked "not linked" cannot receive messages — ask them to message the bot once. People ticked here do not also receive the personal version.',
    'task_test'           => 'Send test',
    'task_test_hint'      => 'Sends today\'s overview to everyone ticked above right now. Only the overview — nobody else is disturbed.',
    'task_personal_title' => 'Personal task cards',
    'task_personal_desc'  => 'Every active member receives their own summary at :time daily: how many overdue, due today and in progress, plus the cards that need attention. People with no cards get a light-hearted line instead. This is automatic and needs no setup.',
    'task_count_note'     => 'A card can be assigned to several people, so the per-person numbers add up to more than the total card count — that is correct, each of them needs to know.',

    // Attendance reports
    'attendance_title'          => 'Attendance report recipients',
    'attendance_desc'           => 'The weekly report (:weekly) covers last Monday to Sunday; the monthly report (:monthly) covers last month. Both DM a summary for everyone (who had perfect attendance, who was late / left early / absent and by how much, who worked overtime). Pick as many people as you like; nothing is sent when no one is selected.',
    'attendance_user'           => 'Recipients (multiple)',
    'attendance_user_hint'      => 'The weekly and monthly reports share this list. Anyone marked "not linked" cannot receive messages — ask them to DM the bot once. People selected here do not also get the personal version.',
    'attendance_test'           => 'Send test',
    'attendance_test_hint'      => 'DMs last week\'s full report to the people selected above right away. Only the full version is sent, so no one else is disturbed.',

    'approval_title'           => 'Approval notice recipients',
    'approval_desc'            => 'DMs these people when someone requests leave, a clock amendment or a shift cover, and again once it has been approved or rejected. Pick as many as you like; with none selected nothing is sent (the request itself still goes through).',
    'approval_user'            => 'Recipients (multiple)',
    'approval_user_hint'       => 'People marked "not bound" cannot receive messages — ask them to DM the bot once first.',
    'approval_test'            => 'Send test',
    'approval_test_hint'       => 'DMs a test message to the people selected above right away. These notices are event-driven, so earlier ones cannot be resent.',
    'approval_events_title'    => 'What gets notified',
    'approval_event_leave'     => 'Leave: on request, and on approval or rejection',
    'approval_event_amendment' => 'Clock amendment: on request, and on approval or rejection',
    'approval_event_cover'     => 'Shift cover: on request, when the covering colleague accepts or declines, and on approval or rejection',
    'approval_swap_hint'       => 'Shift swaps are not included: a swap has no manager approval step — it takes effect as soon as the other colleague agrees, so there would be nothing to act on.',
    'finance_title'           => 'Finance request recipients',
    'finance_desc'            => 'Sends a DM as soon as someone uploads a VM payment proof or requests a credit top-up/deduction, and again once it has been reviewed. Pick as many people as you like; with nobody ticked nothing is sent (the upload and the request still go through).',
    'finance_user'            => 'Recipients (multiple allowed)',
    'finance_user_hint'       => 'Colleagues marked "not bound" cannot receive messages — ask them to DM the bot once first.',
    'finance_test'            => 'Send test',
    'finance_test_hint'       => 'Sends a test DM to the people ticked above right away. These notices fire when something is submitted, so earlier ones cannot be resent.',
    'finance_events_title'    => 'What gets notified',
    'finance_event_proof'     => 'VM payment proof: on upload, and when the payment is confirmed',
    'finance_event_topup'     => 'Top-up / deduction: on request, and when approved or rejected (approved but the main system failed is flagged separately)',
    'finance_split_hint'      => 'This list is separate from "Approvals": that one covers HR (leave, clock amendments, shift cover), this one covers money (payments, credits). Anyone who wants only one of them can tick just that side.',
    'wallet_title'            => 'Wallet transfer notifications',
    'wallet_desc'             => 'Sends a DM as soon as USDT (or any other TRC20 token) moves in or out of a watched TRON wallet — time, amount, token, From, To and transaction hash, plus a tappable Tronscan link. Notification only; nothing is acted on.',
    'wallet_enabled'          => 'Enable wallet transfer notifications',
    'wallet_addresses'        => 'Wallets to watch (address / label)',
    'wallet_address_add'      => 'Add a wallet',
    'wallet_address_hint'     => 'TRON addresses start with T and are 34 characters. The label shows up in the notification (e.g. "Main wallet"); it is optional.',
    'wallet_address_placeholder' => 'TRON address (starts with T)',
    'wallet_label_placeholder'   => 'Label (optional)',
    'wallet_min_amount'       => 'Minimum amount to notify',
    'wallet_min_amount_hint'  => 'Transfers below this are not notified (they are still recorded). Enter 0 to notify on everything.',
    'wallet_user'             => 'Recipients (multiple allowed)',
    'wallet_user_hint'        => 'Colleagues marked "not bound" cannot receive messages — ask them to DM the bot once first.',
    'wallet_test'             => 'Send test',
    'wallet_test_hint'        => 'Sends a test DM to the people ticked above right away. These notices fire on on-chain activity, so earlier ones cannot be resent.',
    'wallet_events_title'     => 'What gets notified',
    'wallet_event_in'         => 'USDT or another TRC20 token arrives',
    'wallet_event_out'        => 'Something is sent out of the wallet',
    'wallet_baseline_hint'    => '⚠️ After adding a wallet, the first check only records the current history as a baseline and sends nothing — otherwise thousands of past transfers would all go out at once. Notifications start from the second check (about a minute later).',
    'wallet_dust_hint'        => '⚠️ Address poisoning is common on TRON: an attacker sends 0.000001 USDT from a lookalike address so you copy the wrong one next time. The minimum amount is what blocks those — 1 or above is recommended.',
    'attendance_personal_title' => 'Personal attendance report',
    'attendance_personal_desc'  => 'Every active staff member receives their own copy: late / early-leave / absence counts and minutes, clock-in amendments, leave dates, and total overtime. Anyone with no lateness, early leave or absence gets a word of encouragement instead. This is sent automatically and needs no configuration.',
    'attendance_leave_note'     => 'Each person gets one of three lines in the full report: perfect attendance (no lateness, early leave or absence, and no leave taken), on leave (how much leave, plus "otherwise clean"), or issues (counts and minutes). Approved leave is not a problem, but it is not perfect attendance either.',

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
    'action_cancel'  => 'Cancel',
    'action_wiping'  => 'Wiping…',

    /*
     * Notification types that a topic can subscribe to.
     *
     * ⚠ Name them by **what lands in the group**, not by which feature they
     * belong to. The shift roster itself is a direct message and never reaches
     * the group — only the "who did not receive it" summary does.
     */
    'notice_type' => [
        'auto_reply_ticket'      => 'Questions forwarded by AI',
        'auto_reply_ticket_hint' => 'Tickets raised when the knowledge base has no answer, overdue reminders and outcomes. Staff answer by quoting here',
        'daily_rate'             => 'Daily rate quote',
        'daily_rate_hint'        => 'The 9:00 quote, the 30-minute reminders while undecided, and the outcome. Decided by quoting the message',
        'conversation_followup'      => 'Pending follow-ups',
        'conversation_followup_hint' => 'Asks the on-duty staff whether to follow up after someone promised something and the chat went quiet. Decided with the buttons on the message — no reply needed',
        'ticket_handover'        => 'Morning handover list',
        'ticket_handover_hint'   => 'Unhandled questions handed to the morning shift at 7:30 daily',
        'shift_notice'           => 'Staff who missed the roster',
        'shift_notice_hint'      => 'A summary of whoever could not be reached by direct message. The roster itself is a DM and never reaches the group',
        'vm_payment'             => 'VM payment notices',
        'vm_payment_hint'        => 'The 9:30 payment notice: falls back here when a station has no group, and chases internal approval',
        'station_credit'         => 'Station credit alerts',
        'station_credit_hint'    => 'The credit alert and top-up message sent on the first whole hour after the rate is decided, falling back here when a station has no group',

        /*
         * ⚠ This one means something different from the rest: the others pick
         * where a notice is *sent*; this one picks where reminders may be *set*.
         */
        // key stays scheduled_reminder; only the label changed (see tw for why)
        'task_change'             => 'Task card changes',
        'task_change_hint'        => 'Notifies when a task card is created, or its status, assignees, participants, due date, priority, project/station, or archive state changes. '
            . '⚠ Consecutive changes to the same card are **merged into one message** (sent after a few quiet minutes), not one per edit. '
            . 'Description, title and checklist ticks are **not** notified (too frequent)',

        /*
         * Auto reply re-enabled itself (requested 2026-11-07).
         *
         * ⚠ The hint must warn against the "AI questions" topic: that one is the
         * work queue where agents reply by quoting, and mixing in pure notices
         * makes its unread marker meaningless.
         */
        'auto_reply_resume'      => 'Auto Reply Resumed',
        'auto_reply_resume_hint' => 'Sent when a conversation has stayed idle long enough after being switched to manual and the system re-enables auto reply, tagging whoever turned it off. '
            . '⚠ Best kept in the same topic as "Morning Handover List"; do **not** tick it on "AI questions" (that topic is reserved for tickets needing quoted replies)',

        /*
         * Shift handover (requested 2026-11-08).
         *
         * ⚠ This one is **bidirectional**: besides "notices go here", a post by
         * that shift's staff in this topic counts as the handover being done.
         * The hint must say so, or people will chat here and silently clear
         * the reminder.
         */
        'shift_handover'      => 'Shift Handover',
        'shift_handover_hint' => 'The "time to hand over" reminder, tagging both the outgoing and incoming shift. '
            . '⚠ This is not only "send it here" — **a post by that shift\'s staff in this topic counts as the handover being completed**, '
            . 'so tick a topic dedicated to handovers rather than one used for chatter',

        'scheduled_reminder'      => 'Assistant',
        'scheduled_reminder_hint' => '⚠ This picks which topics may USE the assistant commands, not where replies go — '
            . 'the assistant always replies in the topic you spoke in. None ticked = every topic may use it',
    ],

    'msg' => [
        'saved'       => 'Saved',
        'save_failed' => 'Save failed, please try again later',

        // Support group
        'chat_id_invalid'     => 'Invalid chat_id format (group IDs are negative)',
        'chat_id_is_customer' => 'This chat_id already appears in the Telegram CS conversation list and cannot also be the internal support group (its messages would all be intercepted, never delivered and never auto-replied). Delete that conversation in Telegram CS first, then save again.'
            . ' | Common cause: after the support group was upgraded to a supergroup (which happens when Topics are enabled), its old chat_id stopped working and the system no longer recognised it — so it created a conversation for it as if it were a new customer. Deleting that one entry is enough.',
        'system_not_found'    => 'System not found',
        'support_test_sent'   => 'Test message sent, please check the group',
        'support_test_failed' => 'Failed to send. Check the chat_id, that the bot is in the group, and that Group Privacy is disabled',
        'chat_id_migrated'    => 'This group was upgraded to a supergroup (which happens when Topics are enabled) and its chat_id changed. The new one is :id — update the chat_id above and save.',

        // Reminders
        'remind_required'      => 'Please enter the reminder interval',
        'remind_invalid'       => 'Reminder interval must be between 1 and 1440 minutes',
        'remind_max_required'  => 'Please enter the maximum number of reminders',
        'remind_max_invalid'   => 'Maximum reminders must be between 1 and 200',

        'followup_keywords_invalid' => 'Waiting phrases must be 2000 characters or fewer in total',
        'followup_minutes_required' => 'Please enter the timing',
        'followup_minutes_invalid'  => 'Timing must be between 1 and 1440 minutes',
        'followup_max_required'     => 'Please enter the maximum number of asks',
        'followup_max_invalid'      => 'Number of asks must be between 1 and 200',
        'followup_stale_required'   => 'Please enter the number of days (use 0 to never close automatically)',
        'followup_stale_invalid'    => 'Days must be between 0 and 365',

        'dm_wipe_done'          => 'Cleared :cleared direct messages for :name.',
        'dm_wipe_with_expired'  => 'Cleared :cleared direct messages for :name. ⚠ Another :expired were sent more than 48 hours ago and Telegram will never allow deleting them — those are still in their Telegram; only they can remove the conversation.',
        'dm_wipe_partial'       => 'Cleared :cleared direct messages for :name; :failed could not be sent this time (left unprocessed, the scheduler will retry). Another :expired are past 48 hours and can never be deleted.',
        'dm_wipe_no_user'       => 'No such person, or they have no Telegram linked (without it there are no direct messages).',
        'dm_wipe_failed'        => 'Failed to wipe direct messages.',
        'dm_wipe_hours_required' => 'Enter a retention window.',
        'dm_wipe_hours_invalid' => 'The retention window must be a whole number between :min and :max hours (:max or more is past Telegram\'s deletion deadline).',
        'dm_wipe_removed_account' => '(deleted account)',
        'call_test_sent'        => 'Called :name — check that their phone rang (there is no voice content and answering drops the line; that is normal)',
        'call_test_not_ready'   => 'Calling is not set up yet — run php artisan telegram:call-login on the server first',
        'call_test_no_user'     => 'No such person, or they have no Telegram username (without it they cannot be called)',
        'call_test_privacy'     => 'Could not call :name — their Telegram "Who can call me" is set to My Contacts. Add them to this bot account\'s contacts, or ask them to change the setting',
        'call_test_not_found'   => 'Could not find :name on Telegram — check the username they entered, or add them to the bot account\'s contacts first',
        'call_test_no_session'  => 'The bot is not logged in — run php artisan telegram:call-login on the server',
        'call_test_no_telethon' => 'The Python used for calling has no telethon — usually TG_CALL_PYTHON in .env does not point at that venv (absolute path required)',
        'call_test_error'       => 'Call to :name failed: :error',
        // No :name/:error here — the frontend uses this when it cannot reach the backend at all
        'call_test_failed'      => 'Test call failed',
        'escalation_trigger_required' => 'Please enter how many reminders before escalating',
        'escalation_trigger_invalid'  => 'The escalation threshold must be between 2 and 200',
        'escalation_trigger_too_high' => 'The escalation threshold must be lower than "Maximum reminders" (currently :max) — otherwise reminders stop before escalation and the phone never rings',
        'escalation_ring_required'    => 'Please enter how long the call should ring',
        'escalation_ring_invalid'     => 'Ring duration must be between 5 and 90 seconds',
        'escalation_interval_required' => 'Please enter the gap between calls',
        'escalation_interval_invalid'  => 'The gap between calls must be between 3 and 60 minutes (too short gets the bot account flagged as harassment)',
        'remind_max_below_escalation'  => 'The reminder cap must be greater than the escalation threshold (currently reminder :trigger) — otherwise reminders stop before escalation and the phone never rings',

        'handover_lead_required'     => 'Please enter how many minutes early to start reminding',
        'handover_lead_invalid'      => 'Lead time must be between 1 and 60 minutes',
        'handover_interval_required' => 'Please enter the repeat interval',
        'handover_interval_invalid'  => 'Repeat interval must be between 5 and 1440 minutes (this feature has no reminder cap, so under 5 minutes is not accepted)',

        'resume_minutes_required' => 'Please enter the default idle minutes',
        'resume_minutes_range'    => 'Default idle minutes must be between :min and :max',
        'report_user_not_found' => 'Account not found',
        'report_test_sent'      => ':sent test summary message(s) sent, please check Telegram',
        'report_test_partial'   => ':sent sent, but these people did not receive it: :failed (ask them to message the bot once)',
        'report_test_no_user'   => 'Please tick at least one recipient and save first',
        'report_test_not_bound' => 'None of the ticked recipients has messaged the bot yet, so they cannot receive messages',
        'report_test_failed'    => 'Send failed, please check the bot is not blocked',

        // Task cards
        'task_user_not_found' => 'Account not found',
        'task_test_sent'      => ':sent test overview message(s) sent, please check Telegram',
        'task_test_partial'   => ':sent sent, but these people did not receive it: :failed (ask them to message the bot once)',
        'task_test_no_user'   => 'Please tick at least one recipient and save first',
        'task_test_not_bound' => 'None of the ticked recipients has messaged the bot yet, so they cannot receive messages',
        'task_test_failed'    => 'Send failed, please check the bot is not blocked',

        'attendance_user_not_found' => 'Account not found',
        'attendance_test_sent'      => 'Test report sent (:sent) — please check Telegram',
        'attendance_test_partial'   => 'Sent :sent, but these people did not receive it: :failed (ask them to DM the bot once)',
        'attendance_test_no_user'   => 'Select recipients and save first',
        'attendance_test_not_bound' => 'None of the selected people have DMed the bot yet, so they cannot receive messages',
        'attendance_test_failed'    => 'Send failed, please check the bot is not blocked',

        'approval_user_not_found' => 'Account not found',
        'approval_test_sent'      => 'Test message sent (:sent). Please check Telegram.',
        'approval_test_partial'   => 'Sent :sent, but these did not receive it: :failed (ask them to DM the bot once first)',
        'approval_test_no_user'   => 'Please select recipients and save first',
        'approval_test_not_bound' => 'None of the selected people have DMed the bot yet, so they cannot receive messages',
        'approval_test_failed'    => 'Send failed, please check the bot is not blocked',
        'finance_user_not_found'  => 'Account not found',
        'finance_test_sent'       => ':sent test message(s) sent, please check Telegram',
        'finance_test_partial'    => ':sent sent, but these did not receive it: :failed (ask them to DM the bot once first)',
        'finance_test_no_user'    => 'Please tick the recipients and save first',
        'finance_test_not_bound'  => 'None of the ticked colleagues has DM\'d the bot yet, so they cannot receive messages',
        'finance_test_failed'     => 'Send failed, please check the bot is not blocked',
        'wallet_user_not_found'   => 'Account not found',
        'wallet_address_required' => 'Enter a wallet address, or remove the row',
        'wallet_address_invalid'  => 'Invalid wallet address (TRON addresses start with T and are 34 characters)',
        'wallet_address_too_many' => 'Too many wallets, at most :max',
        'wallet_label_too_long'   => 'Label must be 50 characters or fewer',
        'wallet_min_amount_invalid' => 'Minimum amount must be a number of 0 or more',
        'wallet_test_sent'        => ':sent test message(s) sent, please check Telegram',
        'wallet_test_partial'     => ':sent sent, but these did not receive it: :failed (ask them to DM the bot once first)',
        'wallet_test_no_user'     => 'Please tick the recipients and save first',
        'wallet_test_not_bound'   => 'None of the ticked colleagues has DM\'d the bot yet, so they cannot receive messages',
        'wallet_test_failed'      => 'Send failed, please check the bot is not blocked',

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
