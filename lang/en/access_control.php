<?php

declare(strict_types=1);

return [
    // Page
    'page_title'         => 'Access Control',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Users',

    // Header
    'total_label' => 'Total:',
    'new_user'    => 'New user',

    // Search
    'search_placeholder' => 'Search by name or e-mail…',

    // View toggle
    'view_table' => 'Table view',
    'view_cards' => 'Cards view',

    // Table columns
    'col_created_at' => 'Registered',
    'col_name'       => 'Name',
    'col_email'      => 'E-mail',
    'col_role'       => 'Role',
    'col_status'     => 'Status',
    'col_actions'    => 'Actions',

    // Pagination
    'showing' => 'Showing :from–:to of :total users',

    // Status badges
    'status_active'   => 'Active',
    'status_inactive' => 'Inactive',
    'status_deleted'  => 'Deleted',

    // Empty state
    'empty' => 'No users found.',

    // Row actions
    'btn_edit'       => 'Edit',
    'btn_restore'    => 'Restore',
    'btn_deactivate' => 'Deactivate',
    'btn_activate'   => 'Activate',
    'btn_delete'     => 'Delete',

    // Confirm messages
    'confirm_delete'  => 'Are you sure you want to remove this user? This action can be reverted via restore.',
    'confirm_restore' => 'Restore this user?',

    // Form modal
    'form_title_create' => 'New User',
    'form_title_edit'   => 'Edit User',

    'field_name'             => 'Full name',
    'field_email'            => 'E-mail',
    'field_role'             => 'Access role',
    'field_role_placeholder' => 'Select a role',
    'field_active'           => 'Active user',
    'field_password'         => 'Password',
    'field_password_hint'    => 'Minimum 8 characters, with uppercase, lowercase, numbers and symbols.',
    'field_password_confirm' => 'Confirm password',

    'credentials_info' => 'The user will receive these credentials to access the system.',

    'btn_cancel' => 'Cancel',
    'btn_save'   => 'Save changes',
    'btn_create' => 'Create user',

    // Owner / self-protection
    'badge_owner'     => 'Owner',
    'owner_protected' => 'The entity owner cannot be deactivated or removed.',
    'self_protected'  => 'You cannot deactivate or remove your own account.',

    // JS errors
    'js_error_load' => 'Error loading user data.',
];
