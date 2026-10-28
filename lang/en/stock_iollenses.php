<?php

declare(strict_types=1);

/**
 * IOL lens listing texts (Pages/Panel/Stock/IolLenses/Index, IolLensTable,
 * IolLensCards) — injected as the `t` prop by
 * App\Http\Controllers\Stock\IolLensesController::index.
 */
return [
    'page_title'         => 'Cataract lenses',
    'total_label'        => 'Total:',
    'view_table'         => 'Table',
    'view_cards'         => 'Cards',
    'btn_new'            => 'New lens',
    'search_placeholder' => 'Search by model or manufacturer...',
    'search_clear'       => 'Clear search',
    'close'              => 'Close',
    'toggle_error'       => 'Could not load the current lens data. Refresh the page and try again.',

    // Filters
    'filter_status_label'    => 'Filter by status',
    'filter_status_all'      => 'All',
    'filter_status_active'   => 'Active',
    'filter_status_inactive' => 'Inactive',

    // Columns
    'col_model'        => 'Model',
    'col_manufacturer' => 'Manufacturer',
    'col_category'     => 'Type',
    'col_diopters'     => 'Diopters',
    'col_price'        => 'Price',
    'col_stock'        => 'Stock',
    'col_status'       => 'Status',
    'col_actions'      => 'Actions',
    'sort_by'          => 'Sort by :column',
    'diopter_range'    => ':min to :max D',
    'not_informed'     => 'Not informed',

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
    'action_movements'  => 'Lens movements',
    'action_edit'       => 'Edit',
    'action_activate'   => 'Activate',
    'action_deactivate' => 'Deactivate',
    'action_delete'     => 'Delete',
    'confirm_delete'    => 'Delete the lens ":name"?',
    'more_actions'      => 'More actions',

    // States
    'empty_list' => 'No lenses found.',

    // Pagination ("Showing 1–12 of 40 lenses")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'lenses',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',
];
