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
    // Status of the original request once its cancellation is approved
    // (⚠ not "rejected" — the applicant withdrew it themselves)
    'status_cancelled' => 'Cancelled',

    'msg' => [
        'submitted'        => 'Leave request submitted',
        'approved'         => 'Leave approved',
        'rejected'         => 'Leave rejected',
        'overlap'          => 'Leave already exists for this date range',
        'quota_exceeded'   => 'Not enough annual leave: only :hours hours left',
        // ⚠ Distinct from the line above: this one is "none yet", that one is "had some, used it up"
        'quota_not_yet'    => 'You are not yet entitled to annual leave (less than six months of service). Please apply for sick or personal leave instead.',
        'user_not_found'   => 'Applicant account not found',
        'already_reviewed' => 'This leave has already been reviewed',

        // Leave cancellation. ⚠ "Cancel" (pending) and "withdraw" (approved) must read differently.
        'cancel_pending_done'      => 'Leave request cancelled',
        'cancel_requested'         => 'Cancellation request submitted, awaiting review',
        'cancel_approved'          => 'Leave cancellation approved',
        'cancel_rejected'          => 'Leave cancellation rejected',
        'cancel_not_owner'         => 'You can only cancel your own leave',
        'cancel_not_pending'       => 'This leave is not pending — use "Request cancellation" instead',
        'cancel_not_approved'      => 'Only approved leave needs a cancellation request',
        'cancel_already_started'   => 'This leave has already started or passed and cannot be cancelled',
        'cancel_already_requested' => 'A cancellation request for this leave is already awaiting review',
        'cancel_already_reviewed'  => 'This cancellation request has already been reviewed',
        'cancel_status_invalid'    => 'Invalid review result',
        'cancel_reason_max'        => 'Cancellation reason is too long (max 500 characters)',
        'not_found'                => 'Leave request not found',

        'cancel_action'          => 'Request cancellation',
        'cancel_pending_action'  => 'Cancel request',
        'cancel_title'           => 'Request leave cancellation',
        'cancel_reason_label'    => 'Reason (optional)',
        'cancel_review_title'    => 'Review leave cancellation',
        'cancel_status_badge'    => 'Cancellation pending',
        'cancel_cancelled_badge' => 'Cancelled',

        // ⚠ TEMP-CANCEL-KEEP-QUOTA: wording follows the requester, not the implementation.
        'cancel_restore_quota'      => 'Give back the deducted annual-leave hours',
        'cancel_restore_quota_hint' => 'Checked by default. Leave it unchecked to cancel the leave while still counting those hours as used.',
        'on_leave_today'   => 'You are on leave today, cannot clock in',
        'leave_blocked'    => 'Approved leave exists on this date, cannot assign shift',
    ],
];
