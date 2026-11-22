<?php

return [
    'title'          => 'Profile',
    'field_account'  => 'Account',
    'field_nickname' => 'Nickname',
    'field_password' => 'Password',
    'password_hint'  => 'Leave blank to keep unchanged',
    'field_telegram_username' => 'Telegram username',
    'telegram_username_hint'  => 'Used to @ you in the internal support group when an auto-reply ticket times out. Leave blank to never be tagged.',
    'telegram_username_ph'    => 'e.g. amy_chen (without @)',
    'telegram_bind_label'      => 'Telegram link',
    'telegram_bind_ready'      => 'Linked',
    'telegram_bind_ready_hint' => 'You receive roster, task card and summary messages on Telegram. Ask an administrator if you need to re-link.',
    'telegram_bind_action'     => 'Generate code',
    'telegram_bind_hint'       => 'Generates a code — paste it to the support bot on Telegram to finish linking. Roster and task card messages only arrive once linked.',

    /*
     * Idle auto-logout (2026-11-22).
     *
     * ⚠ The hint must make clear this counts **idle** time — not "log out
     * N minutes after signing in".
     */
    'field_session_timeout'   => 'Log me out after idling',
    'session_timeout_hint'    => 'Counts only while you do nothing at all (no clicks, no typing). Choose "Never" to keep things as they are. This is a convenience setting, not a security control.',
    'session_timeout_never'   => 'Never',
    'session_timeout_minutes' => ':value minutes',
    'session_timeout_hours'   => ':value hours',

    'msg' => [
        'update_success'      => 'Profile updated',
        'idle_logout'         => 'Signed out after a long period of inactivity',
        'update_failed'       => 'Failed to update profile',
        'full_width_password' => 'Password contains full-width or unsupported characters (including spaces). Use half-width letters, numbers and symbols.',
    ],
];
