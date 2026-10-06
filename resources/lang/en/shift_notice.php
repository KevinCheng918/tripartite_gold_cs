<?php

return [
    'nav_label'  => 'Shift Notice',
    'page_title' => 'Shift Notice',
    'subtitle'   => 'Daily shift roster sent by direct message every morning',

    'section_label' => 'Messaging',

    'manager_title' => 'Full roster recipients',
    'manager_desc'  => 'The full roster (who is on each shift, which shift has nobody) is sent by direct message at :time daily. Pick as many recipients as you like; nothing is sent while none are ticked.',
    'manager_field'  => 'Recipients (multiple)',
    'manager_all'    => 'Select all',
    'manager_hint'   => 'Staff marked "not linked" cannot receive messages — ask them to message the bot once. Untick everyone to stop sending.',
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
    'test_hint'      => 'Sends today\'s full roster to everyone ticked above right now.',

    'msg' => [
        'saved'             => 'Saved',
        'save_failed'       => 'Save failed, please try again later',
        'manager_invalid'   => 'Invalid recipient',
        'manager_not_found' => 'Account not found',
        'test_sent'         => ':sent test message(s) sent, please check Telegram',
        'test_partial'      => ':sent sent, but these people did not receive it: :failed (ask them to message the bot once)',
        'test_no_manager'   => 'Please tick at least one recipient and save first',
        'test_not_bound'    => 'None of the ticked recipients has messaged the bot yet, so they cannot receive messages',
        'test_failed'       => 'Send failed, please check the bot is not blocked',
    ],
];
