<?php

declare(strict_types=1);

/**
 * Supplier listing texts (Pages/Panel/Stock/Suppliers/Index, SupplierTable,
 * SupplierCards) — injected as the `t` prop by
 * App\Http\Controllers\Stock\SuppliersController::index.
 */
return [
    'page_title'          => 'Suppliers',
    'total_label'         => 'Total:',
    'view_table'          => 'Table view',
    'view_cards'          => 'Card view',
    'btn_purchase_orders' => 'Purchase orders',
    'btn_new'             => 'New supplier',
    'search_placeholder'  => 'Search by name, code or document...',
    'search_clear'        => 'Clear search',

    // Status filter
    'filter_status_label'    => 'Filter by status',
    'filter_status_all'      => 'All',
    'filter_status_active'   => 'Active',
    'filter_status_inactive' => 'Inactive',

    // Columns
    'col_name'     => 'Name',
    'col_phone'    => 'Phone',
    'col_document' => 'Document',
    'col_contact'  => 'Contact',
    'col_email'    => 'Email',
    'col_code'     => 'Code',
    'col_status'   => 'Status',
    'col_actions'  => 'Actions',
    'sort_by'      => 'Sort by :column',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Restore default',

    // Status / actions
    'status_active'          => 'Active',
    'status_inactive'        => 'Inactive',
    'action_purchase_orders' => 'Purchase orders from this supplier',
    'action_edit'            => 'Edit',
    'action_delete'          => 'Delete',
    'more_actions'           => 'More actions',
    'confirm_delete'         => 'Delete supplier ":name"?',

    // States
    'empty_list' => 'No suppliers found.',
    'close'      => 'Close',

    // Pagination ("Showing 1–15 of 40 suppliers")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'suppliers',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',
];
