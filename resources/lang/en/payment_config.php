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

    'template_hint'    => 'Variables: {station} station, {amount} amount, {month} month, {content} payment info. Wrap with <code>text</code> for tap-to-copy in Telegram',
    'template_example' => "[Payment Notice]\nStation: {station}\nMonth: {month}\nAmount: <code>{amount}</code>\n\n{content}",

    'status_active'   => 'Active',
    'status_disabled' => 'Disabled',

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
    ],
];
