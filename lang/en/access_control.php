<?php

declare(strict_types=1);

/**
 * Users screen texts (Pages/Panel/Users/Index, UserTable, UserCards,
 * UserFormModal) — injected as the `t` prop by UsersController::index. Also
 * used by the controller (flash messages) and EntityUserService (owner and
 * own-account protections).
 */
return [
    // Page
    'page_title'  => 'Users',
    'total_label' => 'Total:',
    'new_user'    => 'New user',
    'roles_link'  => 'Roles & Permissions',
    'close'       => 'Close',

    // Search
    'search_placeholder' => 'Search by name or e-mail…',
    'search_clear'       => 'Clear search',

    // Table/cards toggle
    'view_table' => 'Table view',
    'view_cards' => 'Cards view',

    // Columns
    'col_created_at'    => 'Registered',
    'col_name'          => 'Name',
    'col_email'         => 'E-mail',
    'col_role'          => 'Role',
    'col_status'        => 'Status',
    'col_actions'       => 'Actions',
    'sort_by'           => 'Sort by :column',
    'extra_roles_one'   => '+:count additional profile',
    'extra_roles_other' => '+:count additional profiles',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Restore default',

    // Pagination ("Showing 1–12 of 40 users")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'users',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',

    // Status
    'status_active'   => 'Active',
    'status_inactive' => 'Inactive',
    'status_deleted'  => 'Deleted',

    // Badges
    'badge_owner' => 'Owner',
    'badge_self'  => 'You',

    // States
    'empty'        => 'No users yet.',
    'empty_search' => 'No users match this search.',

    // Actions
    'btn_edit'       => 'Edit',
    'btn_restore'    => 'Restore',
    'btn_deactivate' => 'Deactivate',
    'btn_activate'   => 'Activate',
    'btn_delete'     => 'Delete',
    'more_actions'   => 'More actions',
    'owner_locked'   => 'The clinic owner cannot be changed on this screen.',

    // Confirmations
    'confirm_delete'  => 'Remove ":name"\'s access to this clinic? You can undo it later by restoring the user.',
    'confirm_restore' => 'Restore ":name"\'s access to this clinic?',

    // Form
    'form_title_create'      => 'New user',
    'form_title_edit'        => 'Edit user',
    'field_name'             => 'Full name',
    'field_email'            => 'E-mail',
    'field_role'             => 'Access role',
    'field_role_placeholder' => 'Select a role',
    'field_active'           => 'Active user',
    'field_password'         => 'Password',
    'field_password_hint'    => 'Minimum 8 characters, with uppercase, lowercase, numbers and symbols.',
    'field_password_confirm' => 'Confirm password',
    'field_extra_roles'      => 'Additional profiles',
    'extra_roles_empty'      => 'No custom profiles in this clinic yet.',
    'extra_roles_hint'       => 'Additional administrative permissions, on top of the base role above.',
    'credentials_info'       => 'The user will receive these credentials to access the system.',
    'required'               => 'required',
    'btn_cancel'             => 'Cancel',
    'btn_save'               => 'Save changes',
    'btn_create'             => 'Create user',

    // Action feedback (flash)
    'flash_created'     => 'User created successfully.',
    'flash_updated'     => 'User updated successfully.',
    'flash_activated'   => 'User activated successfully.',
    'flash_deactivated' => 'User deactivated successfully.',
    'flash_deleted'     => 'User access removed successfully.',
    'flash_restored'    => 'User restored successfully.',

    // Owner / own account
    'owner_protected' => 'The entity owner cannot be deactivated or removed.',
    'self_protected'  => 'You cannot deactivate or remove your own account.',

    // Browser errors
    'js_error_load' => 'Error loading user data.',
];
