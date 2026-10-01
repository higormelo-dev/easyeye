<?php

declare(strict_types=1);

/**
 * Doctors listing texts (Pages/Panel/Doctors/Index, DoctorTable,
 * DoctorCards) — injected as the `t` prop by DoctorsController::index.
 */
return [
    'page_title'           => 'Doctors',
    'breadcrumb_dashboard' => 'Dashboard',
    'total_label'          => 'Total:',
    'view_table'           => 'Table',
    'view_cards'           => 'Cards',
    'btn_import'           => 'Import',
    'btn_new'              => 'New doctor',
    'search_clear'         => 'Clear search',
    'search_placeholder'   => 'Search by name, email, code or medical license...',

    // Columns
    'col_name'       => 'Name',
    'col_phone'      => 'Phone',
    'col_record'     => 'Medical license',
    'col_email'      => 'Email',
    'col_created_at' => 'Registered',
    'col_code'       => 'Code',
    'col_status'     => 'Status',
    'col_actions'    => 'Actions',
    'sort_by'        => 'Sort by :column',
    'whatsapp'       => 'WhatsApp',
    'specialty'      => 'Specialty',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Reset to default',

    // Status / actions
    'status_active'        => 'Active',
    'status_inactive'      => 'Inactive',
    'action_view'          => 'View',
    'action_work_schedule' => 'Working hours',
    'action_edit'          => 'Edit',
    'action_activate'      => 'Activate',
    'action_deactivate'    => 'Deactivate',
    'action_delete'        => 'Delete',
    'confirm_delete'       => 'Are you sure you want to delete this doctor?',

    // States
    'empty_list'   => 'No doctors found.',
    'loading'      => 'Loading...',
    'retry'        => 'Try again',
    'more_actions' => 'More actions',
    'load_error'   => 'Could not load the doctors. Please try again.',

    // Pagination ("Showing 1–15 of 40 doctors")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'doctors',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',

    // Invitation for a doctor who already has an EasyEye login (another clinic).
    'invitation' => [
        'exists_elsewhere'  => 'This doctor already has an EasyEye account, but not at this clinic yet. Send an invitation: it goes to the e-mail of their login and, once accepted, they can work here.',
        'send_button'       => 'Send invitation',
        'sending'           => 'Sending…',
        'sent'              => 'Invitation sent. The doctor will receive the link at the e-mail registered in EasyEye.',
        'conflict'          => 'This CPF and e-mail cannot be used together. Please check the data.',
        'not_invitable'     => 'No doctor with this CPF or e-mail was found in EasyEye. Use the regular registration.',
        'import_use_invite' => 'Doctor already has an EasyEye account (another clinic). To add them, use New doctor and then Send invitation — the spreadsheet does not create a second login.',
        'plan_limit'        => 'The plan\'s doctor limit has been reached.',
        'cancelled'         => 'Invitation cancelled.',
        'pending_title'     => 'Pending invitations',
        'pending_hint'      => 'Waiting for the doctor to accept from their e-mail.',
        'col_sent_at'       => 'Sent on',
        'col_expires_at'    => 'Expires on',
        'cancel'            => 'Cancel invitation',
        'confirm_cancel'    => 'Cancel this invitation? The link sent to the doctor will stop working.',

        'page' => [
            'title'   => 'Invitation to work at :clinic',
            'intro'   => ':clinic invited you to work with them on EasyEye, using your usual login.',
            'note'    => 'Your login, password and data at other clinics do not change. The clinic will use the information it registered itself.',
            'accept'  => 'Accept invitation',
            'decline' => 'Decline',
            'closed'  => 'This invitation is no longer available (expired, declined or cancelled).',
            'back'    => 'Go to my clinics',
        ],

        'result' => [
            'accepted'       => 'Done! You now also work at :clinic. Select the clinic to continue.',
            'already_member' => 'You are already part of :clinic.',
            'plan_limit'     => ':clinic has reached the plan\'s doctor limit. Ask the clinic to contact support.',
            'closed'         => 'This invitation is no longer available (expired, declined or cancelled).',
            'declined'       => 'Invitation declined.',
            'conflict'       => 'Some invitation data (CPF, CRM, specialty or color) is already in use at :clinic. Ask the clinic to send a new invitation.',
        ],

        'mail' => [
            'subject'    => '[:clinic] Invitation to work on EasyEye',
            'greeting'   => 'Hello, :name!',
            'intro'      => ':clinic invited you to work with them on EasyEye.',
            'login_note' => 'You sign in with your usual login — nothing changes at other clinics.',
            'action'     => 'View invitation',
            'expires'    => 'The invitation is valid for :days days. If you do not recognize this clinic, ignore this e-mail.',
        ],
    ],
];
