<?php

declare(strict_types=1);

return [
    // ── Page ──────────────────────────────────────────────────────────────────
    'page_title'         => 'Plans',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Plans',
    'btn_new'            => 'New plan',
    'search_placeholder' => 'Search by name...',
    'total_label'        => 'Total:',
    'loading'            => 'Loading...',

    // ── View toggle ───────────────────────────────────────────────────────────
    'view_table' => 'Table view',
    'view_cards' => 'Card view',

    // ── Confirmations ─────────────────────────────────────────────────────────
    'confirm_delete' => 'Are you sure you want to remove this plan?',

    // ── Table columns ─────────────────────────────────────────────────────────
    'col_order'   => 'Order',
    'col_name'    => 'Name',
    'col_price'   => 'Price',
    'col_cycle'   => 'Cycle',
    'col_status'  => 'Status',
    'col_actions' => 'Actions',

    // ── Status badges ─────────────────────────────────────────────────────────
    'status_active'   => 'Active',
    'status_inactive' => 'Inactive',

    // ── Actions ───────────────────────────────────────────────────────────────
    'action_view'       => 'View',
    'action_edit'       => 'Edit',
    'action_activate'   => 'Activate',
    'action_deactivate' => 'Deactivate',
    'action_delete'     => 'Remove',

    // ── Pagination ────────────────────────────────────────────────────────────
    'showing_from'   => 'Showing',
    'showing_to'     => 'to',
    'showing_of'     => 'of',
    'showing_suffix' => 'plans',

    // ── Empty states ──────────────────────────────────────────────────────────
    'empty_list'     => 'No plans found.',
    'empty_features' => 'No features configured for this plan.',

    // ── Form — titles ─────────────────────────────────────────────────────────
    'form_title_create' => 'New Plan',
    'form_title_edit'   => 'Edit Plan',

    // ── Form — tabs ───────────────────────────────────────────────────────────
    'tab_data'     => 'Data',
    'tab_features' => 'Features',

    // ── Form — fields (Data) ──────────────────────────────────────────────────
    'field_name'                    => 'Name',
    'field_name_required'           => 'Name *',
    'field_sort_order'              => 'Order',
    'field_description'             => 'Description',
    'field_description_placeholder' => 'Brief description of the plan...',
    'field_price'                   => 'Price',
    'currency_prefix'               => '$',
    'field_billing_cycle'           => 'Billing cycle',
    'field_billing_cycle_required'  => 'Billing cycle *',
    'field_status'                  => 'Status',
    'status_option_active'          => 'Active',
    'status_option_inactive'        => 'Inactive',

    // ── Form — features ───────────────────────────────────────────────────────
    'features_info'                 => 'For numeric limits, 0 = unlimited. Boolean features indicate whether the resource is available in the plan.',
    'features_numeric_section'      => 'Quantitative Limits',
    'features_numeric_placeholder'  => '0 = unlimited',
    'features_numeric_hint'         => '0 = unlimited',
    'features_boolean_section'      => 'Available Features',
    'features_boolean_not_included' => 'Not included',
    'features_boolean_included'     => 'Included',

    // ── Form — buttons ────────────────────────────────────────────────────────
    'btn_cancel'       => 'Cancel',
    'btn_save_changes' => 'Save changes',
    'btn_create_plan'  => 'Create plan',

    // ── Detail drawer ─────────────────────────────────────────────────────────
    'detail_btn_edit'    => 'Edit',
    'section_pricing'    => 'Pricing',
    'section_features'   => 'Limits & Features',
    'detail_price'       => 'Price',
    'detail_cycle'       => 'Cycle',
    'detail_sort_order'  => 'Display order',
    'detail_description' => 'Description',
    'detail_created_at'  => 'Registered on',

    // ── Feature values (drawer) ───────────────────────────────────────────────
    'feature_included'     => 'Included',
    'feature_not_included' => 'Not included',
    'feature_unlimited'    => 'Unlimited',
];
