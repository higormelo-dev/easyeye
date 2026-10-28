<?php

declare(strict_types=1);

/**
 * Purchase order listing texts (Pages/Panel/Stock/PurchaseOrders/Index,
 * PurchaseOrderTable, PurchaseOrderCards) — injected as the `t` prop by
 * App\Http\Controllers\Stock\PurchaseOrdersController::index.
 */
return [
    'page_title'         => 'Purchase orders',
    'total_label'        => 'Total:',
    'view_table'         => 'Table view',
    'view_cards'         => 'Card view',
    'btn_suppliers'      => 'Suppliers',
    'btn_new'            => 'New order',
    'search_placeholder' => 'Search by code or supplier...',
    'search_clear'       => 'Clear search',

    // Filters
    'filter_status_label'      => 'Filter by status',
    'filter_status_all'        => 'All statuses',
    'filter_supplier_label'    => 'Filter by supplier',
    'filter_supplier_all'      => 'All suppliers',
    'filter_supplier_unlisted' => 'Selected supplier',
    'filter_supplier_inactive' => ':name (inactive)',

    // Columns
    'col_code'              => 'Code',
    'col_supplier'          => 'Supplier',
    'col_order_date'        => 'Date',
    'col_expected_delivery' => 'Expected delivery',
    'col_total'             => 'Total',
    'col_status'            => 'Status',
    'col_actions'           => 'Actions',
    'sort_by'               => 'Sort by :column',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Restore default',

    // Status labels: `statuses`/`status_label` props (PurchaseOrderStatus::label(), lang/*/stock_enums.php).

    // Actions
    'action_pdf'     => 'Download PDF',
    'action_send'    => 'Send to supplier',
    'action_receive' => 'Receive',
    'action_edit'    => 'Edit',
    'action_cancel'  => 'Cancel order',
    'action_delete'  => 'Delete',
    'more_actions'   => 'More actions',
    'confirm_send'   => 'Send order :code to the supplier?',
    'confirm_cancel' => 'Cancel order :code?',
    'confirm_delete' => 'Delete draft :code?',

    // States
    'empty_list' => 'No purchase orders found.',
    'load_error' => 'Could not open the order. Please try again.',
    'close'      => 'Close',

    // Pagination ("Showing 1–15 of 40 orders")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'orders',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',
];
