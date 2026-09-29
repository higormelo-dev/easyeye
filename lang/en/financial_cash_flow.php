<?php

/*
 * Live Cash Flow screen (Panel/Financial/CashFlow/Index.vue +
 * CashEntryFormModal.vue), CashFlowController/CashFlowService/CashEntryRequest
 * messages and FinancialEntryType/FinancialEntryStatus enum labels.
 * Keep the SAME keys as lang/pt_BR/financial_cash_flow.php.
 */

return [
    'page_title'           => 'Cash Flow',
    'breadcrumb_financial' => 'Financial',
    'breadcrumb'           => 'Cash Flow',
    'total_label'          => 'Total:',
    'new_entry'            => 'New entry',
    'close_cash'           => 'Close cash register',
    'report'               => 'Report',

    // Indicators (same filters as the list; cancelled entries excluded)
    'kpis_label'                 => 'Period indicators',
    'kpi_received'               => 'Received',
    'kpi_received_hint'          => 'Income marked as Paid in the period and list filters.',
    'kpi_receivable'             => 'Receivable',
    'kpi_receivable_hint'        => 'Income still pending in the period and list filters.',
    'kpi_paid'                   => 'Paid',
    'kpi_paid_hint'              => 'Expenses marked as Paid in the period and list filters.',
    'kpi_payable'                => 'Payable',
    'kpi_payable_hint'           => 'Expenses still pending in the period and list filters.',
    'kpi_realized_balance'       => 'Realized balance',
    'kpi_realized_balance_hint'  => 'Received minus Paid: what actually came in and went out of the cash register.',
    'kpi_projected_balance'      => 'Projected balance',
    'kpi_projected_balance_hint' => 'Realized balance plus Receivable, minus Payable.',
    'kpi_scope_note'             => 'Indicators follow the period and the list filters. Cancelled entries are excluded.',

    // Filters (applied automatically)
    'filters_label'       => 'Cash flow filters',
    'search_placeholder'  => 'Search by description or code (FLC)',
    'search_clear'        => 'Clear search',
    'filter_type'         => 'Type',
    'filter_type_all'     => 'All',
    'filter_type_income'  => 'Income',
    'filter_type_expense' => 'Expenses',
    'filter_status'       => 'Status',
    'filter_status_all'   => 'All statuses',
    'filter_category'     => 'Category',
    'filter_category_all' => 'All categories',
    'filter_clear'        => 'Clear filters',
    'filtering'           => 'Updating the list…',

    // Closed period notice
    'closed_banner'      => 'Part of this period has a closed cash register (:periods). Entries on those days cannot be created, changed or deleted.',
    'closed_banner_link' => 'View closings',

    // Table and cards
    'table_caption'      => 'Entries for the period',
    'col_code'           => 'Code',
    'col_date'           => 'Date',
    'col_description'    => 'Description',
    'col_patient'        => 'Patient',
    'col_category'       => 'Category',
    'col_payment_method' => 'Method',
    'col_origin'         => 'Origin',
    'col_type'           => 'Type',
    'col_status'         => 'Status',
    'col_value'          => 'Amount',
    'col_actions'        => 'Actions',
    'sort_by'            => 'Sort by :column',
    'empty'              => 'No entries in this period.',
    'empty_filtered'     => 'No entries match these filters.',
    'action_edit'        => 'Edit entry',
    'action_delete'      => 'Delete entry',

    // Footer: totals for the whole filtered set (all pages)
    'footer_label'   => 'Filter totals (:count entries, cancelled excluded)',
    'footer_income'  => 'Income',
    'footer_expense' => 'Expenses',
    'footer_balance' => 'Balance',

    // Entry origin (system link)
    'origins' => [
        'schedule'      => 'Schedule',
        'claim'         => 'Claim',
        'purchase'      => 'Purchase',
        'doctor_payout' => 'Doctor payout',
        'manual'        => 'Manual',
    ],

    // Per-row lock reason (lock_reason)
    'lock_billing_claim'      => 'Insurance claim',
    'lock_billing_claim_hint' => 'Entry created automatically when a claim payment was recorded. It cannot be changed or deleted here.',
    'lock_closed_period'      => 'Register closed',
    'lock_closed_period_hint' => 'The date falls within a closed cash period. Reopen the period in Cash Closing to change it.',
    'lock_doctor_payout'      => 'Doctor payout',
    'lock_doctor_payout_hint' => 'Expense created automatically when a doctor payout payment was recorded. To fix it, reverse the payment in Financial › Doctor payouts.',

    'types' => [
        'income'  => 'Income',
        'expense' => 'Expense',
    ],
    'statuses' => [
        'pending'   => 'Pending',
        'paid'      => 'Paid',
        'cancelled' => 'Cancelled',
    ],
    // Payment methods (App\Enums\PaymentMethod::label()).
    'payment_methods' => [
        'cash'        => 'Cash',
        'credit'      => 'Credit card',
        'credit_cash' => 'Credit card and cash',
        'debit_cash'  => 'Debit card and cash',
        'transfer'    => 'Bank transfer',
        'courtesy'    => 'Courtesy',
    ],

    // Pagination
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'entries',
    'pagination_label'    => 'Entries pagination',
    'pagination_previous' => 'Previous page',
    'pagination_next'     => 'Next page',

    // Delete confirmation
    'delete_title'         => 'Delete entry?',
    'delete_message'       => 'The entry will be removed from the cash register and from the period balance.',
    'delete_confirm'       => 'Delete',
    'cancel'               => 'Cancel',
    'deleted'              => 'Entry deleted.',
    'delete_error'         => 'Could not delete the entry.',
    'network_error'        => 'Connection failed. Check your internet connection and try again.',
    'session_expired'      => 'Your session has expired. Reload the page and try again.',
    'saved_outside_period' => 'The entry was saved on :date, outside the filtered period.',

    // Entry modal
    'form_title_new'           => 'New entry',
    'form_title_edit'          => 'Edit entry',
    'form_date'                => 'Date',
    'form_type'                => 'Type',
    'form_description'         => 'Description',
    'form_category'            => 'Category',
    'form_category_none'       => 'No category',
    'form_status'              => 'Status',
    'form_amount'              => 'Amount',
    'form_payment_method'      => 'Payment method',
    'form_payment_method_none' => 'Not informed',
    'form_covenant'            => 'Insurance',
    'form_covenant_none'       => 'None',
    'form_notes'               => 'Notes',
    'form_required'            => 'required',
    'form_save'                => 'Save',
    'form_create'              => 'Create',
    'form_save_and_new'        => 'Save and add another',
    'form_cancel'              => 'Cancel',
    'form_save_error'          => 'Could not save the entry.',
    'form_discard_title'       => 'You have unsaved changes. Discard them?',
    'form_discard_confirm'     => 'Discard',
    'form_discard_keep'        => 'Keep editing',
    'form_schedule_locked'     => 'Schedule payment split between cash and card: amount and payment method can only change in the schedule.',
    'form_schedule_link'       => 'Edit in the schedule',

    // Server messages
    'created'                 => 'Entry created successfully.',
    'updated'                 => 'Entry updated successfully.',
    'destroyed'               => 'Entry deleted successfully.',
    'locked_by_claim'         => 'This entry was created by an insurance claim payment and cannot be changed or deleted in the cash flow.',
    'locked_by_doctor_payout' => 'This entry was created by a doctor payout payment and cannot be changed or deleted in the cash flow. Reverse the payment on the Doctor payouts screen.',
    'category_type_mismatch'  => 'Select a clinic category that matches the entry type.',
    'schedule_split_locked'   => 'This payment came from the schedule split between cash and card: amount and payment method can only be changed in the schedule.',

    'reference_managed_by_system' => 'The entry link (appointment, insurance claim or purchase) is set by the system and cannot be provided.',

    'attributes' => [
        'entry_date'     => 'date',
        'description'    => 'description',
        'type'           => 'type',
        'status'         => 'status',
        'amount'         => 'amount',
        'category_id'    => 'category',
        'covenant_id'    => 'insurance',
        'notes'          => 'notes',
        'payment_method' => 'payment method',
        'reference_type' => 'link type',
        'reference_id'   => 'link',
    ],

    // php artisan financial:audit-cash-references (read-only)
    'audit_references' => [
        'invalid_entity' => 'The --entity option must be a valid UUID.',
        'invalid_limit'  => 'The --limit option must be an integer between 1 and :max.',
        'scope_all'      => 'Scope: all clinics.',
        'scope_entity'   => 'Scope: clinic :entity.',
        'col_issue'      => 'Issue',
        'col_count'      => 'Entries',
        'sample'         => ':issue — up to :limit id(s) (entry | clinic):',
        'none'           => 'No inconsistent references found.',
        'found'          => ':count entry(ies) with inconsistent references. Read-only report: nothing was changed.',
        'issues'         => [
            'unknown_type'   => 'Link type outside the whitelist',
            'incomplete'     => 'Incomplete link (type without id or id without type)',
            'missing_target' => 'Linked record does not exist',
            'cross_entity'   => 'Linked record belongs to another clinic',
            'claim_mismatch' => 'Claim link does not match billing_claim_id',
        ],
    ],
];
