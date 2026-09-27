<?php

declare(strict_types=1);

/*
 * Management dashboard (Panel/Financial/Bi/Index.vue + ClinicBiService).
 * Page prop `t` (the period uses `t.shared`, from financial_shared.php);
 * same keys as lang/pt_BR/financial_bi.php.
 */
return [
    'title' => 'Management dashboard',

    // Period loading
    'loading'    => 'Loading…',
    'load_error' => 'Could not refresh the dashboard. Please try again shortly.',

    // Cache refresh
    'updated_at'    => 'Updated at :time',
    'refresh'       => 'Refresh',
    'refresh_title' => 'Recalculate now. Figures are kept for up to :minutes minutes so the page opens faster.',

    // KPI bands
    'section_cash'          => 'Cash',
    'section_cash_hint'     => 'Only entries already paid or received in the period.',
    'section_billing'       => 'Insurers and TISS',
    'section_billing_hint'  => 'Claims by attendance date, excluding drafts and cancelled ones.',
    'section_schedule'      => 'Schedule',
    'section_schedule_hint' => 'Appointments in the period.',

    // Cash (label, subtitle and definition — the definition shows in the card hint)
    'income'           => 'Revenue',
    'income_sub'       => 'Payments received',
    'income_hint'      => 'Sum of paid income entries dated within the period.',
    'expense'          => 'Expenses',
    'expense_sub'      => 'Outflows paid',
    'expense_hint'     => 'Sum of paid expense entries dated within the period.',
    'balance'          => 'Balance',
    'balance_hint'     => 'Revenue minus expenses, paid entries only.',
    'balance_positive' => 'Positive',
    'balance_negative' => 'Negative',
    'balance_zero'     => 'Break-even',

    // Insurers
    'billed'            => 'Billed',
    'billed_sub'        => 'Submitted, paid and denied claims',
    'billed_hint'       => 'Amount of claims with attendance in the period, excluding drafts and cancelled ones.',
    'received'          => 'Received',
    'received_sub'      => 'Paid claims',
    'received_hint'     => 'Amount paid on claims with status Paid.',
    'glosa'             => 'Denied',
    'glosa_sub'         => 'Denied by insurers',
    'glosa_hint'        => 'Amount denied by insurers on the claims of the period.',
    'receipt_rate'      => 'Receipt rate',
    'receipt_rate_sub'  => 'Received ÷ billed',
    'receipt_rate_hint' => 'Amount received on paid claims divided by the amount billed in the period.',
    'avg_ticket'        => 'Average ticket',
    'avg_ticket_sub'    => 'Per paid claim',
    'avg_ticket_hint'   => 'Amount received divided by the number of paid claims in the period.',
    'open_billing'      => 'Open billing',
    'see_glosas'        => 'View denials',
    'see_report'        => 'Report by insurer',

    // Schedule
    'attended'             => 'Attended',
    'attended_sub'         => 'of :count appointment(s)',
    'attended_hint'        => 'Appointments in the period with status Attended.',
    'attendance_rate'      => 'Attendance',
    'attendance_rate_sub'  => ':count no-show(s)',
    'attendance_rate_hint' => 'Attended ÷ (attended + no-shows). Cancelled and pending ones are not counted.',
    'occupancy_rate'       => 'Occupancy',
    'occupancy_rate_sub'   => ':count cancelled',
    'occupancy_rate_hint'  => 'Attended ÷ non-cancelled appointments in the period.',
    'new_patients'         => 'New patients',
    'new_patients_sub'     => 'Registered in the period',
    'new_patients_hint'    => 'Patients registered at the clinic within the period.',

    // Billing by insurer (top 6)
    'billing_by_covenant'      => 'Billing by insurer',
    'billing_by_covenant_hint' => 'The 6 insurers with the highest billed amount in the period.',
    'no_claims'                => 'No billed claims in the period.',
    'no_covenant'              => 'No insurer',
    'covenant_inactive'        => ':name (inactive)',

    // Monthly trend (bar chart + "View data" table)
    'monthly_trend'      => 'Monthly trend',
    'monthly_trend_hint' => 'Always the last 6 months, regardless of the period chosen above. Paid entries only.',
    'trend_chart_aria'   => 'Bar chart of revenue and expenses per month, from :from to :to, with the balance line. Total: revenue :income, expenses :expense, balance :balance.',
    'col_month'          => 'Month',
    'col_income'         => 'Revenue',
    'col_expense'        => 'Expenses',
    'col_balance'        => 'Balance',
    'no_trend_data'      => 'No paid entries in the last 6 months.',
    'see_cash_flow'      => 'Cash flow report',
    'see_data'           => 'View data',
    'hide_data'          => 'Hide data',

    // Schedule mix (donut chart + "View data" table)
    'schedule_mix'        => 'Schedule mix',
    'schedule_mix_hint'   => 'Status of the appointments in the period. Pending: scheduled, confirmed or in progress.',
    'schedule_chart_aria' => 'Donut chart with the status of the :total appointment(s) in the period: :items.',
    'schedule_total'      => 'appointment(s)',
    'no_schedules'        => 'No appointments in the period.',
    'col_status'          => 'Status',
    'col_quantity'        => 'Count',
    'col_share'           => 'Share',
    'col_total'           => 'Total',

    // Schedule status labels (chart_* also used by ClinicBiService)
    'chart_attended'  => 'Attended',
    'chart_no_show'   => 'No-show',
    'chart_cancelled' => 'Cancelled',
    'chart_pending'   => 'Pending',
];
