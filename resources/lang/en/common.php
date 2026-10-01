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
];
