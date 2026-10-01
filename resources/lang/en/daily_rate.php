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
    ],
];
