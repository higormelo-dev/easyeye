/**
 * Dados comuns dos testes do Repasse médico: textos (subconjunto de
 * lang/en/financial_doctor_payouts.php), médicos, itens e fechamento.
 */
export const t = {
    title: 'Doctor payouts', my_title: 'My payouts',
    tabs: { apuracao: 'Calculation', closings: 'Closings', rules: 'Rules' },
    statuses: { pending: 'Pending', closed: 'Closed', paid: 'Paid', cancelled: 'Cancelled' },
    service_types: { consultation: 'Consultation', exam: 'Exam', procedure: 'Procedure/surgery', all: 'All types' },
    service_types_plural: { consultation: 'Consultations', exam: 'Exams', procedure: 'Procedures and surgeries' },
    payer_scopes: { any: 'Any payer', particular: 'Private pay', covenant: 'Insurance' },
    calculations: { percentage: 'Percentage', fixed: 'Fixed amount' },
    base_sources: { charged: 'Charged', table: 'Price table', none: 'No value' },
    base_source_hints: { charged: 'Recorded in the cash register.', table: 'From the price table.', none: 'No value at all.' },
    warnings: { no_rule: 'No rule', doctor_mismatch: 'Record by another doctor', late_item: 'After closing' },
    warning_hints: { no_rule: 'Create a rule to close.', doctor_mismatch: 'Fix the appointment doctor.', late_item: 'Goes into a complementary closing.' },
    particular: 'Private pay', all_doctors: 'All doctors', inactive_doctor: 'inactive', none: '—',
    total_label: 'Total:', view_table: 'Table', view_cards: 'Cards',
    filter_doctor: 'Doctor', filter_doctor_placeholder: 'Select the doctor', filter_doctor_any: 'All rules',
    filter_period: 'Period', filter_status: 'Status', filter_status_all: 'All statuses',
    filter_service_type: 'Type', filter_service_type_all: 'All types', filter_clear: 'Clear filters',
    select_doctor_title: 'Select a doctor', select_doctor_hint: 'Choose the doctor and the period.',
    period_capped: 'Requested :requested_from to :requested_to exceeds :days days. Showing :from to :to.',
    kpi_production: 'Production', kpi_production_hint: ':count acts in the period', kpi_charged: 'Charged amount',
    kpi_payout_total: 'Period payout', kpi_paid: 'Already paid', kpi_to_pay: 'Closed, to pay',
    kpi_pending: 'Pending closing', kpi_no_rule: 'Items without rule',
    summary_title: 'Summary by type', summary_type: 'Type', summary_count: 'Quantity',
    summary_charged: 'Charged amount', summary_payout: 'Payout', summary_total: 'Total',
    items_title: 'Attendances that make up the payout', items_empty: 'No attendance in the period.',
    col_date: 'Date', col_patient: 'Patient', col_service: 'Service', col_payer: 'Payer', col_charged: 'Charged amount',
    col_rule: 'Applied rule', col_payout: 'Payout', col_status: 'Status', col_warnings: 'Warnings',
    rule_percentage: ':value% of the charged amount', rule_fixed: ':value fixed', rule_none: 'No rule', closed_in: 'Closing :code',
    close_action: 'Close period', close_title: 'Close the period payout', close_intro: 'Pending items are frozen.',
    close_items: 'Items', close_charged: 'Charged amount', close_payout: 'Payout', close_warnings: 'Warnings to check',
    close_blocked: ':count item(s) without a payout rule.', close_nothing: 'There are no pending items in this period.',
    close_notes: 'Notes (optional)', close_confirm: 'Close payout', close_cancel: 'Cancel', go_to_rules: 'Go to rules',
    export: 'Export', export_csv: 'CSV spreadsheet', export_xlsx: 'Excel spreadsheet',
    closings_title: 'Closings', closings_empty: 'No closing found.', col_code: 'Code', col_doctor: 'Doctor',
    col_period: 'Period', col_items: 'Items', col_total: 'Total', col_paid_at: 'Paid on', col_actions: 'Actions',
    complementary: 'Complementary', complementary_hint: 'Another closing overlaps this period.',
    view: 'View statement', download_pdf: 'Download PDF',
    statement_title: 'Payout statement', statement_doctor: 'Doctor', statement_record: 'License (CRM)',
    statement_period: 'Period', statement_closed_at: 'Closed on', statement_closed_by: 'Closed by', statement_notes: 'Notes',
    statement_items_total: 'Attendances payout', statement_gross: 'Charged amount (production)',
    statement_adjustments_total: 'Adjustments', statement_net_total: 'Total to pay', statement_subtotal: 'Subtotal',
    statement_cancelled: 'Closing cancelled on :date by :user. Reason: :reason',
    statement_reversed: 'Payment reversed on :date by :user. Reason: :reason',
    adjustments_title: 'Manual adjustments', adjustments_empty: 'No adjustments.', adjustment_description: 'Description',
    adjustment_kind: 'Type', adjustment_kind_credit: 'Addition', adjustment_kind_debit: 'Deduction', adjustment_amount: 'Amount',
    adjustment_add: 'Add adjustment', adjustment_remove: 'Remove adjustment', adjustment_remove_title: 'Remove this adjustment?',
    adjustment_hint: 'E.g. advance, withheld tax.',
    payment_title: 'Payment', payment_date: 'Payment date', payment_amount: 'Amount paid', payment_method: 'Payment method',
    payment_notes: 'Payment notes', payment_confirm: 'Confirm payment',
    payment_cash_hint: 'Creates a paid expense in the Cash Flow.', payment_zero_hint: 'Zero total: no cash entry.',
    payment_paid_on: 'Paid on :date', payment_cash_entry: 'Cash entry',
    payment_methods: { transfer: 'Bank transfer', cash: 'Cash' },
    reverse_payment: 'Reverse payment', reverse_payment_title: 'Reverse the payment of this payout?',
    reverse_payment_hint: 'The expense is removed from the Cash Flow.',
    reopen: 'Reopen', reopen_title: 'Reopen (cancel) this closing?', reopen_hint: 'The attendances become pending again.',
    admin_only: 'Only administrators can perform this operation.',
    rules_title: 'Payout rules', rules_intro: 'The most specific rule wins.', rules_empty: 'No rules yet.',
    rules_new: 'New rule', rules_edit: 'Edit rule', rules_delete: 'Delete rule', rules_delete_title: 'Delete this rule?',
    rules_delete_hint: 'Past closings do not change.',
    col_scope_doctor: 'Doctor', col_scope_service: 'Service', col_scope_item: 'Item', col_scope_payer: 'Payer',
    col_calculation: 'Calculation', col_validity: 'Validity', col_active: 'Status', active: 'Active', inactive: 'Inactive',
    validity_always: 'Always', validity_from: 'From :date', validity_until: 'Until :date', validity_range: ':from to :until',
    item_any: 'Any item',
    form_doctor: 'Doctor', form_service_type: 'Service type', form_item_kind: 'Apply to', form_item_any: 'All items of the type',
    form_visit_type: 'Visit type', form_procedure: 'Procedure', form_exam_type: 'Exam type', form_payer_scope: 'Payer',
    form_covenant: 'Specific insurance', form_covenant_any: 'Any insurance', form_calculation: 'Calculation',
    form_percentage: 'Percentage (%)', form_fixed_amount: 'Fixed amount per item', form_valid_from: 'Valid from',
    form_valid_until: 'Valid until', form_validity_hint: 'Blank = no limit.', form_active: 'Active rule', form_notes: 'Notes',
    form_all_types_hint: 'Creates one rule for each type.', form_save: 'Save', form_cancel: 'Cancel',
    settings_title: 'Doctor view', settings_visible: 'Doctors can see their own payouts',
    settings_visible_hint: 'Shows the doctor only their own closings.',
    my_empty: 'No payout closed yet.', my_intro: 'Payout closings made by the clinic.', my_production: 'Production',
    my_payout: 'Payout', my_awaiting: 'Awaiting payment',
    pagination_showing: 'Showing', pagination_of: 'of', pagination_suffix: 'records', pagination_label: 'Pagination',
    pagination_previous: 'Previous page', pagination_next: 'Next page',
    errors: { end_in_future: 'The period end cannot be after today.' },
};

