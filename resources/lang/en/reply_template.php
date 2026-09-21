<?php

return [
    'nav_label'  => 'Reply Templates',
    'page_title' => 'Reply Templates',
    'subtitle'   => 'What auto reply actually sends to customers',

    'intro'       => 'This is the only thing customers see. Answers come verbatim from the knowledge base; what you set here is the tone wrapped around them.',
    'signature_hint' => 'The signature :signature is appended automatically — do not write it in the template.',

    'full'       => 'Full',
    'short'      => 'Short',
    'full_hint'  => 'Used when the last auto reply was more than :minutes minutes ago. Includes a greeting.',
    'short_hint' => 'Used during an ongoing conversation, dropping the greeting so it does not read as canned.',

    'answer'  => 'Matched the knowledge base',
    'clarify' => 'Unsure — ask the customer',
    'wait'    => 'Not in the knowledge base (please wait)',
    'support' => 'Forwarding the support group answer',

    'answer_desc'  => 'Sent when an answer is found in the knowledge base.',
    'clarify_desc' => 'Politely asks the customer which one they meant when several entries look alike.',
    'wait_desc'    => 'Sent when the knowledge base has no answer; the question is forwarded to the support group at the same time.',
    'support_desc' => 'Sent when a colleague answers in the support group and presses "Reply to customer".',

    'var_required' => 'Must contain :value',

    'action_save'   => 'Save',
    'action_saving' => 'Saving...',

    'msg' => [
        'saved'         => 'Templates updated',
        'save_failed'   => 'Failed to update templates',
        'load_failed'   => 'Failed to load templates',
        'required'      => 'Templates cannot be empty, or no message can be sent',
        'max'           => 'Template must not exceed :value characters',
        'placeholder_missing' => 'The template must keep the :value placeholder, otherwise the customer receives an empty message',
    ],
];
