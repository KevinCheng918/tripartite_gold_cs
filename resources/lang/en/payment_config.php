<?php

return [
    'nav_label'   => 'Payment Config',
    'page_title'  => 'Payment Config',
    'subtitle'    => 'Configure payment info & templates per system',

    'field_system'     => 'System',
    'field_title'      => 'Payment Method',
    'field_content'    => 'Payment Info',
    'field_template'   => 'Message Template',
    'field_image'      => 'Payment Image',
    'field_status'     => 'Status',
    'field_sort'       => 'Sort Order',

    'template_hint'    => 'Variables: {station} station, {amount} amount, {month} month, {due_date} due date, {content} payment info. Wrap with <code>text</code> for tap-to-copy in Telegram',
    'template_example' => "[Payment Notice]\nStation: {station}\nMonth: {month}\nAmount: <code>{amount}</code>\n\n{content}",

    'status_active'   => 'Active',
    'status_disabled' => 'Disabled',

    // Station credit alert
    'alert_section_title'    => 'Station Credit Alert',
    'alert_section_subtitle' => 'Syncs every station\'s system credit at 10:00 daily and sends a Telegram reminder when it falls below the threshold',
    'alert_field_threshold'  => 'Alert Threshold',
    'alert_field_cooldown'   => 'Repeat Interval',
    'alert_field_template'   => 'Alert Template',
    'alert_threshold_hint'   => 'Alert when credit drops below this. Each station can set its own threshold in Station Management; leave that blank to use this one. Set 0 to disable alerts',
    'alert_cooldown_hint'    => 'Days before alerting the same station again. 1 means once a day; 0 means every run',
    'alert_template_hint'    => 'Variables: {station} station name, {credit} current credit, {threshold} alert threshold',
    'alert_target_hint'      => 'Alerts go to the station\'s own Telegram group. If the station has no group, the alert goes to the internal support group instead, flagged as not sent to the customer',
    'topup_field_template'   => 'Top-up Message',
    'topup_template_hint'    => 'Variables: {rate} today\'s rate, {station} station name. Leave blank to omit',
    'topup_condition_hint'   => "This is appended to the credit alert sent to the customer, but only when today's rate has been decided — otherwise just the alert goes out, since the customer wouldn't know how much to transfer",
    'topup_template_example' => "————————\n💰 Today's top-up rate: {rate}\nJust let us know if you'd like to top up and we'll take care of it right away 🙏",
    'alert_preview'          => 'Preview',
    'alert_preview_title'    => 'What the customer receives',
    'alert_preview_station'  => 'Sample Station',
    'action_save_alert'      => 'Save Alert Settings',

    'action_create' => 'Add Config',
    'action_edit'   => 'Edit',
    'action_delete' => 'Delete',
    'action_copy'   => 'Copy Text',
    'action_send'   => 'Send Notice',

    'msg' => [
        'created'       => 'Payment config created',
        'create_failed' => 'Failed to create',
        'updated'       => 'Payment config updated',
        'update_failed' => 'Failed to update',
        'deleted'       => 'Payment config deleted',
        'delete_failed' => 'Failed to delete',
        'copied'        => 'Text copied',
        'sent'          => 'Notice sent',
        'send_failed'   => 'Failed to send',
        'no_config'     => 'No payment config for this system',
        'no_telegram'   => 'No Telegram group for this station',

        // Credit alert settings
        'alert_saved'              => 'Alert settings saved',
        'alert_save_failed'        => 'Failed to save alert settings',
        'alert_template_required'  => 'Alert template is required',
        'alert_template_max'       => 'Alert template must not exceed :value characters',
        'alert_threshold_required' => 'Alert threshold is required',
        'alert_threshold_numeric'  => 'Alert threshold must be a number',
        'alert_threshold_min'      => 'Alert threshold cannot be negative',
        'alert_cooldown_required'  => 'Repeat interval is required',
        'alert_cooldown_integer'   => 'Repeat interval must be a whole number of days',
        'alert_cooldown_max'       => 'Repeat interval must not exceed :value days',
    ],
];