export const doctors = [
    { id: 'd1', name: 'Dra. Ana Lima', record: '12345/SP', active: true },
    { id: 'd2', name: 'Dr. Beto Reis', record: null, active: false },
];

export const itemRows = [
    {
        key: 'schedule:s1', date: '2026-09-02T09:30:00', patient_id: 'p1', patient_name: 'Maria Souza', patient_code: 'P0001',
        service_type: 'consultation', description: 'Consulta', covenant_name: null, is_particular: true, charged: 300,
        base_source: 'charged', rule: { calculation: 'percentage', percentage: 60, fixed: null }, payout: 180,
        status: 'pending', payout_id: null, payout_code: null, warnings: [],
    },
    {
        key: 'procedure:x1', date: '2026-09-03T10:00:00', patient_id: 'p2', patient_name: 'João Lopes', patient_code: 'P0002',
        service_type: 'procedure', description: 'Yag laser', covenant_name: 'Unimed', is_particular: false, charged: 450.5,
        base_source: 'table', rule: null, payout: 0, status: 'pending', payout_id: null, payout_code: null, warnings: ['no_rule'],
    },
    {
        key: 'schedule:s0', date: '2026-08-28T08:00:00', patient_id: 'p1', patient_name: 'Maria Souza', patient_code: 'P0001',
        service_type: 'consultation', description: 'Retorno', covenant_name: null, is_particular: true, charged: 0,
        base_source: 'none', rule: { calculation: 'fixed', percentage: null, fixed: 80 }, payout: 80,
        status: 'paid', payout_id: 'po1', payout_code: 'RM-000001', warnings: [],
    },
];

