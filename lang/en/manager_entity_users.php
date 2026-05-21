<?php

declare(strict_types=1);

return [
    'page_title'         => 'Company Users',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_entities'=> 'Companies',
    'breadcrumb_current' => 'Users',

    'btn_back'           => 'Back to companies',
    'search_placeholder' => 'Search by name or e-mail...',
    'total_label'        => 'Total:',

    // Columns
    'col_registered_at' => 'Registered',
    'col_code'          => 'Code',
    'col_name'          => 'Name',
    'col_email'         => 'E-mail',
    'col_rule'          => 'Role',
    'col_status'        => 'Status',
    'col_actions'       => 'Actions',

    // Status
    'status_active'   => 'Active',
    'status_inactive' => 'Inactive',
    'status_deleted'  => 'Removed',

    // Actions
    'action_impersonate'           => 'Login as this user',
    'action_impersonate_disabled'  => 'Cannot impersonate this user',
    'confirm_impersonate_title'    => 'Login as :name?',
    'confirm_impersonate_text'     => 'You will temporarily assume this clinic context. Only use for authorized support.',
    'confirm_impersonate_yes'      => 'Yes, continue',
    'confirm_impersonate_no'       => 'Cancel',

    // Empty
    'empty_list' => 'No users found in this company.',
    'loading'    => 'Loading...',
];
