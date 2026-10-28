<?php

declare(strict_types=1);

/**
 * Stock product listing texts (Pages/Panel/Stock/Products/Index,
 * ProductTable, ProductCards) — injected as the `t` prop by
 * App\Http\Controllers\Stock\ProductsController::index.
 */
return [
    'page_title'         => 'Products',
    'total_label'        => 'Total:',
    'view_table'         => 'Table',
    'view_cards'         => 'Cards',
    'btn_movements'      => 'Movements',
    'btn_import'         => 'Import',
    'btn_new'            => 'New product',
    'search_placeholder' => 'Search by name, SKU, code or barcode...',
    'search_clear'       => 'Clear search',
    'close'              => 'Close',
    'toggle_error'       => 'Could not load the current product data. Refresh the page and try again.',

    // Filters
    'filter_status_label'    => 'Filter by status',
    'filter_category_label'  => 'Filter by category',
    'filter_status_all'      => 'All',
    'filter_status_active'   => 'Active',
    'filter_status_inactive' => 'Inactive',
    'category_all'           => 'All categories',
    'filter_low_stock'       => 'Below minimum only',
    'filter_expiring_lots'   => 'Lot expiring only (30d)',

    // Columns
    'col_code'        => 'Code',
    'col_name'        => 'Name',
    'col_category'    => 'Category',
    'col_unit'        => 'Unit',
    'col_qty_on_hand' => 'On hand',
    'col_cost_avg'    => 'Average cost',
    'col_sale_price'  => 'Price',
    'col_status'      => 'Status',
    'col_actions'     => 'Actions',
    'sort_by'         => 'Sort by :column',

    // Indicators
    'badge_opm'          => 'OPM',
    'badge_opm_title'    => 'Orthosis, prosthesis or special material',
    'badge_expiring_lot' => 'Lot expiring',
    'expiring_lot_title' => 'Expires on :date',
    'below_minimum'      => 'Below minimum',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Restore default',

    // Status / actions
    'status_active'     => 'Active',
    'status_inactive'   => 'Inactive',
    'action_movements'  => 'Product movements',
    'action_edit'       => 'Edit',
    'action_activate'   => 'Activate',
    'action_deactivate' => 'Deactivate',
    'action_delete'     => 'Delete',
    'confirm_delete'    => 'Delete the product ":name"?',
    'more_actions'      => 'More actions',

    // States
    'empty_list' => 'No products found.',

    // Pagination ("Showing 1–15 of 40 products")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'products',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',
];
