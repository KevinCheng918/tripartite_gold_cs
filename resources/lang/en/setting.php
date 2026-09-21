<?php

return [
    'nav_label'   => 'Global Settings',
    'page_title'  => 'Global Settings',
    'subtitle'    => 'Claude credentials, internal support group and customer-facing templates',

    // Claude
    'claude_title'        => 'Claude (Auto Reply)',
    'claude_token'        => 'Subscription Token',
    'claude_token_hint'   => 'Run claude setup-token on your own machine. The token is verified before it is saved. Leave blank to keep the current one.',
    'claude_model'        => 'Model',
    'claude_verified_at'  => 'Last verified',
    'claude_not_set'      => 'Not configured',

    // How to get the subscription token
    'claude_howto_title'   => 'How do I get a subscription token?',
    'claude_howto_install' => '1. Install Claude Code on your own machine (pick one):',
    'claude_howto_login'   => '2. Run claude and follow the browser prompts to log in. Requires a Pro, Max, Team or Enterprise subscription — the free claude.ai plan does not include Claude Code.',
    'claude_howto_token'   => '3. Once logged in, run this command to generate a long-lived token:',
    'claude_howto_paste'   => '4. Complete the authorization as prompted, then copy the token from your terminal into the field above and save. It is verified before being stored — if verification fails, your existing setting is left untouched.',
    'claude_howto_note'    => 'The token is tied to the subscription of whoever ran the command — if that person\'s subscription lapses, the token stops working.',

    // Fallback
    'fallback_title'       => 'Claude (Fallback API)',
    'fallback_desc'        => 'Takes over when the subscription quota runs out. This does incur API charges, so set a daily cap.',
    'fallback_enabled'     => 'Enable fallback',
    'fallback_api_key'     => 'API Key',
    'fallback_api_key_hint' => 'From the Claude Console. Leave blank to keep the current one.',
    'fallback_model'       => 'Fallback model',
    'fallback_daily_limit' => 'Daily call limit',
    'fallback_daily_limit_hint' => '0 means unlimited. Once the limit is reached, everything falls back to manual handling.',
    'fallback_used_today'  => 'Used today',

    // How to get an API key
    'fallback_howto_title'  => 'How do I get an API key?',
    'fallback_howto_open'   => '1. Go to the Claude Console and sign in:',
    'fallback_howto_create' => '2. Open Settings → API Keys in the left menu and click "Create Key".',
    'fallback_howto_paste'  => '3. Copy the key into the field above and save. The key is shown in full only once — close the dialog and you cannot see it again.',
    'fallback_howto_note'   => 'API billing is separate from your subscription — the account needs credit before the fallback can work.',

    // Support group
    'support_title'         => 'Internal Support Group',
    'support_desc'          => 'When the knowledge base has no answer, the question is forwarded here for the team to answer.',
    'support_chat_id'       => 'Group chat_id',
    'support_chat_id_hint'  => 'Group chat IDs are negative. The bot must be in the group and Group Privacy must be disabled.',
    'support_system'        => 'Bot to use',
    'support_system_default' => 'Default bot (.env)',
    'support_remind_first'  => 'First reminder (minutes)',
    'support_remind_first_hint' => 'Tags the staff currently on shift if nobody has answered by then.',
    'support_remind_second' => 'Second reminder (minutes)',
    'support_remind_second_hint' => 'Tags managers and owners if still unanswered. Engineers are skipped at both stages.',
    'support_test'          => 'Send test message',

    // Usage
    'usage_title'        => 'Usage',
    'usage_desc'         => 'Every call to Claude is counted — successes, failures and rate limits alike.',
    'usage_today'        => 'Today',
    'usage_month'        => 'This month',
    'usage_subscription' => 'Subscription',
    'usage_fallback'     => 'Fallback API',
    'usage_calls'        => 'Calls',
    'usage_errors'       => 'Failures',
    'usage_rate_limited' => 'Rate limited',
    'usage_avg_duration' => 'Avg duration',
    'usage_cost'         => 'Estimated cost',
    'usage_cost_hint'    => 'Estimated from the configured rates. The Anthropic Console is the source of truth.',

    'action_save'   => 'Save',
    'action_saving' => 'Saving...',
    'action_testing' => 'Testing...',

    'msg' => [
        'saved'         => 'Saved',
        'save_failed'   => 'Save failed',
        'load_failed'   => 'Failed to load settings',
        'token_max'     => 'Credential is too long',
        'token_invalid' => 'This token failed verification; the existing settings were left unchanged',
        'api_key_invalid' => 'This API key failed verification; the existing settings were left unchanged',
        'model_required' => 'Please choose a model',
        'model_invalid' => 'Unsupported model',
        'daily_limit_required' => 'Please enter a daily call limit',
        'daily_limit_invalid'  => 'Daily call limit must be an integer of 0 or more',
        'chat_id_invalid' => 'Invalid chat_id format (group IDs are negative)',
        'chat_id_is_customer' => 'This group is already a customer conversation and cannot be used as the internal support group (all of its customer messages would be intercepted, never delivered and never auto-replied). Please use a separate group, or remove that conversation in Telegram CS first.',
        'system_not_found' => 'System not found',
        'remind_required' => 'Please enter the reminder interval',
        'remind_invalid'  => 'Reminder interval must be between 1 and 1440 minutes',
        'support_test_sent'   => 'Test message sent, please check the group',
        'support_test_failed' => 'Failed to send. Check the chat_id, that the bot is in the group, and that Group Privacy is disabled',
    ],
];
