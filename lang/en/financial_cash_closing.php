<?php

/*
 * Cash Closing (Panel/Financial/CashClosing/Index.vue), CashClosingController,
 * CashCloseRequest and ReopenCashCloseRequest messages.
 * Keep the SAME keys as lang/pt_BR/financial_cash_closing.php.
 */

return [
    'page_title'           => 'Cash Closing',
    'breadcrumb_financial' => 'Financial',
    'breadcrumb'           => 'Cash Closing',
    'subtitle'             => 'Close periods to lock entries and prevent retroactive changes.',
    'back_to_cash_flow'    => 'Cash flow',

    // Closing form
    'form_title'      => 'Close a period',
    'last_close_hint' => 'Last closing through :date. The suggested start is the next day.',
    'notes'           => 'Notes',
    'close_btn'       => 'Close period',

    // Preview (read-only; the closing is recalculated on the server)
    'preview'                 => 'Period preview',
    'preview_loading'         => 'Updating preview…',
    'preview_hint'            => 'Includes paid and pending entries; cancelled entries are excluded.',
    'income'                  => 'Income',
    'expense'                 => 'Expenses',
    'balance'                 => 'Balance',
    'entries_count'           => 'Entries',
    'pending_title'           => 'Pending',
    'pending_summary'         => ':count pending: :income receivable and :expense payable.',
    'pending_none'            => 'No pending entries in the period.',
    'pending'                 => 'Receivable (pending)',
    'pending_expense'         => 'Payable (pending)',
    'view_pending'            => 'View pending entries in the cash flow',
    'by_payment_method'       => 'By payment method',
    'by_payment_method_empty' => 'No entries in the period.',
    'col_payment_method'      => 'Method',
    'col_count'               => 'Qty.',
    'payment_method_none'     => 'Not informed',
    'overlap_warning'         => 'A closing already covers part of this period. Adjust the dates or reopen the existing closing.',
    'overlap_periods'         => 'Active closings in the range: :periods.',

    // Closing confirmation
    'confirm_title'           => 'Confirm cash closing',
    'confirm_intro'           => 'Once closed, no entry between :from and :to can be created, changed or deleted until the period is reopened.',
    'confirm_period'          => 'Period',
    'confirm_pending_warning' => 'Pending entries in this period (:count): :income receivable and :expense payable. They are included in the closing totals and, after closing, can only be marked as paid by reopening the period.',
    'confirm_btn'             => 'Confirm closing',
    'cancel'                  => 'Cancel',
    'close_error'             => 'Could not close the period.',
    'closed'                  => 'Period closed successfully.',

    // History
    'history'           => 'Closed periods',
    'empty'             => 'No closed periods yet.',
    'col_period'        => 'Period',
    'col_income'        => 'Income',
    'col_expense'       => 'Expenses',
    'col_balance'       => 'Balance',
    'col_closed_by'     => 'Closed by',
    'col_closed_at'     => 'Closed at',
    'col_notes'         => 'Notes',
    'col_actions'       => 'Actions',
    'view_entries'      => 'View entries for this period',
    'actions_more'      => 'More actions',
    'reopen'            => 'Reopen period',
    'reopen_admin_only' => 'Only clinic administrators can reopen a period.',

    // Reopening (admin only, with a reason)
    'reopen_title'   => 'Reopen period?',
    'reopen_message' => 'Reopening the period from :from to :to allows entries on those days to be created, changed and deleted again. The reopening is recorded in the audit trail.',
    'reopen_confirm' => 'Reopen period',
    'reopened'       => 'Period reopened.',
    'reopen_error'   => 'Could not reopen the period.',

    // Reason modal texts (ConfirmationWithReasonModal) when reopening
    'reopen_reason_modal' => [
        'modal_reason_label'       => 'Reason for reopening',
        'modal_reason_hint'        => 'Explain why the period needs to be reopened (at least 10 characters). The reason is stored in the closing and in the audit trail.',
        'modal_reason_placeholder' => 'E.g.: payment from 08/28 entered with the wrong amount; fix it and close again.',
    ],

    // Pagination
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'closings',
    'pagination_label'    => 'Closings pagination',
    'pagination_previous' => 'Previous page',
    'pagination_next'     => 'Next page',

    // Validation (CashCloseRequest / ReopenCashCloseRequest)
    'validation' => [
        'period_start_future' => 'The period start cannot be later than today.',
        'period_end_future'   => 'The period end cannot be later than today.',
        'period_end_before'   => 'The period end must be on or after the start.',
        'reason_required'     => 'Enter the reason for reopening.',
        'reason_min'          => 'The reason must have at least :min characters.',
        'reason_max'          => 'The reason may not be longer than :max characters.',
    ],
    'attributes' => [
        'period_start' => 'period start',
        'period_end'   => 'period end',
        'notes'        => 'notes',
        'reason'       => 'reason',
    ],
];
