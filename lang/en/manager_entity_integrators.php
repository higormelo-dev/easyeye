<?php

declare(strict_types=1);

return [
    'page_title'          => 'Integrators',
    'breadcrumb_home'     => 'Dashboard',
    'breadcrumb_entities' => 'Companies',
    'breadcrumb_users'    => 'Integrator Users',
    'breadcrumb_current'  => 'Integrators',

    'btn_back'            => 'Back to integrator users',
    'btn_new'             => 'New integrator',
    'search_placeholder'  => 'Search by name, IP, MAC or code...',
    'total_label'         => 'Total:',

    // Columns
    'col_registered_at' => 'Registered',
    'col_code'          => 'Code',
    'col_name'          => 'Name',
    'col_ip'            => 'IP',
    'col_mac'           => 'MAC',
    'col_status'        => 'Status',
    'col_actions'       => 'Actions',

    // Status
    'status_active'   => 'Active',
    'status_inactive' => 'Inactive',
    'status_deleted'  => 'Deleted',

    // Actions
    'action_view'        => 'View details',
    'action_edit'        => 'Edit',
    'action_equipments'  => 'Equipments',
    'action_activate'    => 'Activate',
    'action_deactivate'  => 'Deactivate',
    'action_delete'      => 'Delete',
    'action_restore'     => 'Restore',

    // Empty
    'empty_list' => 'No integrators registered for this user.',
    'loading'    => 'Loading...',

    // Form modal
    'form_title_create'   => 'New Integrator',
    'form_title_edit'     => 'Edit Integrator',
    'field_name'          => 'Name',
    'field_ip'            => 'IP Address',
    'field_ip_hint'       => 'IPv4 or IPv6 of the equipment/host.',
    'field_mac'           => 'MAC Address',
    'field_mac_hint'      => 'Format: 00:1B:44:11:3A:B7',
    'field_active'        => 'Active',
    'field_yes'           => 'Yes',
    'field_no'            => 'No',
    'btn_cancel'          => 'Cancel',
    'btn_save'            => 'Save',
    'btn_create'          => 'Create',

    // Detail drawer
    'detail_loading'             => 'Loading...',
    'detail_section_identity'    => 'Identity',
    'detail_section_network'     => 'Network',
    'detail_section_security'    => 'Security',
    'detail_section_equipments'  => 'Linked equipments',
    'detail_open_equipments'     => 'Open equipments',
    'detail_active_tokens'       => 'Active tokens (Sanctum)',
    'detail_active_tokens_hint'  => 'Tokens issued via POST /api/integrators (equipment login). Expire in 7 days.',
    'detail_registered_at'       => 'Registered at',
    'detail_btn_edit'            => 'Edit',
    'detail_btn_close'           => 'Close',

    // Confirmations / toasts
    'confirm_delete'  => 'Delete this integrator?',
    'confirm_restore' => 'Restore this integrator?',
    'created'         => 'Integrator created.',
    'updated'         => 'Integrator updated.',
    'deleted'         => 'Integrator deleted.',
    'restored'        => 'Integrator restored.',
    'activated'       => 'Integrator activated.',
    'deactivated'     => 'Integrator deactivated.',
];