export function paginator(data, overrides = {}) {
    return {
        data,
        current_page: 1, last_page: 1, per_page: 25, total: data.length, from: data.length ? 1 : null, to: data.length,
        prev_page_url: null, next_page_url: null, links: [],
        ...overrides,
    };
}

export const payoutSummary = {
    id: 'po1', code: 'RM-000001', doctor_id: 'd1', doctor_name: 'Dra. Ana Lima', doctor_record: '12345/SP',
    period_start: '2026-08-01', period_end: '2026-08-31', status: 'closed', items_count: 2,
    gross_amount: 750.5, items_amount: 180, adjustments_amount: -30, total_amount: 150,
    closed_at: '2026-09-01T10:30:00-03:00', closed_by_name: 'Carla Financeiro',
    paid_at: null, paid_amount: null, payment_method: null, payment_notes: null, cash_entry_id: null,
    cancel_reason: null, cancelled_at: null, cancelled_by_name: null,
    payment_reversal_reason: null, payment_reversed_at: null, payment_reversed_by_name: null, notes: 'Conferido',
};

export const statement = {
    payout: payoutSummary,
    groups: [
        { service_type: 'consultation', count: 1, charged: 300, payout: 180, items: [{ ...itemRows[0], status: 'closed', payout_id: 'po1', payout_code: 'RM-000001' }] },
        { service_type: 'procedure', count: 1, charged: 450.5, payout: 0, items: [{ ...itemRows[1], rule: { calculation: 'fixed', percentage: null, fixed: 0 }, warnings: [], status: 'closed' }] },
    ],
    adjustments: [
        { id: 'a1', description: 'Adiantamento', amount: -30, created_at: '2026-09-02T14:00:00-03:00', created_by_name: 'Carla Financeiro' },
    ],
};

/** Moeda como o app formata (pt-BR, BRL; sinal explícito opcional). */
export const brl = (value, signed = false) => new Intl.NumberFormat('pt-BR', {
    style: 'currency', currency: 'BRL', ...(signed ? { signDisplay: 'exceptZero' } : {}),
}).format(value);
