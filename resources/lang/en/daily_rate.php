<?php

return [
    'nav_label'  => 'Daily Rate',
    'page_title' => 'Daily Rate',
    'subtitle'   => 'Posted to the internal support group at 09:00 daily; a teammate replies to set the customer-facing rate',

    'today_title'    => "Today's Rate",
    'today_pending'  => 'Not set yet',
    'today_hint'     => 'Until the rate is set, customers asking about it are told it will be confirmed shortly',

    'field_date'           => 'Date',
    'field_rate'           => 'Customer Rate',
    'field_reference_rate' => '4H Average',
    'field_suggested_rate' => 'Suggested',
    'field_yesterday_rate' => 'Previous',
    'field_replier'        => 'Set By',
    'field_replied_at'     => 'Set At',
    'field_remind_count'   => 'Reminders',

    'template_title'    => 'Message Template',
    'template_hint'     => 'Variables: {date} date, {reference} 4H average, {suggested} suggested rate, {yesterday} previous rate, {yesterday_date} previous date',
    'template_preview'  => 'Preview',
    'template_preview_title' => 'What the team sees in the group',

    'action_edit'    => 'Edit',
    'action_save'    => 'Save',
    'action_preview' => 'Preview',

    'edit_title' => 'Set Rate',
    'edit_hint'  => 'Setting it here overrides the reply from the group. Past dates can be backfilled; future dates cannot',

    'empty' => 'No rate records yet',

    // Chart screenshot
    'screenshot_title'     => 'Chart Screenshot',
    'screenshot_ready'     => 'This machine can take screenshots',
    'screenshot_missing'   => 'No usable Chrome on this machine — the quote will be text only',
    'screenshot_hint'      => "The quote includes a screenshot of MAX's chart. If it can't be captured the quote still goes out, just without the image",
    'screenshot_arm_note'  => 'This machine is arm64 — there is no official Linux Chrome for it. Screenshots only work on the x86_64 production machine',
    'screenshot_install'   => 'Install (x86_64 Linux): apt-get install -y google-chrome-stable',
    'action_test_shot'     => 'Test Screenshot',
    'action_ask_now'       => 'Post Now',
    'ask_now_hint'         => 'Runs the whole 09:00 routine now and posts to the internal group, so you can reply there to test the full loop',
    'ask_now_confirm'      => "This really posts to the internal support group and replaces today's existing post. Continue?",

    'msg' => [
        'saved'                 => 'Rate saved',
        'save_failed'           => 'Failed to save rate',
        'template_saved'        => 'Template saved',
        'template_save_failed'  => 'Failed to save template',
        'date_required'         => 'Date is required',
        'date_future'           => 'Cannot set a rate for a future date',
        'rate_required'         => 'Rate is required',
        'rate_numeric'          => 'Rate must be a number',
        'rate_min'              => 'Rate must be greater than 0',
        'template_required'     => 'Template is required',
        'template_max'          => 'Template must not exceed :value characters',

        'ask_sent'            => 'Quote posted to the internal support group (no chart this time)',
        'ask_sent_with_chart' => 'Quote posted to the internal support group with the chart',
        'ask_no_support_group' => 'Internal support group is not configured',
        'ask_send_failed'      => 'Failed to post the quote — see the log',
        'ask_already_decided'  => "Today's rate is already decided",

        'screenshot_sent'             => 'Screenshot sent to the internal support group (using :binary)',
        'screenshot_no_chrome'        => 'No usable Chrome on this machine',
        'screenshot_no_support_group' => 'Internal support group is not configured',
        'screenshot_capture_failed'   => 'Screenshot failed — see the log (the site may be slow, or the wait time too short)',
        'screenshot_send_failed'      => 'Captured but failed to send — see the log',
    ],
];
