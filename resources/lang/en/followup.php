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
    'filter_search'     => 'Search',
    'filter_reset'      => 'Clear',

    // Table
    'col_source'  => 'Source',
    'col_group'   => 'Conversation',
    'col_sender'  => 'Said by',
    'col_text'    => 'What they said',
    'col_waited'  => 'Waiting',
    'col_counts'  => 'Stalls / Asks',
    'col_status'  => 'Status',
    'col_decided' => 'Decided by',
    'col_action'  => 'Actions',

    'counts'      => ':waits / :rounds',
    'keyword'     => 'Matched: :keyword',
    'empty'       => 'No follow-ups match these filters',
    'never_asked' => 'Not asked yet',

    // Actions
    'action_follow' => 'Follow up',
    'action_close'  => 'Close',

    'confirm_follow_customer' => 'Follow up? The system will message the customer about progress.',
    'confirm_follow_staff'    => 'Follow up? The system will tag the on-duty staff in the support group to pick this up. The customer is not messaged.',
    'confirm_close'           => 'Close this? The topic ends here and no further reminders are sent.',
    'confirm_ok'              => 'Confirm',
    'confirm_cancel'          => 'Cancel',

    'msg' => [
        'not_found'       => 'This follow-up has already been handled',
        'decided'         => 'Done',
        'decide_required' => 'Please choose whether to follow up or close',
        'decide_failed'   => 'Could not complete that, please try again',
        'load_failed'     => 'Could not load the list, please try again',
    ],
];
