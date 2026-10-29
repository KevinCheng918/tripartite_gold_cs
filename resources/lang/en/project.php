<?php

return [
    'project_add'   => 'Add project',
    'project_edit'  => 'Edit project',
    'saved'         => 'Saved',
    'deleted'       => 'Deleted',
    'action_failed' => 'Something went wrong',
    'loading'       => 'Loading…',

    'regulation_section'   => 'Regulations',
    'regulation_empty'     => 'This project has no regulations yet',
    'regulation_add'       => 'Add regulation',
    'regulation_edit'      => 'Edit',
    'regulation_delete'    => 'Delete',
    'regulation_view'      => 'View',
    'regulation_title'     => 'Title',
    'regulation_content'   => 'Content',
    'regulation_status'    => 'Status',
    'regulation_sort'      => 'Order',
    'regulation_updated_by' => 'Last edited',
    'regulation_active'    => 'Active',
    'regulation_disabled'  => 'Disabled',
    'regulation_title_placeholder'   => 'e.g. Tripartite Regulations',
    'regulation_content_placeholder' => 'Plain text. The bot posts this verbatim to the group, splitting it automatically when it is too long.',
    'regulation_status_hint' => 'Once disabled, the bot will no longer return this regulation.',
    'regulation_delete_confirm' => 'Delete this regulation? This cannot be undone.',

    'regulation_created'       => 'Regulation created',
    'regulation_updated'       => 'Regulation updated',
    'regulation_deleted'       => 'Regulation deleted',
    'regulation_save_failed'   => 'Failed to save the regulation',
    'regulation_delete_failed' => 'Failed to delete the regulation',

    'regulation_no_access' => 'You are not a member of this project, so its regulations are not visible to you',

    'regulation_project_required'  => 'Please choose which project this regulation belongs to',
    'regulation_title_required'    => 'Please enter a title',
    'regulation_title_too_long'    => 'Title is too long (max :max characters)',
    'regulation_content_required'  => 'Please enter the content',
    'regulation_content_too_long'  => 'Content is too long (max :max characters)',

    // Project owner (exactly one per project)
    'owner_label'  => 'Owner',
    'owner_change' => 'Change',
    'owner_change_title' => 'Change project owner',
    'owner_unassigned' => 'Unassigned',
    'owner_select_none' => 'Unassigned (no owner)',
    'owner_select_hint' => 'A project has exactly one owner — assigning someone new replaces the current one. Administrators are not listed.',
    'owner_create_hint' => 'A brand-new project has no members yet, so any colleague can be picked here (administrators excluded). Remember to add them to the project in Account Management after saving, otherwise they will not see this project after logging in.',
    'owner_candidates_empty' => 'Nobody is a member of this project yet. Please assign members in Account Management first.',

    'owner_issue_resigned' => 'has left — please assign a new owner',
    'owner_issue_disabled' => 'is disabled — please assign a new owner',
    'owner_issue_admin' => 'is an administrator and cannot be a project owner — please assign someone else',
    'owner_issue_not_participant' => 'is not a member of this project and cannot see it — please add them in Account Management',

    'owner_assigned'      => 'Project owner updated',
    'owner_assign_failed' => 'Failed to update the project owner',
    'owner_not_found'     => 'That account no longer exists — please pick another owner',
    'owner_not_participant' => 'This person is not a member of this project yet. Please add them to the project in Account Management first, then assign them as owner.',
    'owner_not_allowed_admin' => 'Administrators cannot be project owners. Please assign a colleague who is a member of this project.',
];
