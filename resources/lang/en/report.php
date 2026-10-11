<?php

/**
 * Reports (Back office → Reports)
 *
 * Brings the summaries that previously only existed in Telegram into the admin.
 */

return [
    'nav_label'  => 'Reports',
    'page_title' => 'Reports',
    'subtitle'   => 'Attendance report, overdue reminder summary',

    'tab_attendance' => 'Attendance',
    'tab_remind'     => 'Overdue reminders',

    // Period: pick your own range, with shortcut buttons underneath
    'date_from' => 'From',
    'date_to'   => 'To',
    'search'    => 'Search',

    // Attendance report
    'att_user'      => 'Staff',
    'att_days'      => 'Days worked',
    'att_normal'    => 'Normal',
    'att_late'      => 'Late',
    'att_early'     => 'Left early',
    'att_absent'    => 'Absent',
    'att_amend'     => 'Amendments',
    'att_leave'     => 'Leave',
    'att_overtime'  => 'Overtime',
    'att_detail_hint' => 'Click a row to see that person\'s attendance detail.',

    // Overdue reminder summary
    'remind_summary'   => ':tickets tickets reminded, :times reminders in total; :escalated of them were chased more than :escalate_at times',

    // The ones nobody has dealt with: appended to the summary, flagged on the row too
    'remind_pending_badge'   => 'Still open',
    'remind_pending_summary' => ':n still unhandled',
    'remind_pending_none'    => 'Everything that was chased has been dealt with',
    'remind_by_ticket' => 'By ticket',
    'remind_by_user'   => 'By person',
    'remind_question'  => 'Question',
    'remind_group'     => 'Customer group',
    'remind_times'     => 'Reminders',
    'remind_status'    => 'Status',
    'remind_user'      => 'Staff',
    'remind_tickets'   => 'Tickets',
    'remind_no_target' => '(nobody was tagged)',

    /*
     * "Mark as handled" — the only way out of the red badge on this report.
     *
     * ⚠ The confirmation must say it does **not** reply to the customer:
     * it only closes the ticket, and people assume the system answers for them.
     */
    'remind_action'       => 'Action',
    'remind_mark_handled' => 'Mark as handled',
    'remind_confirm_mark' => 'Mark this question as "handled by support"? Reminders stop and the support group is notified. This does not reply to the customer, nor add the question to the library.',
    'confirm_ok'          => 'Confirm',
    'confirm_cancel'      => 'Cancel',
    'remind_manager_note' => 'Supervisors and above are left out of the per-person list — they only get tagged from the third reminder onward, and were never the ones meant to answer.',

    'empty'      => 'No data for this period',
    'unit_min'   => ':n min',
    'unit_hour'  => ':n h',
    'unit_day'   => ':n d',
    'unit_times' => ':n',

    'msg' => [
        'load_failed'     => 'Failed to load, please try again',
        'date_invalid'    => 'Invalid date format',
        'range_required'  => 'Please pick a start and end date',
        'range_reversed'  => 'End date cannot be earlier than the start date',
        'range_too_long'  => 'At most :days days per query — please shorten the range',

        // Mark as handled
        'ticket_handled'  => 'Marked as handled — this question will not be reminded again',
        'ticket_missing'  => 'This support ticket no longer exists; it may have been deleted',
        'ticket_done'     => 'Someone just handled this ticket — search again to see its current status',
        'handle_failed'   => 'Could not mark it as handled, please try again later',
    ],
];
