<?php

return [
    'nav_label'        => 'Stations',
    'page_title'       => 'Station Management',
    'subtitle'         => 'Client station list',
    'section_label'    => 'Station Management',
    'field_name'       => 'Station Name',
    'field_domain'     => 'Domain',
    'field_credits'    => 'Credits',
    'field_telegram'   => 'Telegram Group',
    'field_status'     => 'Status',
    'field_telegram_chat_id' => 'Telegram Chat ID',
    'bot_groups_title'       => 'Bot Groups',

    'action_topup_notice'  => 'Top-up Notice',

    // Shown when the top-up notice button is disabled — name the missing fields
    'topup_notice_disabled' => 'Cannot send a top-up notice: :missing not configured',
    'topup_notice_missing'  => [
        'api_url'           => 'API URL',
        'api_key'           => 'API key',
        'telegram_group_id' => 'Telegram group',
    ],

    'field_credit_alert_threshold'       => 'Credit Alert Threshold',
    'credit_alert_threshold_placeholder' => 'Leave blank to use the global setting',
    'credit_alert_threshold_hint'        => 'Alert when this station\'s credit drops below this number. Leave blank to use the global threshold from Payment Config; set 0 to disable alerts for this station',
    'field_note'       => 'Note',
    'status_active'    => 'Active',
    'status_frozen'    => 'Frozen',
    'status_disabled'  => 'Disabled',
    'no_data'          => 'No data',
    'action_create'    => 'New Station',
    'action_edit'      => 'Edit',
    'action_detail'    => 'Detail',
    /*
     * Added on 2026-10-10 while reworking the action column.
     *
     * The last two column headings both read "Sync" — one was the last sync
     * time, the other the action column holding the sync button. The time
     * column is now "Last sync".
     */
    'action_change_status' => 'Change Status',
    'field_synced_at'      => 'Last Sync',
    'field_action'         => 'Actions',
    /*
     * The top-up list used to have separate "Images" and "Actions" columns,
     * with the read-only "Note" button mixed into the latter. On 2026-10-10
     * images and notes merged into one "Attachments" column (two small icon
     * chips), leaving only approve/reject — the actions that change data.
     */
    'topup_field_attachment' => 'Attachments',
    'topup_image_count'      => ':count image(s)',

    // Detail modal (4 cards: station / features / deposit / withdraw)
    'detail' => [
        'section_station'    => 'Station Info',
        'section_feature'    => 'Features',
        'section_deposit'    => 'Deposit',
        'section_withdraw'   => 'Withdraw',
        'shop'               => 'Shop',
        'score_runner'       => 'Score Runner',
        'deposit_rate'       => 'Deposit rate',
        'self_deposit_rate'  => 'Same-system deposit rate',
        'usdt_deposit'       => 'USDT deposit',
        'atm_deposit'        => 'ATM deposit',
        'cvs_deposit'        => 'CVS deposit',
        'cc_deposit'         => 'Credit card',
        'qr_deposit'         => 'QR deposit',
        'withdraw'           => 'Withdraw',
        'withdraw_rate'      => 'Withdraw rate',
        'self_withdraw_rate' => 'Same-system withdraw rate',
        'enabled'            => 'Enabled',
        'disabled'           => 'Disabled',
        'free'               => 'No fee',
        'not_enabled'        => 'Not enabled',
        'not_found'          => 'Station not found',
    ],

    'msg' => [
        'created'       => 'Station created',
        'updated'       => 'Station updated',
        'create_failed' => 'Failed to create station',
        'update_failed' => 'Failed to update station',
        'sync_success'  => 'Credits synced successfully',
        'sync_failed'   => 'Credits sync failed (API not configured or connection error)',

        // Manual top-up notice
        'topup_notice_sent'            => 'Top-up notice sent to the customer. Current credit: :credits',
        'topup_notice_above_threshold' => 'credit is above the threshold, sent anyway as requested',
        'topup_notice_no_rate'         => "today's rate is not decided yet, so only the credit alert went out",
        'topup_notice_no_template'     => 'this system has no top-up message configured, so only the credit alert went out',
        'topup_notice_topup_failed'    => 'the credit alert was sent, but the top-up message failed — see the log',
        'topup_notice_no_group'        => 'This station has no Telegram group configured',
        'topup_notice_sync_failed'     => 'Failed to sync credits — nothing was sent, to avoid showing a stale number',
        'topup_notice_send_failed'     => 'Failed to send — see the log',
    ],

    // Topup
    'tab_topup'           => 'Topup Records',
    'topup_title'         => 'Apply Topup / Deduction',
    'topup_action_add'    => 'Add',
    'topup_action_deduct' => 'Deduct',
    'topup_credit'        => 'System Credit',
    'topup_shop_credit'   => 'Shop Credit',
    'topup_other'              => 'Other',
    'topup_other_hint'         => '"Other" does not call the main system API — no credits are actually added or deducted, this only keeps a record here. Note is required.',
    'topup_other_note_required' => 'Note is required when credit type is "Other" (otherwise there is no way to tell what this record was for)',
    'topup_field_station'  => 'Station',
    'topup_field_action'   => 'Action',
    'topup_field_type'     => 'Credit Type',
    'topup_field_input_type'  => 'Input Method',
    'topup_input_usdt'        => 'Convert from USDT',
    'topup_input_credit'      => 'Enter Credits Directly',
    'topup_input_credit_hint' => 'For customers topping up credits directly; excluded from the average exchange rate',
    'topup_field_twd'         => 'TWD',
    'topup_field_usdt'     => 'USDT Quantity',
    'topup_field_rate'     => 'Exchange Rate',
    'topup_field_amount'   => 'Credit Amount',
    'topup_field_status'   => 'Status',
    'topup_field_requester' => 'Requester',
    'topup_field_note'     => 'Note',
    'topup_field_date_from' => 'From',
    'topup_field_date_to'   => 'To',
    'topup_status_pending'   => 'Pending',
    'topup_status_completed' => 'Completed',
    'topup_status_rejected'  => 'Rejected',
    'topup_status_failed'    => 'API Failed',
    'topup_submitted'      => 'Topup application submitted',
    'topup_approved'       => 'Topup approved, API call succeeded',
    'topup_rejected'       => 'Topup rejected',
    'topup_api_failed'     => 'Topup API call failed',

    'credit_report' => [
        'page_title'     => 'Credit Report',
        'action'         => 'Credit report',
        'date_from'      => 'From',
        'date_to'        => 'To',
        'search'         => 'Search',
        'chart_label'    => 'Credits',
        'tooltip_source' => 'Source',
        'summary_range'  => 'Period',
        'summary_first'  => 'Opening',
        'summary_last'   => 'Closing',
        'summary_diff'   => 'Change',
        'empty'          => 'No sync records in this period.',
        'empty_only_failed' => 'Every sync in this period returned no credits, so there is no line to draw. See the list below.',
        'load_failed'    => 'Failed to load, please try again later',
        'chart_hint'     => 'Every sync writes one record (daily schedule, manual sync, assistant credit query, low-balance alert and VM affordability check all count). The line is stepped because we only know the value at each sync — what happened in between is unknown.',
        'failure_title'  => 'Failed syncs',
        'failure_hint'   => 'These syncs returned no credits (the main system did not respond), so the line breaks there.',
        'failure_at'     => 'Time',
        'failure_source' => 'Source',
    ],

    'credit_source' => [
        'schedule' => 'Daily schedule',
        'manual'   => 'Manual sync',
        'command'  => 'Assistant query',
        'alert'    => 'Low-balance alert',
        'afford'   => 'VM affordability',
    ],
];
