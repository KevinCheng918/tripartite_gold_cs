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

    // Period switch
    'period_daily'   => 'Daily',
    'period_weekly'  => 'Weekly',
    'period_monthly' => 'Monthly',
    'prev'           => 'Previous',
    'next'           => 'Next',
    'current'        => 'Latest',

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
    'remind_by_ticket' => 'By ticket',
    'remind_by_user'   => 'By person',
    'remind_question'  => 'Question',
    'remind_group'     => 'Customer group',
    'remind_times'     => 'Reminders',
    'remind_status'    => 'Status',
    'remind_user'      => 'Staff',
    'remind_tickets'   => 'Tickets',
    'remind_no_target' => '(nobody was tagged)',
    'remind_manager_note' => 'Supervisors and above are left out of the per-person list — they only get tagged from the third reminder onward, and were never the ones meant to answer.',

    'empty'      => 'No data for this period',
    'unit_min'   => ':n min',
    'unit_hour'  => ':n h',
    'unit_day'   => ':n d',
    'unit_times' => ':n',

    'msg' => [
        'load_failed'  => 'Failed to load, please try again',
        'type_invalid' => 'Invalid period type',
        'date_invalid' => 'Invalid date format',
    ],
];
