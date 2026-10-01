import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import CashFlowIndex from '@/Pages/Panel/Financial/CashFlow/Index.vue';

/**
 * Fluxo de caixa (Fase 3): barra de filtros com aplicação automática (período,
 * busca com debounce, tipo segmentado, status, categoria, "Limpar filtros"),
 * KPIs do overview do servidor, tabela ordenável + cards com os mesmos dados,
 * totais do conjunto filtrado, modal de lançamento e exclusão confirmada.
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    router: { get: vi.fn(), reload: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel'],
        template: '<div><span class="total">{{ totalLabel }} {{ total }}</span><slot name="actions" /></div>',
    },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: { props: ['data'], template: '<nav class="pagination-stub" />' },
}));
vi.mock('@/Components/Panel/PeriodFilter.vue', () => ({
    default: {
        name: 'PeriodFilterStub',
        props: { from: String, to: String, today: String, labels: Object, compact: Boolean, max: String },
        emits: ['change', 'update:from', 'update:to'],
        template: '<div class="period-stub">{{ from }}|{{ to }}</div>',
    },
}));
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({
    default: {
        name: 'SearchSelectStub',
        props: ['modelValue', 'options', 'placeholder', 'sm'],
        emits: ['update:modelValue'],
        template: `<select class="category-filter" :value="modelValue ?? ''" @change="$emit('update:modelValue', $event.target.value)">
            <option value="">{{ placeholder }}</option>
            <option v-for="o in options" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>`,
    },
}));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size'],
        emits: ['close'],
        template: `<div v-if="open" class="modal-stub"><slot name="header" /><slot /><slot name="footer" />
            <button type="button" class="modal-stub-backdrop" @click="$emit('close')"></button></div>`,
    },
}));
vi.mock('@/Pages/Panel/Financial/CashFlow/CashEntryFormModal.vue', () => ({
    default: {
        name: 'CashEntryFormModalStub',
        props: ['open', 'entry', 'categories', 'covenants', 'paymentMethods', 'today', 'canEditSchedule', 't'],
        emits: ['close', 'saved'],
        template: '<div class="entry-modal-stub" />',
    },
}));

const t = {
    page_title: 'Cash Flow',
    total_label: 'Total:',
    new_entry: 'New entry',
    close_cash: 'Close cash register',
    report: 'Report',
    kpis_label: 'Period indicators',
    kpi_received: 'Received',
    kpi_received_hint: 'Paid income.',
    kpi_receivable: 'Receivable',
    kpi_receivable_hint: 'Pending income.',
    kpi_paid: 'Paid',
    kpi_paid_hint: 'Paid expenses.',
    kpi_payable: 'Payable',
    kpi_payable_hint: 'Pending expenses.',
    kpi_realized_balance: 'Realized balance',
    kpi_realized_balance_hint: 'Received minus paid.',
    kpi_projected_balance: 'Projected balance',
    kpi_projected_balance_hint: 'Realized plus receivable minus payable.',
    kpi_scope_note: 'Indicators follow the filters.',
    filters_label: 'Cash flow filters',
    search_placeholder: 'Search by description or code',
    search_clear: 'Clear search',
    filter_type: 'Type',
    filter_type_all: 'All',
    filter_type_income: 'Income',
    filter_type_expense: 'Expenses',
    filter_status: 'Status',
    filter_status_all: 'All statuses',
    filter_category: 'Category',
    filter_category_all: 'All categories',
    filter_clear: 'Clear filters',
    filtering: 'Updating the list…',
    closed_banner: 'Part of this period is closed (:periods).',
    closed_banner_link: 'View closings',
    table_caption: 'Entries for the period',
    sort_by: 'Sort by :column',
    col_code: 'Code',
    col_date: 'Date',
    col_description: 'Description',
    col_patient: 'Patient',
    col_category: 'Category',
    col_payment_method: 'Method',
    col_origin: 'Origin',
    col_type: 'Type',
    col_status: 'Status',
    col_value: 'Amount',
    col_actions: 'Actions',
    empty: 'No entries in this period.',
    empty_filtered: 'No entries match these filters.',
    action_edit: 'Edit entry',
    action_delete: 'Delete entry',
    footer_label: 'Filter totals (:count entries, cancelled excluded)',
    footer_income: 'Income',
    footer_expense: 'Expenses',
    footer_balance: 'Balance',
    origins: { schedule: 'Schedule', claim: 'Claim', purchase: 'Purchase', manual: 'Manual' },
    lock_billing_claim: 'Insurance claim',
    lock_billing_claim_hint: 'Created by a claim payment.',
    lock_closed_period: 'Register closed',
    lock_closed_period_hint: 'Reopen the period to change it.',
    types: { income: 'Income', expense: 'Expense' },
    statuses: { pending: 'Pending', paid: 'Paid', cancelled: 'Cancelled' },
    delete_title: 'Delete entry?',
    delete_message: 'It leaves the balance.',
    delete_confirm: 'Delete',
    cancel: 'Cancel',
    deleted: 'Entry deleted.',
    delete_error: 'Could not delete the entry.',
    network_error: 'Connection failed.',
    session_expired: 'Session expired.',
    saved_outside_period: 'Saved on :date, outside the filter.',
    shared: { period: { label: 'Period' } },
};

const rows = [
    {
        id: 'free',
        code: 'FLC-0000000003',
        entry_date: '2026-09-10',
        description: 'Consulta particular',
        type: 'income',
        status: 'paid',
        amount: 180,
        patient_name: 'MARIA DA SILVA',
        payment_method: 'cash',
        payment_method_label: 'À Vista',
        origin: 'schedule',
        category_name: null,
        covenant_name: null,
        lock_reason: null,
    },
    {
        id: 'claim',
        code: 'FLC-0000000002',
        entry_date: '2026-09-11',
        description: 'Recebimento de guia',
        type: 'income',
        status: 'paid',
        amount: 250,
        patient_name: null,
        payment_method: 'transfer',
        payment_method_label: 'Transferência Bancária',
        origin: 'claim',
        category_name: null,
        covenant_name: 'Unimed',
        lock_reason: 'billing_claim',
    },
    {
        id: 'closed',
        code: 'FLC-0000000001',
        entry_date: '2026-09-02',
        description: 'Aluguel',
        type: 'expense',
        status: 'paid',
        amount: 1500,
        patient_name: null,
        payment_method: null,
        payment_method_label: null,
        origin: 'manual',
        category_name: 'Aluguel',
        covenant_name: null,
        lock_reason: 'closed_period',
    },
];

const overview = {
    received: 430,
    receivable: 50,
    paid: 1500,
    payable: 20,
    realized_balance: -1070,
    projected_balance: -1040,
    income_total: 480,
    expense_total: 1520,
    entries_count: 5,
};

const categories = [
    { id: 'cat-in', name: 'Consultas', type: 'income' },
    { id: 'cat-out', name: 'Aluguel', type: 'expense' },
];

const brl = (value, signed = false) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        ...(signed ? { signDisplay: 'exceptZero' } : {}),
    }).format(value);

let wrapper;

function mountPage(extra = {}) {
    wrapper = mount(CashFlowIndex, {
        props: {
            entries: { data: rows, total: 3 },
            overview,
            categories,
            covenants: [{ id: 'cov-1', name: 'UNIMED' }],
            payment_methods: [{ value: 'cash', label: 'À Vista' }],
            closed_periods: [],
            filters: {
                from: '2026-09-01',
                to: '2026-09-30',
                type: null,
                status: null,
                category_id: null,
                search: '',
                sort: 'entry_date',
                direction: 'desc',
            },
            today: '2026-09-26',
            can_edit_schedule: true,
            t,
            ...extra,
        },
    });

    return wrapper;
}

function jsonResponse(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

const INDEX_URL = '/_routes/panel.financial.cash-flow.index';
const VISIT_OPTS = expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true });
const lastVisit = () => vi.mocked(router.get).mock.calls.at(-1);

beforeEach(() => {
    vi.mocked(router.get).mockReset();
    vi.mocked(router.reload).mockReset();
    window.showSuccessToast = vi.fn();
    globalThis.fetch = vi.fn(() => jsonResponse(200, { message: 'Entry deleted successfully.' }));
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.useRealTimers();
    delete window.showSuccessToast;
});

describe('Financial/CashFlow/Index — cabeçalho e KPIs', () => {
    it('textos vêm de `t` e o total aparece no cabeçalho', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Cash Flow');
        expect(w.find('.total').text()).toBe('Total: 3');
        expect(w.text()).not.toContain('Receita');
    });

    it('KPIs do overview: recebido, a receber, pago, a pagar e saldos (com sinal), cada um com a definição', () => {
        const w = mountPage();

        expect(w.find('[data-test="kpi-received"]').text()).toBe(brl(430));
        expect(w.find('[data-test="kpi-receivable"]').text()).toBe(brl(50));
        expect(w.find('[data-test="kpi-paid"]').text()).toBe(brl(1500));
        expect(w.find('[data-test="kpi-payable"]').text()).toBe(brl(20));
        expect(w.find('[data-test="kpi-realized_balance"]').text()).toBe(brl(-1070, true));
        expect(w.find('[data-test="kpi-projected_balance"]').text()).toBe(brl(-1040, true));

        const kpis = w.find('[data-test="kpis"]');
        expect(kpis.attributes('aria-label')).toBe('Period indicators');
        expect(kpis.text()).toContain('Realized balance');
        expect(kpis.text()).toContain('Received minus paid.');
        expect(kpis.text()).toContain('Realized plus receivable minus payable.');
    });

    it('atalho "Fechar caixa" leva o período limitado a hoje; "Relatório" mantém De/Até', () => {
        const w = mountPage();

        expect(w.find('[data-test="close-cash-link"]').attributes('href')).toBe(
            '/_routes/panel.financial.cash-closing.index?from=2026-09-01&to=2026-09-26',
        );
        expect(w.find('[data-test="report-link"]').attributes('href')).toBe(
            '/_routes/panel.financial.reports.cash-flow?from=2026-09-01&to=2026-09-30',
        );
    });

    it('avisa quando o período filtrado cruza um caixa fechado, com datas formatadas', () => {
        const w = mountPage({ closed_periods: [{ period_start: '2026-09-01', period_end: '2026-09-05' }] });

        expect(w.find('[data-test="closed-banner"]').text()).toContain(
            'Part of this period is closed (01/09/2026–05/09/2026).',
        );
    });
});

describe('Financial/CashFlow/Index — tabela e cards', () => {
    it('linha mostra código, paciente (só o nome), forma, origem, data localizada e valor com sinal', () => {
        const w = mountPage();
        const row = w.find('[data-test="row-free"]');

        expect(row.find('[data-test="code"]').text()).toBe('FLC-0000000003');
        expect(row.find('[data-test="patient"]').text()).toBe('MARIA DA SILVA');
        expect(row.find('[data-test="payment-method"]').text()).toBe('À Vista');
        expect(row.find('[data-test="origin"]').text()).toBe('Schedule');
        expect(row.text()).toContain('10/09/2026');
        expect(row.find('[data-test="amount"]').text()).toBe(brl(180, true));
        expect(w.find('[data-test="row-closed"] [data-test="amount"]').text()).toBe(brl(-1500, true));
        expect(w.find('[data-test="row-closed"] [data-test="payment-method"]').text()).toBe('—');
        expect(w.find('[data-test="row-claim"] [data-test="origin"]').text()).toBe('Claim');
    });

    it('linha travada mostra o motivo no lugar dos botões; ações têm nome acessível com a descrição', () => {
        const w = mountPage();

        const claimRow = w.find('[data-test="row-claim"]');
        expect(claimRow.find('[data-test="lock-badge"]').text()).toContain('Insurance claim');
        expect(claimRow.find('[data-test="lock-badge"]').attributes('title')).toBe('Created by a claim payment.');
        expect(claimRow.find('[data-test="edit"]').exists()).toBe(false);

        expect(w.find('[data-test="row-closed"] [data-test="lock-badge"]').text()).toContain('Register closed');

        const freeRow = w.find('[data-test="row-free"]');
        expect(freeRow.find('[data-test="edit"]').attributes('aria-label')).toBe('Edit entry: Consulta particular');
        expect(freeRow.find('[data-test="delete"]').attributes('aria-label')).toBe('Delete entry: Consulta particular');
    });

    it('cabeçalhos ordenáveis expõem aria-sort; clicar em Valor ordena por amount (asc) pela URL', async () => {
        const w = mountPage();

        const dateTh = w.findAll('th').find((th) => th.text().startsWith('Date'));
        expect(dateTh.attributes('aria-sort')).toBe('descending');

        const amountTh = w.findAll('th').find((th) => th.text().startsWith('Amount'));
        expect(amountTh.attributes('aria-sort')).toBe('none');
        expect(amountTh.find('button').attributes('title')).toBe('Sort by Amount');

        await amountTh.find('button').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            INDEX_URL,
            { from: '2026-09-01', to: '2026-09-30', sort: 'amount', direction: 'asc' },
            VISIT_OPTS,
        );
    });

    it('cards (abaixo de md) trazem os mesmos dados e ações', async () => {
        const w = mountPage();
        const card = w.find('[data-test="card-free"]');

        expect(card.text()).toContain('Consulta particular');
        expect(card.text()).toContain('FLC-0000000003');
        expect(card.text()).toContain('MARIA DA SILVA');
        expect(card.text()).toContain('À Vista');
        expect(card.find('[data-test="amount"]').text()).toBe(brl(180, true));
        expect(w.find('[data-test="card-claim"] [data-test="lock-badge"]').exists()).toBe(true);

        await card.find('[data-test="edit"]').trigger('click');
        expect(w.findComponent({ name: 'CashEntryFormModalStub' }).props('entry')).toEqual(rows[0]);
    });

    it('rodapé soma TODO o conjunto filtrado (overview), não só a página', () => {
        const w = mountPage();
        const totals = w.find('[data-test="totals"]');

        expect(totals.text()).toContain('Filter totals (5 entries, cancelled excluded)');
        expect(totals.find('[data-test="total-income"]').text()).toBe(brl(480, true));
        expect(totals.find('[data-test="total-expense"]').text()).toBe(brl(-1520, true));
        expect(totals.find('[data-test="total-balance"]').text()).toBe(brl(-1040, true));
    });

    it('estado vazio: sem filtro só oferece "Novo lançamento"; com filtro, também "Limpar filtros"', async () => {
        const w = mountPage({ entries: { data: [], total: 0 } });

        expect(w.find('[data-test="empty-state"]').text()).toContain('No entries in this period.');
        expect(w.find('[data-test="empty-clear-filters"]').exists()).toBe(false);

        await w.find('[data-test="empty-new-entry"]').trigger('click');
        expect(w.findComponent({ name: 'CashEntryFormModalStub' }).props('open')).toBe(true);
        expect(w.findComponent({ name: 'CashEntryFormModalStub' }).props('entry')).toBeNull();

        const filtered = mountPage({
            entries: { data: [], total: 0 },
            filters: {
                from: '2026-09-01',
                to: '2026-09-30',
                type: 'expense',
                status: null,
                category_id: null,
                search: '',
            },
        });
        expect(filtered.find('[data-test="empty-state"]').text()).toContain('No entries match these filters.');
        expect(filtered.find('[data-test="empty-clear-filters"]').exists()).toBe(true);
    });
});

describe('Financial/CashFlow/Index — filtros com aplicação automática', () => {
    it('busca aplica após 400 ms (uma requisição para várias teclas), com preserveState e replace', async () => {
        vi.useFakeTimers();
        const w = mountPage();
        const input = w.find('input[type="text"]');

        expect(input.attributes('aria-label')).toBe('Search by description or code');

        await input.setValue('alu');
        await input.setValue('alug');
        vi.advanceTimersByTime(399);
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()).toEqual([INDEX_URL, { from: '2026-09-01', to: '2026-09-30', search: 'alug' }, VISIT_OPTS]);
    });

    it('tipo segmentado: aria-pressed e aplica na hora; troca de tipo tira categoria incompatível', async () => {
        const w = mountPage({
            filters: {
                from: '2026-09-01',
                to: '2026-09-30',
                type: null,
                status: null,
                category_id: 'cat-in',
                search: '',
            },
        });

        expect(w.find('[data-test="type-all"]').attributes('aria-pressed')).toBe('true');
        expect(w.find('[data-test="type-expense"]').attributes('aria-pressed')).toBe('false');
        expect(w.find('[data-test="type-filter"]').attributes('aria-label')).toBe('Type');

        await w.find('[data-test="type-expense"]').trigger('click');

        expect(w.find('[data-test="type-expense"]').attributes('aria-pressed')).toBe('true');
        expect(lastVisit()).toEqual([INDEX_URL, { from: '2026-09-01', to: '2026-09-30', type: 'expense' }, VISIT_OPTS]);

        // Só categorias de despesa ficam na lista do filtro.
        const options = w.findAll('select.category-filter option').map((o) => o.text());
        expect(options).toContain('Aluguel');
        expect(options).not.toContain('Consultas');
    });

    it('status e categoria aplicam na hora, preservando os outros filtros', async () => {
        const w = mountPage();

        await w.find('[data-test="status-filter"]').setValue('pending');
        expect(lastVisit()).toEqual([
            INDEX_URL,
            { from: '2026-09-01', to: '2026-09-30', status: 'pending' },
            VISIT_OPTS,
        ]);

        await w.find('select.category-filter').setValue('cat-out');
        expect(lastVisit()).toEqual([
            INDEX_URL,
            { from: '2026-09-01', to: '2026-09-30', status: 'pending', category_id: 'cat-out' },
            VISIT_OPTS,
        ]);
    });

    it('período do PeriodFilter aplica na hora (hoje do servidor e rótulos compartilhados repassados)', async () => {
        const w = mountPage();
        const period = w.findComponent({ name: 'PeriodFilterStub' });

        expect(period.props('today')).toBe('2026-09-26');
        expect(period.props('labels')).toEqual({ label: 'Period' });
        expect(period.props('compact')).toBe(true);

        period.vm.$emit('change', { from: '2026-08-01', to: '2026-08-31', preset: 'last_month' });
        await nextTick();

        expect(lastVisit()).toEqual([INDEX_URL, { from: '2026-08-01', to: '2026-08-31' }, VISIT_OPTS]);
    });

    it('"Limpar filtros" só aparece com filtro e limpa busca/tipo/status/categoria mantendo o período (uma requisição)', async () => {
        vi.useFakeTimers();
        const w = mountPage({
            filters: {
                from: '2026-09-01',
                to: '2026-09-30',
                type: 'income',
                status: 'paid',
                category_id: 'cat-in',
                search: 'consulta',
                sort: 'amount',
                direction: 'asc',
            },
        });

        await w.find('[data-test="clear-filters"]').trigger('click');
        vi.advanceTimersByTime(1000);

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()).toEqual([
            INDEX_URL,
            { from: '2026-09-01', to: '2026-09-30', sort: 'amount', direction: 'asc' },
            VISIT_OPTS,
        ]);
        await nextTick();
        expect(w.find('[data-test="clear-filters"]').exists()).toBe(false);
        expect(w.find('input[type="text"]').element.value).toBe('');
    });

    it('enquanto carrega: aviso em aria-live, lista com aria-busy e KPIs em placeholder', async () => {
        vi.mocked(router.get).mockImplementation((url, data, options) => {
            options.onStart?.();
        });
        const w = mountPage();

        await w.find('[data-test="status-filter"]').setValue('paid');

        expect(w.find('[data-test="filtering"]').attributes('aria-live')).toBe('polite');
        expect(w.find('[data-test="filtering"]').text()).toContain('Updating the list…');
        expect(w.find('.cash-flow-results').attributes('aria-busy')).toBe('true');
        expect(w.find('[data-test="kpi-received"]').exists()).toBe(false);
    });
});

describe('Financial/CashFlow/Index — lançamento e exclusão', () => {
    it('Editar leva a linha inteira ao modal, com convênios, formas e permissão da agenda', async () => {
        const w = mountPage();

        await w.find('[data-test="row-free"] [data-test="edit"]').trigger('click');

        const modal = w.findComponent({ name: 'CashEntryFormModalStub' });
        expect(modal.props('open')).toBe(true);
        expect(modal.props('entry')).toEqual(rows[0]);
        expect(modal.props('today')).toBe('2026-09-26');
        expect(modal.props('covenants')).toEqual([{ id: 'cov-1', name: 'UNIMED' }]);
        expect(modal.props('paymentMethods')).toEqual([{ value: 'cash', label: 'À Vista' }]);
        expect(modal.props('canEditSchedule')).toBe(true);
    });

    it('excluir pede confirmação com resumo e mostra o erro do servidor dentro da confirmação', async () => {
        fetch.mockImplementationOnce(() =>
            jsonResponse(422, {
                message: 'Dados de validação inválidos',
                errors: { entry_date: ['O caixa deste período está fechado.'] },
            }),
        );
        const w = mountPage();

        await w.find('[data-test="row-free"] [data-test="delete"]').trigger('click');
        expect(fetch).not.toHaveBeenCalled();

        const summary = w.find('[data-test="delete-summary"]');
        expect(summary.text()).toContain('FLC-0000000003');
        expect(summary.text()).toContain('Consulta particular');
        expect(summary.text()).toContain('10/09/2026');
        expect(summary.text()).toContain(brl(180, true));

        await w.find('[data-test="confirm-delete"]').trigger('click');
        await flushPromises();

        expect(fetch.mock.calls[0][0]).toBe('/_routes/panel.financial.cash-flow.destroy/free');
        expect(fetch.mock.calls[0][1].method).toBe('DELETE');
        expect(w.find('[data-test="delete-error"]').text()).toContain('O caixa deste período está fechado.');
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('falha de rede na exclusão aparece na confirmação; Esc fecha sem excluir', async () => {
        fetch.mockImplementationOnce(() => Promise.reject(new TypeError('Failed to fetch')));
        const w = mountPage();

        await w.find('[data-test="row-free"] [data-test="delete"]').trigger('click');
        await w.find('[data-test="confirm-delete"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-test="delete-error"]').text()).toContain('Connection failed.');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(w.find('[data-test="delete-summary"]').exists()).toBe(false);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('exclusão ok fecha a confirmação, avisa e recarrega lista, overview e fechamentos', async () => {
        const w = mountPage();

        await w.find('[data-test="row-free"] [data-test="delete"]').trigger('click');
        await w.find('[data-test="confirm-delete"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-test="delete-summary"]').exists()).toBe(false);
        expect(window.showSuccessToast).toHaveBeenCalledWith('Entry deleted successfully.');
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['entries', 'overview', 'closed_periods', 'today'] }),
        );
    });

    it('excluir o último item de uma página > 1 leva à última página com dados', async () => {
        const lastPageUrl = 'http://localhost/panel/financial/cash-flow?from=2026-09-01&to=2026-09-30&page=1';
        vi.mocked(router.reload).mockImplementationOnce((options) =>
            options.onSuccess?.({
                props: { entries: { data: [], total: 30, current_page: 2, last_page: 1, last_page_url: lastPageUrl } },
            }),
        );
        const w = mountPage({ entries: { data: [rows[0]], total: 31, current_page: 2, last_page: 2 } });

        await w.find('[data-test="row-free"] [data-test="delete"]').trigger('click');
        await w.find('[data-test="confirm-delete"]').trigger('click');
        await flushPromises();

        expect(router.get).toHaveBeenCalledWith(
            lastPageUrl,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    });

    it('"Salvar e lançar outro" mantém o modal aberto, avisa e recarrega a lista', async () => {
        const w = mountPage();
        await w.find('[data-test="new-entry"]').trigger('click');
        const modal = w.findComponent({ name: 'CashEntryFormModalStub' });

        modal.vm.$emit('saved', { message: 'Entry created successfully.', entryDate: '2026-09-10', keepOpen: true });
        await flushPromises();

        expect(modal.props('open')).toBe(true);
        expect(window.showSuccessToast).toHaveBeenCalledWith('Entry created successfully.');
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['entries', 'overview', 'closed_periods', 'today'] }),
        );

        modal.vm.$emit('saved', { message: 'Entry created successfully.', entryDate: '2026-09-10' });
        await flushPromises();
        expect(modal.props('open')).toBe(false);
    });

    it('salvar com data fora do filtro avisa no toast (evita lançar de novo)', async () => {
        const w = mountPage();

        w.findComponent({ name: 'CashEntryFormModalStub' }).vm.$emit('saved', {
            message: 'Entry created successfully.',
            entryDate: '2026-10-02',
        });
        await flushPromises();

        expect(window.showSuccessToast).toHaveBeenCalledWith(
            'Entry created successfully. Saved on 02/10/2026, outside the filter.',
        );
    });
});
