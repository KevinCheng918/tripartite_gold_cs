<?php

return [
    'tab_title'       => 'Leave Management',
    'tab_my_leave'    => 'My Leave',
    'tab_review'      => 'Leave Review',

    'action_apply'    => 'Apply for Leave',

    'field_user'      => 'Applicant',
    'field_date'      => 'Date',
    'field_type'      => 'Type',
    'field_time'      => 'Time',
    'field_reason'    => 'Reason',
    'field_status'    => 'Status',

    /*
     * Who reviewed it and when.
     *
     * ⚠ Shown for both approvals and rejections — someone who was turned down
     * needs the timestamp most, to judge whether to reapply.
     */
    'reviewed_by_at'  => 'Reviewed by :name · :time',
    'reviewed_at'     => 'Reviewed :time',

    'field_leave_type' => 'Leave type',
    'leave_annual'     => 'Annual leave',
    'leave_sick'       => 'Sick leave',
    'leave_personal'   => 'Personal leave',
    'leave_unclassified' => 'Unclassified',

    'quota_title'     => 'Annual leave this year',
    'quota_remaining' => 'Remaining',
    'quota_entitled'  => 'Entitled',
    'quota_used'      => 'Used',
    'quota_carry'     => 'Carried over',
    'quota_period'    => 'Period',
    'quota_none'      => 'Not yet entitled (under 6 months)',
    'unit_hours'      => ':value h',
    'unit_days'       => ':value d',

    'type_full_day'   => 'Full Day',
    'type_hours'      => 'Hours',

    'status_pending'  => 'Pending',
    'status_approved' => 'Approved',
    'status_rejected' => 'Rejected',

    'msg' => [
        'submitted'        => 'Leave request submitted',
        'approved'         => 'Leave approved',
        'rejected'         => 'Leave rejected',
        'overlap'          => 'Leave already exists for this date range',
        'quota_exceeded'   => 'Not enough annual leave: only :hours hours left',
        'user_not_found'   => 'Applicant account not found',
        'already_reviewed' => 'This leave has already been reviewed',
        'on_leave_today'   => 'You are on leave today, cannot clock in',
        'leave_blocked'    => 'Approved leave exists on this date, cannot assign shift',
    ],
];
