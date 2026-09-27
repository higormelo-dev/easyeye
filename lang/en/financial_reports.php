<?php

declare(strict_types=1);

/*
 * Financial reports (Panel/Financial/Reports/{CashFlow,Covenants}.vue +
 * FinancialReportsController, including export headers).
 * Page prop `t`; same keys as lang/pt_BR/financial_reports.php.
 * Period filter texts come from financial_shared (`t.shared.period`).
 */
return [
    // Screen state
    'loading'    => 'Loading…',
    'load_error' => 'Could not load the report. Please try again shortly.',
    'sort_by'    => 'Sort by :column',

    // Export
    'export'            => 'Export',
    'export_title'      => 'Export the applied period (:from to :to)',
    'export_csv'        => 'CSV (plain spreadsheet)',
    'export_xlsx'       => 'Excel (.xlsx)',
    'export_pdf'        => 'PDF',
    'export_pdf_failed' => 'The PDF could not be generated right now. Export to Excel or CSV, or try again later.',

    // Exported file formatting
    'date_format'       => 'm/d/Y',
    'decimal_separator' => '.',

    // Missing values
    'no_category' => 'No category',
    'no_covenant' => 'No insurer',
    // Deleted insurer (soft delete) that still has billing in the period.
    'covenant_inactive' => ':name (inactive)',
    'no_patient'        => 'No patient',
    // Privacy (user decision): patient shown only by code + initials, on screen and in exports.
    'patient_ref' => ':code · :initials',

    // Enum labels (cash entry and claim)
    'entry_type' => [
        'income'  => 'Revenue',
        'expense' => 'Expense',
    ],
    'entry_status' => [
        'pending'   => 'Pending',
        'paid'      => 'Paid',
        'cancelled' => 'Cancelled',
    ],
    'claim_status' => [
        'draft'     => 'Draft',
        'submitted' => 'Submitted',
        'paid'      => 'Paid',
        'denied'    => 'Denied',
        'cancelled' => 'Cancelled',
    ],

    // Cash flow report
    'cashflow' => [
        'title'       => 'Cash flow report',
        'breadcrumb'  => 'Cash flow report',
        'sheet_name'  => 'Cash flow',
        'total_label' => 'Entries:',

        // Indicators: realized × projected (same definition as the Cash flow screen)
        'kpis_label'                 => 'Period indicators',
        'group_realized'             => 'Realized',
        'group_realized_hint'        => 'Only entries marked as Paid.',
        'group_projected'            => 'Projected',
        'group_projected_hint'       => 'Includes pending entries: what is still to be received and paid.',
        'kpi_received'               => 'Received',
        'kpi_received_hint'          => 'Income marked as Paid in the period.',
        'kpi_paid'                   => 'Paid',
        'kpi_paid_hint'              => 'Expenses marked as Paid in the period.',
        'kpi_realized_balance'       => 'Realized balance',
        'kpi_realized_balance_hint'  => 'Received minus Paid: what actually came in and went out of the cash register.',
        'kpi_receivable'             => 'Receivable',
        'kpi_receivable_hint'        => 'Income still pending in the period.',
        'kpi_payable'                => 'Payable',
        'kpi_payable_hint'           => 'Expenses still pending in the period.',
        'kpi_projected_balance'      => 'Projected balance',
        'kpi_projected_balance_hint' => 'Realized balance plus Receivable, minus Payable.',
        'balance_positive'           => 'Positive',
        'balance_negative'           => 'Negative',
        'balance_zero'               => 'Zero',
        'kpi_scope_note'             => 'Cancelled entries are excluded. The management dashboard only shows what has been realized.',

        // By category (income and expenses apart)
        'by_category_income'  => 'Revenue by category',
        'by_category_expense' => 'Expenses by category',
        'col_category'        => 'Category',
        'col_total'           => 'Total',
        'col_share'           => '% of total',
        'no_category_income'  => 'No revenue in the period.',
        'no_category_expense' => 'No expenses in the period.',

        // By day
        'by_day'          => 'By day',
        'by_day_hint'     => 'Paid and pending, cancelled excluded. The running balance adds up the days since the start of the period.',
        'col_day'         => 'Day',
        'col_income'      => 'Revenue',
        'col_expense'     => 'Expenses',
        'col_day_balance' => 'Day balance',
        'col_cumulative'  => 'Running balance',
        'footer_total'    => 'Period total',
        'no_day_data'     => 'No day with activity in the period.',

        // Entries (screen and export headers)
        'entries'            => 'Entries',
        'col_date'           => 'Date',
        'col_code'           => 'Code',
        'col_description'    => 'Description',
        'col_covenant'       => 'Insurer',
        'col_payment_method' => 'Payment method',
        'col_type'           => 'Type',
        'col_status'         => 'Status',
        'col_value'          => 'Amount',
        'no_entries'         => 'No entries in the period.',

        // Period limit (screen and exports)
        'period_capped' => 'The requested period (:requested_from to :requested_to) exceeds the :days-day limit. Showing :from to :to; exports use the same period.',

        // Entry list: search, filters, sorting, shortcut and pagination
        'filters_label'          => 'Entry list filters',
        'filters_scope_hint'     => 'Search and filters apply only to the list. Indicators, the category and day tables and the export use the whole period.',
        'search_placeholder'     => 'Search by description or code',
        'search_clear'           => 'Clear search',
        'filter_type'            => 'Filter by type',
        'filter_type_all'        => 'All types',
        'filter_status'          => 'Filter by status',
        'filter_status_all'      => 'All statuses',
        'filter_category'        => 'Filter by category',
        'filter_category_all'    => 'All categories',
        'filters_clear'          => 'Clear filters',
        'filtered_count_one'     => ':count entry found with the filters',
        'filtered_count_other'   => ':count entries found with the filters',
        'no_entries_filtered'    => 'No entries match the search and filters.',
        'list_loading'           => 'Updating the list…',
        'col_actions'            => 'Actions',
        'open_in_cash_flow'      => 'Open in cash flow',
        'open_in_cash_flow_code' => 'Open :code in cash flow',
        'pagination_label'       => 'Entries pagination',
        'pagination_showing'     => 'Showing',
        'pagination_of'          => 'of',
        'pagination_suffix'      => 'entries',
        'pagination_previous'    => 'Previous page',
        'pagination_next'        => 'Next page',

        // PDF (resources/views/pdf/financial_cashflow.blade.php); columns and
        // empty states reuse the screen keys.
        'pdf' => [
            'clinic'         => 'Clinic: :name',
            'period'         => 'Period: :from to :to',
            'generated_at'   => 'Generated on: :datetime',
            'total_income'   => 'Total income',
            'total_expense'  => 'Total expenses',
            'period_balance' => 'Period balance',
        ],
    ],

    // Billing by insurer report
    'covenants' => [
        'title'        => 'Billing by insurer report',
        'breadcrumb'   => 'Billing by insurer',
        'sheet_name'   => 'Billing by insurer',
        'period_basis' => 'Period by attendance date. Draft and cancelled claims are left out of the totals, the same rule as the management dashboard.',
        'total_label'  => 'Claims:',

        // Indicators
        'kpis_label'        => 'Period indicators',
        'kpi_claims'        => 'Claims',
        'kpi_claims_hint'   => 'Claims with attendance in the period, drafts and cancelled excluded.',
        'kpi_billed'        => 'Total billed',
        'kpi_billed_hint'   => 'Sum of the billed claims in the period.',
        'kpi_received'      => 'Received',
        'kpi_received_hint' => 'Paid amount of claims marked as Paid (same rule as the management dashboard).',
        'kpi_glosa'         => 'Denied',
        'kpi_glosa_hint'    => 'Amount denied by insurers in the period claims.',
        'kpi_open'          => 'Outstanding',
        'kpi_open_hint'     => 'Amount of submitted claims still awaiting payment.',
        'rate_of_billed'    => ':percent of billed',

        // Consolidated by insurer
        'by_covenant'        => 'Consolidated by insurer',
        'col_covenant'       => 'Insurer',
        'col_billed'         => 'Billed',
        'col_received'       => 'Received',
        'col_glosa'          => 'Denied',
        'col_open'           => 'Outstanding',
        'col_glosa_rate'     => '% Denied',
        'col_received_rate'  => '% Received',
        'claims_count_one'   => ':count claim',
        'claims_count_other' => ':count claims',
        'inactive_badge'     => 'Inactive',
        'inactive_hint'      => 'Insurer removed from the registry; its billing for the period is still shown here.',
        'glosa_alert_badge'  => 'High',
        'glosa_alert_hint'   => 'Denials above :threshold of the billed amount.',
        'glosa_alert_legend' => '"High" = denials above :threshold of the billed amount. % Denied and % Received are based on the billed amount.',
        'footer_total'       => 'Total',
        'no_data'            => 'No billed claims in the period.',

        // Insurer claims (expanded row) and export headers
        'toggle_hint'         => 'Select an insurer to see its claims in the period.',
        'claims_title'        => ':covenant claims in the period',
        'claims_loading'      => 'Loading claims…',
        'claims_error'        => 'Could not load the claims. Please try again.',
        'claims_retry'        => 'Try again',
        'claims_empty'        => 'No claims for this insurer in the period.',
        'claims_close'        => 'Close',
        'claims_privacy_note' => 'For privacy, the patient is shown only by code and initials.',
        'col_guide'           => 'Claim',
        'col_attendance_date' => 'Attendance date',
        'col_patient'         => 'Patient (code · initials)',
        'col_status'          => 'Status',
        'col_value'           => 'Amount',
        'view_in_billing'     => 'View in billing',
        'view_glosas'         => 'View denials for the period',
        'pagination_label'    => 'Claims pagination',
        'pagination_previous' => 'Previous page',
        'pagination_next'     => 'Next page',
        'pagination_status'   => 'Page :current of :last',
    ],
];
