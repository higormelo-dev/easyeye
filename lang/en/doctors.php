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
];
