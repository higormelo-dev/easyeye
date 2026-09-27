<?php

declare(strict_types=1);

/**
 * Financial › Price Table screen (panel/financial/procedure-prices).
 * Same keys as lang/pt_BR/financial_procedure_prices.php.
 */
return [
    'title'                => 'Price Table',
    'subtitle'             => 'Set the price of each procedure per health plan.',
    'breadcrumb_financial' => 'Financial',
    'priced_counter'       => ':priced of :total priced',

    'covenant'             => 'Health plan',
    'covenant_placeholder' => 'Select the health plan',
    'covenant_tiss'        => 'TISS operator',
    'covenant_cash'        => 'Collected at the cash desk',
    'code'                 => 'Code',
    'procedure'            => 'Procedure',
    'price'                => 'Price',
    'price_aria'           => 'Price of :procedure',
    'empty_hint'           => 'Leave the price blank to not price the procedure for this health plan (a saved price is removed).',
    'inherited_price'      => 'System default: :price',
    'row_changed'          => 'changed',

    // Search and filters (local)
    'search_placeholder' => 'Search by code or name',
    'search_clear'       => 'Clear search',
    'filter_label'       => 'Filter procedures',
    'filter_all'         => 'All',
    'filter_priced'      => 'Priced',
    'filter_unpriced'    => 'Not priced',
    'no_results'         => 'No procedure matches this search and filter.',
    'clear_filters'      => 'Clear search and filter',

    // "Billable" → "Bill the health plan (TISS claim)"
    'charging'               => 'Bill the health plan (TISS claim)',
    'charging_aria'          => 'Bill :procedure to the health plan via TISS claim',
    'charging_help'          => 'Checked: the procedure is billed to the health plan through a TISS claim and is not fully collected at the arrival cash desk. Note: a single checked procedure makes the schedule treat all appointments of this health plan as billed by claim (the arrival cash desk does not open or prefill the amount).',
    'charging_help_cash'     => 'This health plan has no TISS operator (no ANS registry), like Private: the amount is collected at the cash desk, with no claim. That is why billing the health plan is turned off.',
    'charging_disabled_hint' => 'Enter a price to set the billing.',
    'charging_cash_hint'     => 'Collected at the cash desk: health plan without a TISS operator.',
    'charging_legacy'        => ':count procedure(s) of this health plan are still marked to be billed by claim, which does not apply to a health plan without a TISS operator (the schedule stops opening the arrival cash desk). Save the table to fix it.',

    // Bulk actions (adjust by % and copy from another health plan): grid only —
    // nothing is saved until "Save prices".
    'bulk_menu'           => 'Adjust prices',
    'bulk_menu_label'     => 'Adjust prices in bulk (percentage or copy)',
    'bulk_adjust'         => 'Adjust by %',
    'bulk_copy'           => 'Copy from another health plan',
    'bulk_local_hint'     => 'Changes only go to the grid: nothing is saved until you click "Save prices".',
    'bulk_applied'        => ':count price(s) changed in the grid. Nothing has been saved yet: review and click "Save prices".',
    'bulk_cancel'         => 'Cancel',
    'preview_label'       => 'Preview',
    'preview_changes'     => ':count price(s) will change.',
    'preview_none'        => 'No price changes with these options.',
    'preview_examples'    => 'Examples:',
    'preview_example'     => ':procedure: from :from to :to',
    'preview_example_new' => ':procedure: no own price, becomes :to',

    // Adjust by %
    'adjust_title'         => 'Adjust prices',
    'adjust_direction'     => 'Adjustment type',
    'adjust_increase'      => 'Increase',
    'adjust_decrease'      => 'Decrease',
    'adjust_percent'       => 'Percentage',
    'adjust_percent_help'  => 'Up to 2 decimal places. Each new price is rounded to the cent.',
    'adjust_percent_range' => 'Enter a percentage between :min and :max.',
    'adjust_preview_empty' => 'Enter the percentage to see the preview.',
    'adjust_scope'         => 'Apply to',
    'adjust_scope_visible' => 'Only the :count visible row(s) (current search and filter)',
    'adjust_scope_all'     => 'All :count row(s) of this health plan',
    'adjust_skipped'       => ':count row(s) without their own price stay as they are (the system default is not adjusted).',
    'adjust_apply'         => 'Apply to the grid',

    // Copy from another health plan
    'copy_title'              => 'Copy prices from another health plan',
    'copy_source'             => 'Source health plan',
    'copy_source_placeholder' => 'Select the source health plan',
    'copy_no_sources'         => 'There is no other health plan to copy from.',
    'copy_scope_hint'         => 'Applies to the :count procedures in the table. The copied price is what the source health plan table shows: the clinic price or, without it, the system default.',
    'copy_overwrite'          => 'Also replace prices already filled in for this health plan',
    'copy_overwrite_help'     => 'Unchecked: only procedures without a price get the copied price.',
    'copy_loading'            => 'Loading the source health plan prices...',
    'copy_load_error'         => 'Could not load the prices of this health plan. Please try again.',
    'copy_preview_empty'      => 'Choose the source health plan to see the preview.',
    'copy_kept'               => ':count procedure(s) already have a price and stay as they are.',
    'copy_missing'            => ':count procedure(s) without a price in the source health plan stay as they are.',
    'copy_apply'              => 'Copy to the grid',

    // Save (sticky bar)
    'savebar_label'    => 'Save price table',
    'save'             => 'Save prices',
    'saving'           => 'Saving...',
    'saved'            => 'Prices updated successfully.',
    'save_error'       => 'The prices could not be saved. Check the highlighted rows.',
    'rows_with_errors' => ':count row(s) with errors.',
    'unsaved'          => ':count unsaved change(s)',
    'no_changes'       => 'No pending changes',
    'loading'          => 'Loading prices...',
    'leave_confirm'    => 'You have :count unsaved change(s) in the price table. Leaving this page discards these changes. Leave anyway?',
    'too_many_changes' => 'There are :count changes and the limit per save is :max. Apply the adjustment or copy in parts (use the search or filter) and save in between.',

    // Empty states
    'no_covenants'        => 'Register a health plan before setting prices.',
    'no_covenants_action' => 'Register health plan',
    'no_covenants_ask'    => 'Ask a clinic administrator to register the health plans.',
    'no_procedures'       => 'No active procedures registered.',
    'no_procedures_hint'  => 'Procedures come from the system catalog. If one is missing, contact support.',

    // Switching health plan with pending changes
    'discard_title'   => 'Discard changes?',
    'discard_body'    => 'You have :count unsaved change(s) in :covenant. Switching health plans discards these changes.',
    'discard_confirm' => 'Discard and switch',
    'discard_cancel'  => 'Keep editing',

    // Field names in validation messages
    'covenant_attribute'  => 'health plan',
    'procedure_attribute' => 'procedure',
    'price_attribute'     => 'price',
    'charging_attribute'  => 'bill the health plan',

    // Save validation messages (per row: items.N.*)
    'items_max'           => 'Send at most :max prices at a time.',
    'procedure_invalid'   => 'Procedure not found or from another clinic.',
    'procedure_duplicate' => 'Procedure repeated in the same save.',
    'price_missing'       => 'Enter the price (or send it empty to remove this row price).',
];
