<?php

declare(strict_types=1);

/**
 * Stock movements listing texts (Pages/Panel/Stock/Movements/Index,
 * MovementTable, MovementCards) — injected as the `t` prop by
 * StockMovementsController::index. Same keys as lang/pt_BR.
 */
return [
    'page_title'         => 'Stock movements',
    'total_label'        => 'Total:',
    'view_table'         => 'Table',
    'view_cards'         => 'Cards',
    'btn_products'       => 'Products',
    'btn_new'            => 'New movement',
    'search_placeholder' => 'Search by product, code, lot or note...',
    'search_clear'       => 'Clear search',
    'filter_product'     => 'Filter by product',
    'filter_product_all' => 'All products',
    'filter_type'        => 'Filter by type',
    'filter_type_all'    => 'All types',
    'close'              => 'Close',

    // Columns
    'col_occurred_at'   => 'Date',
    'col_product'       => 'Product',
    'col_lot'           => 'Lot',
    'col_type'          => 'Type',
    'col_quantity'      => 'Quantity',
    'col_unit_cost'     => 'Unit cost',
    'col_balance_after' => 'Balance after',
    'col_note'          => 'Note',
    'col_created_by'    => 'By',
    'col_actions'       => 'Actions',
    'sort_by'           => 'Sort by :column',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Restore default',

    // Type labels come from the backend (`type_label`, lang/{locale}/stock_enums.php).

    // Actions (immutable ledger: no edit/delete — a correction is a new entry)
    'action_filter_product' => 'View this product\'s ledger',

    // States
    'empty_list' => 'No movements found.',

    // Pagination ("Showing 1–20 of 40 movements")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'movements',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',
];
