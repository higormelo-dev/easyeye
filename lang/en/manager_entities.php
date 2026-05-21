<?php

declare(strict_types=1);

return [
    // ── Page ──────────────────────────────────────────────────────────────────
    'page_title'         => 'Companies',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Companies',
    'btn_new'            => 'New company',
    'search_placeholder' => 'Search by name, code or city...',
    'showing_from'       => 'Showing',
    'showing_to'         => 'to',
    'showing_of'         => 'of',
    'showing_suffix'     => 'companies',
    'total_label'        => 'Total:',

    // ── View toggle ───────────────────────────────────────────────────────────
    'view_table' => 'Table view',
    'view_cards' => 'Card view',

    // ── Table columns ─────────────────────────────────────────────────────────
    'col_registered_at' => 'Registered',
    'col_code'          => 'Code',
    'col_company'       => 'Company',
    'col_city_state'    => 'City / State',
    'col_users'         => 'Users',
    'col_status'        => 'Status',
    'col_actions'       => 'Actions',

    // ── Status badges ─────────────────────────────────────────────────────────
    'status_active'   => 'Active',
    'status_inactive' => 'Inactive',
    'status_deleted'  => 'Deleted',

    // ── Actions ───────────────────────────────────────────────────────────────
    'action_view'                => 'View details',
    'action_edit'                => 'Edit',
    'action_users'               => 'Users',
    'action_user_integrators'    => 'Integrator Users',
    'action_activate'            => 'Activate',
    'action_deactivate'          => 'Deactivate',
    'action_delete'              => 'Delete',
    'action_restore'             => 'Restore',

    // ── Empty states ──────────────────────────────────────────────────────────
    'empty_list'    => 'No companies found.',
    'loading'       => 'Loading...',

    // ── Confirmations ─────────────────────────────────────────────────────────
    'confirm_delete' => 'Are you sure you want to delete this company?',

    // ── Cards ─────────────────────────────────────────────────────────────────
    'card_code'     => 'Code:',
    'card_location' => 'Location:',

    // ── Form — titles ─────────────────────────────────────────────────────────
    'form_title_create' => 'New Company',
    'form_title_edit'   => 'Edit Company',

    // ── Form — tabs ───────────────────────────────────────────────────────────
    'tab_data'    => 'Data',
    'tab_address' => 'Address',
    'tab_config'  => 'Config.',

    // ── Form — fields (Data) ──────────────────────────────────────────────────
    'field_name'                   => 'Name',
    'field_name_required'          => 'Name *',
    'field_subdomain'              => 'Subdomain',
    'field_subdomain_placeholder'  => 'my-company',
    'field_email'                  => 'E-mail',
    'field_telephone'              => 'Telephone',
    'field_cellphone'              => 'Mobile',
    'field_national_registration'  => 'Tax Number (CNPJ / CPF)',
    'field_state_registration'     => 'State Registration',
    'field_municipal_registration' => 'Municipal Registration',
    'field_website'                => 'Website',
    'field_website_placeholder'    => 'https://',

    // ── Form — fields (Address) ───────────────────────────────────────────────
    'field_zipcode'           => 'Zip Code',
    'field_zipcode_placeholder' => '00000-000',
    'field_address'           => 'Street',
    'field_number'            => 'Number',
    'field_complement'        => 'Complement',
    'field_district'          => 'District',
    'field_city'              => 'City',
    'field_city_required'     => 'City *',
    'field_state'             => 'State',
    'field_state_required'    => 'State *',
    'field_country'           => 'Country',
    'field_country_required'  => 'Country *',
    'btn_lookup_cep'          => 'Look up',

    // ── Form — fields (Config) ────────────────────────────────────────────────
    'field_schedule_interval'  => 'Appointment interval',
    'interval_15'              => '15 minutes',
    'interval_20'              => '20 minutes',
    'interval_30'              => '30 minutes',
    'field_status'             => 'Status',
    'status_option_active'     => 'Active',
    'status_option_inactive'   => 'Inactive',
    'config_info_after_create' => 'After creating the company, open its record to set up users and integrators.',

    // ── Form — buttons ────────────────────────────────────────────────────────
    'btn_cancel'         => 'Cancel',
    'btn_save_changes'   => 'Save changes',
    'btn_create_company' => 'Create company',

    // ── Detail drawer — header ────────────────────────────────────────────────
    'detail_loading'    => 'Loading...',
    'detail_btn_edit'   => 'Edit',
    'detail_deleted_at' => 'Deleted on :date',

    // ── Detail drawer — sections ──────────────────────────────────────────────
    'section_data'    => 'Company Data',
    'section_docs'    => 'Documents',
    'section_address' => 'Address',
    'section_config'  => 'Settings',

    // ── Detail drawer — fields ────────────────────────────────────────────────
    'detail_email'                   => 'E-mail',
    'detail_subdomain'               => 'Subdomain',
    'detail_telephone'               => 'Telephone',
    'detail_cellphone'               => 'Mobile',
    'detail_website'                 => 'Website',
    'detail_national_registration'   => 'Tax Number',
    'detail_state_registration'      => 'State Reg.',
    'detail_municipal_registration'  => 'Municipal Reg.',
    'detail_zipcode'                 => 'Zip Code',
    'detail_address'                 => 'Street',
    'detail_district'                => 'District',
    'detail_city_state'              => 'City / State',
    'detail_country'                 => 'Country',
    'detail_schedule_interval'       => 'Appointment interval',
    'detail_interval_minutes'        => ':value minutes',
    'detail_registered_at'           => 'Registered',

    'section_security'         => 'Security',
    'detail_2fa_required'      => 'Requires 2FA',
    'detail_2fa_yes'           => 'Yes — all users',
    'detail_2fa_no'            => 'No — optional per user',
    'detail_2fa_enabled_at'    => 'Enabled at',
    'detail_2fa_enabled_by'    => 'Enabled by',
    'badge_2fa_required_hint'  => 'This company requires 2FA from all users',

    'form_section_security'    => 'Security',
    'form_2fa_label'           => 'Require 2FA for all users',
    'form_2fa_hint'            => 'When enabled, all users of this company must set up two-factor authentication.',
    'form_2fa_enabled_at'      => 'Enabled at :date by :user',
    'form_2fa_toggle_enable'   => 'Enable required 2FA',
    'form_2fa_toggle_disable'  => 'Disable required 2FA',
    'form_2fa_modal_enable'    => 'Justify why you are ENABLING required 2FA for this company (LGPD/CFM).',
    'form_2fa_modal_disable'   => 'Justify why you are DISABLING required 2FA for this company (LGPD/CFM).',
];
