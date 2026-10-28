<?php

declare(strict_types=1);

/**
 * Stock reports texts: screen (Pages/Panel/Stock/Reports/Index and
 * ReportTable, `t` prop from StockReportsController::index), the
 * StockReportService fallback and the CSV headers (`csv`, used only by
 * exportCsv and kept out of the `t` prop). Breadcrumbs use actions.sidemenu.*.
 */
return [
    'page_title'   => 'Stock reports',
    'btn_export'   => 'Export CSV',
    'export_title' => 'Export ":report" as CSV',

    // Period
    'period_label'    => 'Period (turnover/consumption/purchases):',
    'period_from'     => 'Start date',
    'period_to'       => 'End date',
    'period_until'    => 'to',
    'period_invalid'  => 'The start date must be on or before the end date.',
    'period_required' => 'Enter both the start and end dates.',

    // Tabs
    'tabs_label'      => 'Stock reports',
    'tab_inventory'   => 'Valued inventory / ABC curve',
    'tab_turnover'    => 'Stock turnover',
    'tab_consumption' => 'Consumption by procedure',
    'tab_purchases'   => 'Purchases by supplier',

    // Columns
    'col_product'        => 'Product',
    'col_category'       => 'Category',
    'col_qty_on_hand'    => 'On hand',
    'col_cost_avg'       => 'Average cost',
    'col_total_value'    => 'Total value',
    'col_cumulative_pct' => 'Cumulative %',
    'col_abc_class'      => 'Class',
    'col_qty_out'        => 'Out in period',
    'col_current_qty'    => 'Current on hand',
    'col_turnover_ratio' => 'Turnover (out ÷ on hand)',
    'col_procedure'      => 'Procedure',
    'col_doctor'         => 'Doctor',
    'col_executed_at'    => 'Performed on',
    'col_materials'      => 'Materials',
    'col_total_cost'     => 'Total cost',
    'col_supplier'       => 'Supplier',
    'col_orders_count'   => 'Orders received',
    'col_total_spent'    => 'Total spent',
    'sort_by'            => 'Sort by :column',
    'abc_class_title'    => 'ABC curve class :class',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Reset to default',

    // Summary and notes
    'inventory_total' => 'Total stock value:',
    'note_abc'        => 'ABC curve: class A = products adding up to 80% of the total stock value, B = up to 95%, C = the rest — the standard convention to prioritize control of the items with the highest financial weight.',
    'note_turnover'   => 'Approximate turnover (out in period ÷ current stock value) — not the classic average-stock turnover (that would require daily stock snapshots, which the system does not keep). Useful to compare idle vs. moving products.',
    'note_purchases'  => 'Valued by what ACTUALLY entered stock (confirmed receipts), not by the ordered total — cancelled/partial orders never inflate this number.',

    // Empty states
    'empty_inventory'   => 'No products in stock.',
    'empty_turnover'    => 'No outbound movements in the period.',
    'empty_consumption' => 'No procedure consumption recorded in the period.',
    'empty_purchases'   => 'No purchase receipts in the period.',

    // Supplier deleted after the receipt (StockReportService)
    'supplier_removed' => 'Removed supplier',

    // CSV headers (exportCsv)
    'csv' => [
        'product'        => 'Product',
        'code'           => 'Code',
        'category'       => 'Category',
        'qty_on_hand'    => 'On hand',
        'cost_avg'       => 'Average cost',
        'total_value'    => 'Total value',
        'cumulative_pct' => 'Cumulative %',
        'abc_class'      => 'ABC class',
        'qty_out'        => 'Out in period',
        'current_qty'    => 'Current on hand',
        'turnover_ratio' => 'Turnover',
        'procedure'      => 'Procedure',
        'doctor'         => 'Doctor',
        'executed_at'    => 'Performed on',
        'quantity'       => 'Quantity',
        'unit_cost'      => 'Unit cost',
        'total_cost'     => 'Total cost',
        'supplier'       => 'Supplier',
        'orders_count'   => 'Orders received',
        'total_spent'    => 'Total spent',
    ],
];
