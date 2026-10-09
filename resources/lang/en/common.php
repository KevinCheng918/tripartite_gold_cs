<?php

/**
 * Shared wording used across the whole app.
 *
 * Only put words that any page might need and that mean exactly the same
 * thing everywhere. Feature-specific copy belongs in that feature's own file.
 */

return [
    /*
     * Date range shortcuts, paired with window.DateRange in
     * public/js/common.js. "This week" and "this month" are full ranges
     * (Monday to Sunday, 1st to end of month).
     */
    'date_range' => [
        'today'      => 'Today',
        'yesterday'  => 'Yesterday',
        'this_week'  => 'This week',
        'last_week'  => 'Last week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
    ],

    /*
     * Segment names for a table's action column. Actions are glued into
     * btn-groups by category (styles live in the .row-actions block of
     * public/css/custom.css); these words are each segment's `aria-label`.
     *
     * ⚠ The visual grouping does not exist for a screen reader — this name
     * is the only cue it has, so every segment needs one. Never skip it just
     * because the label is not painted on screen.
     *
     * These category names are generic; each page picks the ones it needs.
     * Action labels tied to one feature stay in that feature's own file.
     */
    'row_actions' => [
        'view'   => 'View',
        'manage' => 'Manage',
        'record' => 'History',
        'notify' => 'Notify',
        'danger' => 'Danger zone',
    ],
];
