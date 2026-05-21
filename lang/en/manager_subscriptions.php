<?php

declare(strict_types=1);

return [
    // ── Page ──────────────────────────────────────────────────────────────────
    'page_title'         => 'Subscriptions',
    'breadcrumb_home'    => 'Dashboard',
    'breadcrumb_current' => 'Subscriptions',
    'search_placeholder' => 'Search by company or plan...',
    'total_label'        => 'Total:',
    'loading'            => 'Loading...',

    // ── View toggle ───────────────────────────────────────────────────────────
    'view_table' => 'Table view',
    'view_cards' => 'Card view',

    // ── Settings ──────────────────────────────────────────────────────────────
    'settings_title'           => 'Trial & Grace Period Settings',
    'settings_trial_days'      => 'Trial Days',
    'settings_grace_days'      => 'Grace Days (after expiration)',
    'settings_save'            => 'Save',
    'settings_saved'           => 'Settings updated successfully.',

    // ── Table columns ─────────────────────────────────────────────────────────
    'col_entity'        => 'Company',
    'col_plan'          => 'Plan',
    'col_status'        => 'Status',
    'col_billing_state' => 'Billing',
    'col_gateway'       => 'Gateway',
    'col_next_billing'  => 'Next billing',
    'col_starts_at'     => 'Start',
    'col_ends_at'       => 'Expiry',
    'col_actions'       => 'Actions',

    // ── Pagination ────────────────────────────────────────────────────────────
    'showing_from'   => 'Showing',
    'showing_to'     => 'to',
    'showing_of'     => 'of',
    'showing_suffix' => 'subscriptions',

    // ── Empty states ──────────────────────────────────────────────────────────
    'empty_list'     => 'No subscriptions found.',
    'empty_invoices' => 'No invoices found.',
    'empty_retries'  => 'No retry records found.',

    // ── Actions ───────────────────────────────────────────────────────────────
    'action_view'     => 'View',
    'action_edit'     => 'Edit',
    'action_activate' => 'Activate plan',
    'action_trial'    => 'Start trial',
    'action_cancel'   => 'Cancel',
    'action_block'    => 'Block access',
    'action_unblock'  => 'Unblock access',
    'attention_badge' => 'Needs attention',

    // ── Confirmations ─────────────────────────────────────────────────────────
    'confirm_cancel_title'  => 'Cancel subscription?',
    'confirm_cancel_text'   => 'Access will be maintained during the grace period. The cancellation will also be sent to the gateway.',
    'confirm_cancel_btn'    => 'Yes, cancel',
    'confirm_block_title'   => 'Block access?',
    'confirm_block_text'    => 'The company and all its users will lose access to the system.',
    'confirm_block_btn'     => 'Yes, block',
    'confirm_unblock_title' => 'Unblock access?',
    'confirm_unblock_text'  => 'The company and all its users will have their access restored.',
    'confirm_unblock_btn'   => 'Yes, unblock',
    'confirm_no'            => 'No',

    // ── Edit form ─────────────────────────────────────────────────────────────
    'form_edit_title'   => 'Edit Subscription',
    'form_field_plan'   => 'Plan *',
    'form_field_status' => 'Status *',
    'form_field_starts' => 'Start date *',
    'form_field_ends'   => 'Expiry date',
    'form_field_trial'  => 'Trial until',
    'form_select_plan'  => '-- Select --',
    'btn_save_changes'  => 'Save changes',
    'btn_cancel'        => 'Cancel',

    // ── Activate Plan Modal ───────────────────────────────────────────────────
    'activate_title'           => 'Activate Paid Plan',
    'activate_company'         => 'Company',
    'activate_plan'            => 'Plan *',
    'activate_plan_select'     => '-- Select --',
    'activate_cycle'           => 'Billing cycle *',
    'activate_gateway'         => 'Gateway',
    'activate_gateway_hint'    => 'Leave blank to use the system default gateway.',
    'activate_gateway_default' => 'System default',
    'activate_btn'             => 'Activate subscription',
    'activate_success'         => 'Subscription activated successfully.',

    // ── Trial Modal ───────────────────────────────────────────────────────────
    'trial_title'      => 'Start Trial',
    'trial_company'    => 'Company',
    'trial_plan'       => 'Plan (optional)',
    'trial_plan_none'  => 'No specific plan',
    'trial_days'       => 'Duration (days)',
    'trial_days_hint'  => 'Leave blank to use the default (:days days).',
    'trial_btn'        => 'Start trial',
    'trial_success'    => 'Trial started successfully.',

    // ── Detail drawer ─────────────────────────────────────────────────────────
    'detail_title'    => 'Subscription Details',
    'detail_btn_edit' => 'Edit',

    // Tabs
    'tab_overview' => 'Overview',
    'tab_invoices' => 'Invoices & Payments',
    'tab_retries'  => 'Retries',

    // Overview — fields
    'detail_entity'                    => 'Company',
    'detail_plan'                      => 'Plan',
    'section_subscription_status'      => 'Subscription Status',
    'detail_status'                    => 'Status',
    'detail_billing_state'             => 'Billing State',
    'detail_billing_error'             => 'Billing Error',
    'section_gateway'                  => 'Payment Gateway',
    'detail_gateway'                   => 'Gateway',
    'detail_gateway_empty'             => 'Not configured',
    'detail_gateway_customer_id'       => 'Gateway Customer ID',
    'detail_gateway_subscription_id'   => 'Gateway Subscription ID',
    'section_dates'                    => 'Dates',
    'detail_starts_at'                 => 'Start date',
    'detail_ends_at'                   => 'Expiry date',
    'detail_ends_at_lifetime'          => 'Lifetime',
    'detail_next_billing'              => 'Next Billing',
    'detail_next_billing_overdue'      => 'Overdue',
    'detail_last_payment'              => 'Last Payment',
    'detail_trial_ends_at'             => 'Trial until',
    'detail_grace_period_ends_at'      => 'Grace period until',
    'detail_cancelled_at'              => 'Cancelled at',
    'detail_created_at'                => 'Created at',

    // Invoices
    'invoice_period'       => 'Period:',
    'invoice_due_at'       => 'Due date:',
    'invoice_paid_at'      => 'Paid on:',
    'invoice_payments'     => 'Payments',
    'invoice_paid_label'   => 'Paid on:',
    'invoice_failed_label' => 'Failed on:',

    // Retries — columns
    'retry_col_attempt'   => 'Attempt',
    'retry_col_status'    => 'Status',
    'retry_col_gateway'   => 'Gateway',
    'retry_col_scheduled' => 'Scheduled for',
    'retry_col_executed'  => 'Executed at',
    'retry_col_result'    => 'Result',
];
