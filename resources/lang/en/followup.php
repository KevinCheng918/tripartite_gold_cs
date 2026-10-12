<?php

return [
    'nav_label'  => 'Follow-ups',
    'page_title' => 'Pending Follow-ups',
    'subtitle'   => 'Recorded whenever someone in a chat says they will send something later',

    'section_label' => 'Customer Service',

    'desc' => 'Normally the on-duty staff decide with the buttons in the support group. This page is for seeing everything, or for when that button message has scrolled away.',

    // Filters
    'filter_source'     => 'Source',
    'filter_status'     => 'Status',
    'filter_group'      => 'Conversation',
    'filter_all'        => 'All',
    'filter_open_only'  => 'Open only',
    // The range filters on when the item was opened; blank means no limit
    'filter_date_from'  => 'Opened from',
    'filter_date_to'    => 'Opened to',
    'filter_search'     => 'Search',
    'filter_reset'      => 'Clear',

    // Table
    'col_source'  => 'Source',
    'col_group'   => 'Conversation',
    'col_sender'  => 'Said by',
    'col_text'    => 'What they said',
    'col_opened_at' => 'Opened',
    'col_waited'  => 'Waiting',
    // Measured from the last message, not from when the item was opened
    'col_waited_hint' => 'Measured from their last message. Anything they say while we wait restarts the clock, so this can be much later than when the item was opened.',
    'col_counts'  => 'Stalls / Asks',
    'col_status'  => 'Status',
    'col_decided' => 'Decided by',
    'col_action'  => 'Actions',

    'counts'      => ':waits / :rounds',

    'waited_days'  => ':days d :hours h',
    'waited_hours' => ':hours h :minutes m',
    'waited_mins'  => ':minutes m',
    'keyword'     => 'Matched: :keyword',
    'empty'       => 'No follow-ups match these filters',
    'never_asked' => 'Not asked yet',

    // Actions
    'action_follow' => 'Follow up',
    // Decide later — ask again after things go quiet
    'action_defer'  => 'Keep waiting',
    'action_close'  => 'Close',

    'confirm_follow_customer' => 'Follow up? The system will message the customer about progress.',
    'confirm_follow_staff'    => 'Follow up? The system will tag the on-duty staff in the support group to pick this up. The customer is not messaged.',
    // Must say both "we will ask again" and "the count still stands" — otherwise it reads as closing, or as stalling forever
    'confirm_defer'           => 'Leave this undecided for now? The next prompt is pushed back and you will be asked again once things go quiet. The customer is not messaged and nothing is closed; rounds already asked still count, so this cannot be deferred forever.',
    'confirm_close'           => 'Close this? The topic ends here and no further reminders are sent.',
    'confirm_ok'              => 'Confirm',
    'confirm_cancel'          => 'Cancel',

    'msg' => [
        'not_found'       => 'This follow-up has already been handled',
        'decided'         => 'Done',
        'decide_required' => 'Please choose: follow up, keep waiting, or close',
        'decide_failed'   => 'Could not complete that, please try again',
        'load_failed'     => 'Could not load the list, please try again',
        'date_invalid'    => 'Invalid date format',
        'range_required'  => 'Pick both a start and an end date',
        'range_reversed'  => 'End date cannot be earlier than start date',
    ],
];
