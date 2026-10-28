<?php

declare(strict_types=1);

/**
 * Physical stock count texts (Pages/Panel/Stock/Counts/Index, CountTable,
 * CountCards) — injected as the `t` prop by StockCountsController::index.
 * Same keys as lang/pt_BR.
 */
return [
    'page_title'          => 'Stock count',
    'total_label'         => 'Total:',
    'view_table'          => 'Table',
    'view_cards'          => 'Cards',
    'btn_movements'       => 'Movements',
    'opens_new_tab'       => 'opens in a new tab',
    'btn_apply'           => 'Apply count (:count)',
    'applying'            => 'Applying count...',
    'help'                => 'Type the PHYSICALLY counted quantity next to each product. Items left blank are not changed — only products with a typed value are adjusted. A count equal to the system balance creates no movement. Typed values are kept while you search, filter or change pages.',
    'search_placeholder'  => 'Search by name, code or barcode...',
    'search_clear'        => 'Clear search',
    'filter_category'     => 'Filter by category',
    'filter_category_all' => 'All categories',
    'touched_summary'     => ':touched of :total product(s) with a typed count',

    // Columns
    'col_product'     => 'Product',
    'col_code'        => 'Code',
    'col_category'    => 'Category',
    'col_qty_on_hand' => 'System balance',
    'col_counted'     => 'Counted',
    'col_difference'  => 'Difference',
    'col_actions'     => 'Actions',
    'sort_by'         => 'Sort by :column',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Restore default',

    // Row
    'requires_lot'     => 'Requires lot',
    'counted_label'    => 'Counted quantity of :product',
    'difference_match' => 'Matches',
    // The title adds `opens_new_tab`: "View product movements (opens in a new tab)".
    'action_movements' => 'View product movements',

    // Unit labels come from the backend (`unit_label`, lang/{locale}/stock_enums.php).

    // Result
    'result_applied' => ':count product(s) adjusted — the rest already matched the system.',
    'apply_error'    => 'Could not apply the count.',
    'close'          => 'Close',

    // States
    'empty_list' => 'No active products found to count.',

    // Pagination ("Showing 1–50 of 120 products")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'products',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',
];
