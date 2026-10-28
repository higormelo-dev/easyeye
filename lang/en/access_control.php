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

    // Invitation for a user who already has an EasyEye login (another clinic).
    // The response never tells whether the e-mail has an account.
    'invitation' => [
        'button'         => 'Invite existing user',
        'title'          => 'Invite someone who already uses EasyEye',
        'intro'          => 'Enter the e-mail the person uses to sign in to EasyEye and the profile they will have at this clinic. If the e-mail already has access, they will receive an invitation to accept.',
        'email'          => 'E-mail',
        'rule'           => 'Profile at this clinic',
        'rule_hint'      => 'Doctors are invited through the doctors registration (with CRM).',
        'submit'         => 'Send invitation',
        'close'          => 'Close',
        'sent'           => 'If this e-mail already has EasyEye access, it will receive an invitation. If it is not accepted, register the person as a new user.',
        'plan_limit'     => 'The plan\'s user limit has been reached.',
        'cancelled'      => 'Invitation cancelled.',
        'pending_title'  => 'Pending invitations',
        'pending_hint'   => 'Waiting for acceptance. Invitations to e-mails without EasyEye access reach no one and expire on their own.',
        'col_email'      => 'E-mail',
        'col_rule'       => 'Profile',
        'col_sent_at'    => 'Sent on',
        'col_expires_at' => 'Expires on',
        'cancel'         => 'Cancel invitation',
        'confirm_cancel' => 'Cancel this invitation?',

        'page' => [
            'title'   => 'Invitation to access :clinic',
            'intro'   => ':clinic invited you to access EasyEye with them, using your usual login.',
            'note'    => 'Your login, password and access to other clinics do not change.',
            'role'    => 'Profile offered at this clinic: :role',
            'accept'  => 'Accept invitation',
            'decline' => 'Decline',
            'closed'  => 'This invitation is no longer available (expired, declined or cancelled).',
            'back'    => 'Go to my clinics',
        ],

        'result' => [
            'accepted'       => 'Done! You now also have access to :clinic. Select the clinic to continue.',
            'already_member' => 'You already have access to :clinic.',
            'plan_limit'     => ':clinic has reached the plan\'s user limit. Ask the clinic to contact support.',
            'closed'         => 'This invitation is no longer available (expired, declined or cancelled).',
            'declined'       => 'Invitation declined.',
        ],

        'mail' => [
            'subject'    => '[:clinic] Invitation to access EasyEye',
            'greeting'   => 'Hello, :name!',
            'intro'      => ':clinic invited you to access EasyEye with them.',
            'login_note' => 'You sign in with your usual login — nothing changes at other clinics.',
            'role'       => 'Profile offered: :role.',
            'action'     => 'View invitation',
            'expires'    => 'The invitation is valid for :days days. If you do not recognize this clinic, ignore this e-mail.',
        ],
    ],
];
