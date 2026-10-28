<?php

declare(strict_types=1);

/**
 * Access profiles screen texts (Pages/Panel/AccessControl/Roles/Index,
 * RoleTable, RoleCards, RoleFormModal) — injected as the `t` prop by
 * App\Http\Controllers\AccessControl\RolesController::index. Also used by
 * the controller (flash messages) and RoleRequest (validation).
 */
return [
    'page_title'         => 'Access profiles',
    'total_label'        => 'Total:',
    'view_table'         => 'Table',
    'view_cards'         => 'Cards',
    'btn_new'            => 'New profile',
    'search_placeholder' => 'Search profiles by name or description...',
    'search_clear'       => 'Clear search',
    'close'              => 'Close',

    // Notice + fixed platform profiles
    'notice'                => 'System profiles are defined by the platform and chosen when registering each user. The custom profiles below grant additional administrative permissions. Clinical actions (reports, prescriptions) remain exclusive to doctors, regardless of profile.',
    'system_profiles_title' => 'System profiles',
    'system_profiles_count' => ':count predefined by the platform',
    'system_profile_badge'  => 'Default',

    // Columns
    'col_name'        => 'Profile',
    'col_permissions' => 'Permissions',
    'col_users'       => 'Users',
    'col_created_at'  => 'Created',
    'col_actions'     => 'Actions',
    'sort_by'         => 'Sort by :column',
    'no_description'  => 'No description',

    // Counts ("1 permission", "3 users")
    'permissions_one'   => ':count permission',
    'permissions_other' => ':count permissions',
    'users_one'         => ':count user',
    'users_other'       => ':count users',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Restore default',

    // Actions
    'action_edit'               => 'Edit',
    'action_delete'             => 'Delete',
    'confirm_delete'            => 'Delete the profile ":name"?',
    'confirm_delete_with_users' => 'Delete the profile ":name"? :count user(s) will lose these additional permissions.',

    // States
    'empty_list'   => 'No custom profiles yet. The system profiles already cover the clinic\'s default roles.',
    'empty_search' => 'No profiles match this search.',

    // Pagination ("Showing 1–12 of 40 profiles")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'profiles',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',

    // Form (side panel)
    'form_title_create'      => 'New profile',
    'form_title_edit'        => 'Edit profile',
    'field_name'             => 'Name',
    'field_description'      => 'Description',
    'field_description_hint' => 'Optional — explain when this profile should be used',
    'field_permissions'      => 'Permissions',
    'no_permissions'         => 'No permissions available to assign.',
    'select_all'             => 'Select all',
    'unselect_all'           => 'Unselect all',
    'btn_cancel'             => 'Cancel',
    'btn_create'             => 'Create profile',
    'btn_save'               => 'Save changes',
    'required'               => 'required',

    // Action feedback (flash)
    'flash_created' => 'Access profile created successfully.',
    'flash_updated' => 'Access profile updated successfully.',
    'flash_deleted' => 'Access profile deleted successfully.',

    // Validation (RoleRequest)
    'validation_name_unique'        => 'A profile with this name already exists in this clinic.',
    'validation_permissions_exists' => 'One or more selected permissions are invalid.',

    // Permission and group labels (key = App\Enums\Permission value).
    // A key without a translation falls back to the enum's label()/group().
    'permission_labels' => [
        'settings.manage'  => 'Manage settings',
        'users.manage'     => 'Manage users',
        'roles.manage'     => 'Manage access profiles',
        'financial.view'   => 'View financials',
        'exams.import'     => 'Import exams',
        'patients.manage'  => 'Manage patients and doctors',
        'financial.manage' => 'Manage financials and billing',
        'stock.manage'     => 'Manage stock',
    ],
    'permission_groups' => [
        'settings.manage'  => 'Settings',
        'users.manage'     => 'Users',
        'roles.manage'     => 'Users',
        'financial.view'   => 'Financial',
        'exams.import'     => 'Patients',
        'patients.manage'  => 'Patients',
        'financial.manage' => 'Financial',
        'stock.manage'     => 'Stock',
    ],
];
