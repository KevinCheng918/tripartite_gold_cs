<?php

return [
    'nav_label'  => 'Shift Notice',
    'page_title' => 'Shift Notice',
    'subtitle'   => 'Daily shift roster sent by direct message every morning',

    'section_label' => 'Messaging',

    'manager_title' => 'Full roster recipient',
    'manager_desc'  => 'The full roster (who is on each shift, which shift has nobody) is sent by direct message at :time daily. Nothing is sent while no recipient is set.',
    'manager_field'  => 'Recipient',
    'manager_none'   => '— Do not send —',
    'manager_hint'   => 'Staff marked "not linked" cannot receive messages. Ask them to message the bot once.',
    'manager_unbound' => 'not linked',

    'personal_title' => 'Personal roster',
    'personal_desc'  => 'Everyone rostered today receives their own entry at :time (their shift and hours only, never anyone else\'s). This is automatic and needs no setup.',

    'bind_title' => 'Why some people receive nothing',
    'bind_step1' => 'Telegram does not let a bot message someone who has never talked to it.',
    'bind_step2' => 'Ask them to open the support bot on Telegram and send any message (e.g. "hi").',
    'bind_step3' => 'The system records it automatically, and the roster arrives the next morning.',
    'bind_note'  => 'Only needed once. Posting in a group does not count — it must be a direct message.',

    'action_save'    => 'Save',
    'action_saving'  => 'Saving…',
    'action_test'    => 'Send test',
    'action_testing' => 'Sending…',
    'test_hint'      => 'Sends today\'s full roster to the recipient above right now.',

    'msg' => [
        'saved'             => 'Saved',
        'save_failed'       => 'Save failed, please try again later',
        'manager_invalid'   => 'Invalid recipient',
        'manager_not_found' => 'Account not found',
        'test_sent'         => 'Test message sent, please check Telegram',
        'test_no_manager'   => 'Please select a recipient and save first',
        'test_not_bound'    => 'This person has not messaged the bot yet, so they cannot receive messages',
        'test_failed'       => 'Send failed, please check the bot is not blocked',
    ],
];
