import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import CashFlowReport from '@/Pages/Panel/Financial/Reports/CashFlow.vue';

/**
 * Relatório de fluxo de caixa (fase 3): período pelo PeriodFilter (URL),
 * KPIs realizado × previsto em KpiCard (overview do servidor, com dica),
 * "Por categoria" com receitas e despesas separadas e % em texto, "Por dia"
 * com saldo do dia/acumulado e rodapé, lançamentos com tipo em texto + ícone,
 * status em badge e exportação do período aplicado.
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR', flash: {}, errors: {} } }),
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: {
        props: ['title', 'breadcrumbs'],
        template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>',
    },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: {
        props: ['title'],
        template:
            '<div class="dd" :data-title="title"><span class="dd-trigger"><slot name="trigger" /></span><ul><slot /></ul></div>',
    },
}));

const t = {
    loading: 'Loading…',
    load_error: 'Could not load.',
    sort_by: 'Sort by :column',
    export: 'Export',
    export_title: 'Export the applied period (:from to :to)',
    export_csv: 'CSV',
    export_xlsx: 'Excel (.xlsx)',
    export_pdf: 'PDF',
    entry_type: { income: 'Revenue', expense: 'Expense' },
    entry_status: { pending: 'Pending', paid: 'Paid', cancelled: 'Cancelled' },
    cashflow: {
        title: 'Cash flow report',
        total_label: 'Entries:',
        kpis_label: 'Period indicators',
        group_realized: 'Realized',
        group_realized_hint: 'Only paid entries.',
        group_projected: 'Projected',
        group_projected_hint: 'Includes pending entries.',
        kpi_received: 'Received',
        kpi_received_hint: 'Paid income.',
        kpi_paid: 'Paid',
        kpi_paid_hint: 'Paid expenses.',
        kpi_realized_balance: 'Realized balance',
        kpi_realized_balance_hint: 'Received minus Paid.',
        kpi_receivable: 'Receivable',
        kpi_receivable_hint: 'Pending income.',
        kpi_payable: 'Payable',
        kpi_payable_hint: 'Pending expenses.',
        kpi_projected_balance: 'Projected balance',
        kpi_projected_balance_hint: 'Realized plus receivable minus payable.',
        balance_positive: 'Positive',
        balance_negative: 'Negative',
        balance_zero: 'Zero',
        kpi_scope_note: 'Cancelled entries are excluded.',
        by_category_income: 'Revenue by category',
        by_category_expense: 'Expenses by category',
        col_category: 'Category',
        col_total: 'Total',
        col_share: '% of total',
        no_category_income: 'No revenue.',
        no_category_expense: 'No expenses.',
        by_day: 'By day',
        by_day_hint: 'Paid and pending.',
        col_day: 'Day',
        col_income: 'Revenue',
        col_expense: 'Expenses',
        col_day_balance: 'Day balance',
        col_cumulative: 'Running balance',
        footer_total: 'Period total',
        no_day_data: 'No days.',
        entries: 'Entries',
        col_date: 'Date',
        col_code: 'Code',
        col_description: 'Description',
        col_covenant: 'Insurer',
        col_payment_method: 'Payment method',
        col_type: 'Type',
        col_status: 'Status',
        col_value: 'Amount',
        no_entries: 'No entries.',
    },
    shared: {
        period: {
            label: 'Period',
            from: 'From',
            to: 'To',
            invalid_range: 'Start must be before end.',
            invalid_date: 'Invalid date.',
            after_max: 'Not after :date.',
            presets: {
                today: 'Today',
                yesterday: 'Yesterday',
                last7: 'Last 7 days',
                month: 'This month',
                last_month: 'Last month',
                year: 'This year',
                custom: 'Custom',
            },
        },
    },
};

function baseProps(overrides = {}) {
    return {
        breadcrumbs: [],
        filters: { from: '2026-09-01', to: '2026-09-26' },
        today: '2026-09-26',
        summary: { income: 350, expense: 120, balance: 230, pending: 50 },
        overview: {
            received: 300,
            receivable: 50,
            paid: 80,
            payable: 40,
            realized_balance: 220,
            projected_balance: 230,
            entries_count: 2,
        },
        byCategory: [
            {
                key: 'c1:income',
                category_id: 'c1',
                category: 'CONSULTAS | PARTICULAR',
                type: 'income',
                total: 262.5,
                share: 75,
            },
            { key: 'c2:income', category_id: 'c2', category: 'EXAMES', type: 'income', total: 87.5, share: 25 },
            {
                key: 'none:expense',
                category_id: null,
                category: 'Sem categoria',
                type: 'expense',
                total: 120,
                share: 100,
            },
        ],
        byDay: [
            { day: '2026-09-05', income: 350, expense: 80, balance: 270, cumulative: 270 },
            { day: '2026-09-06', income: 0, expense: 40, balance: -40, cumulative: 230 },
        ],
        // Paginador do Laravel (lista paginada no servidor).
        entries: {
            data: [
                {
                    id: 'a',
                    code: 'FLC-1',
                    entry_date: '2026-09-05',
                    description: 'Consulta',
                    category_name: null,
                    covenant_name: 'UNIMED',
                    payment_method_label: 'Cash',
                    type: 'income',
                    status: 'pending',
                    amount: 50,
                },
                {
                    id: 'b',
                    code: 'FLC-2',
                    entry_date: '2026-09-06',
                    description: 'Aluguel',
                    category_name: 'FIXAS',
                    covenant_name: null,
                    payment_method_label: null,
                    type: 'expense',
                    status: 'paid',
                    amount: 80,
                },
            ],
            links: [],
            current_page: 1,
            last_page: 1,
            total: 2,
            from: 1,
            to: 2,
        },
        routes: { index: '/reports/cash-flow', export: '/reports/cash-flow/export' },
        export_formats: ['csv', 'xlsx', 'pdf'],
        t,
        ...overrides,
    };
}

let wrapper;

beforeEach(() => {
    vi.mocked(router.get).mockReset();
});
afterEach(() => wrapper?.unmount());

function mountPage(overrides = {}) {
    wrapper = mount(CashFlowReport, { props: baseProps(overrides), attachTo: document.body });

    return wrapper;
}

const clean = (value) => value.replace(/ | /g, ' ');
const text = (el) => clean(el.text());
const brl = (v) => clean(new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const signed = (v) =>
    clean(new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', signDisplay: 'exceptZero' }).format(v));
const pct = (v) =>
    clean(
        new Intl.NumberFormat('pt-BR', { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(
            v / 100,
        ),
    );
const kpiValue = (w, key) => text(w.find(`[data-test="kpi-${key}"]`));
const kpiCard = (w, key) => w.find(`[data-kpi="${key}"] .kpi-card`);

describe('Financial/Reports/CashFlow — indicadores', () => {
    it('KPIs realizado × previsto vêm do overview, em dois grupos com dica', () => {
        const w = mountPage();

        expect(kpiValue(w, 'received')).toBe(brl(300));
        expect(kpiValue(w, 'paid')).toBe(brl(80));
        expect(kpiValue(w, 'realized_balance')).toBe(signed(220));
        expect(kpiValue(w, 'receivable')).toBe(brl(50));
        expect(kpiValue(w, 'payable')).toBe(brl(40));
        expect(kpiValue(w, 'projected_balance')).toBe(signed(230));

        const realized = w.find('[data-group="realized"]');
        expect(realized.find('h2').text()).toBe('Realized');
        expect(realized.find('[data-test="kpi-group-hint"]').text()).toBe('Only paid entries.');
        expect(realized.findAll('[data-kpi]').map((k) => k.attributes('data-kpi'))).toEqual([
            'received',
            'paid',
            'realized_balance',
        ]);
        expect(
            w
                .find('[data-group="projected"]')
                .findAll('[data-kpi]')
                .map((k) => k.attributes('data-kpi')),
        ).toEqual(['receivable', 'payable', 'projected_balance']);

        expect(kpiCard(w, 'projected_balance').attributes('title')).toBe('Realized plus receivable minus payable.');
        expect(w.find('[data-kpi="received"] .visually-hidden').text()).toBe('Paid income.');
        expect(w.find('[data-test="kpi-scope-note"]').text()).toBe('Cancelled entries are excluded.');
    });

    it('saldo negativo tem sinal, texto e borda de alerta; zerado fica neutro', () => {
        const w = mountPage({
            overview: {
                received: 10,
                paid: 30,
                receivable: 0,
                payable: 0,
                realized_balance: -20,
                projected_balance: 0,
            },
        });

        expect(kpiValue(w, 'realized_balance')).toBe(signed(-20));
        expect(kpiCard(w, 'realized_balance').text()).toContain('Negative');
        expect(kpiCard(w, 'realized_balance').classes()).toContain('border-danger');
        expect(w.find('[data-test="kpi-realized_balance"]').classes()).toContain('text-body');
        expect(kpiCard(w, 'projected_balance').classes()).toContain('border-secondary');
        expect(kpiCard(w, 'projected_balance').text()).toContain('Zero');
    });
});

describe('Financial/Reports/CashFlow — por categoria e por dia', () => {
    it('receitas e despesas em tabelas separadas, com % do tipo em texto e barra decorativa', () => {
        const w = mountPage();
        const income = w.find('[data-test="category-income"]');
        const expense = w.find('[data-test="category-expense"]');

        expect(income.find('h2').text()).toBe('Revenue by category');
        expect(income.findAll('[data-test="category-row"] th').map((th) => th.text())).toEqual([
            'CONSULTAS | PARTICULAR',
            'EXAMES',
        ]);
        expect(text(income.find('[data-test="category-share"]'))).toBe(pct(75));
        expect(income.find('[data-test="category-bar"]').attributes('aria-hidden')).toBe('true');
        expect(income.find('[data-test="category-bar"] .progress-bar').attributes('style')).toContain('width: 75%');
        expect(income.find('[data-test="category-bar"] .progress-bar').classes()).toContain('bg-success');
        expect(text(income.find('[data-test="category-footer"]'))).toBe(brl(350));

        expect(expense.findAll('[data-test="category-row"]')).toHaveLength(1);
        expect(expense.find('[data-test="category-bar"] .progress-bar').classes()).toContain('bg-danger');
        expect(text(expense.find('[data-test="category-footer"]'))).toBe(brl(120));
    });

    it('por dia: data no idioma, saldo do dia e acumulado com sinal e rodapé com os totais do período', () => {
        const w = mountPage();
        const rows = w.findAll('[data-test="day-row"]');

        expect(text(rows[0].find('th'))).toBe('05/09/2026');
        expect(rows[0].find('time').attributes('datetime')).toBe('2026-09-05');
        expect(text(rows[1].find('[data-test="day-balance"]'))).toBe(signed(-40));
        expect(text(rows[1].find('[data-test="day-cumulative"]'))).toBe(signed(230));
        expect(text(w.find('[data-test="day-total-income"]'))).toBe(brl(350));
        expect(text(w.find('[data-test="day-total-expense"]'))).toBe(brl(120));
        expect(text(w.find('[data-test="day-total-cumulative"]'))).toBe(signed(230));
        expect(w.find('[data-test="day-totals"] th').text()).toBe('Period total');
    });

    it('estados vazios nas tabelas', () => {
        const w = mountPage({
            byCategory: [],
            byDay: [],
            entries: { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
        });

        expect(w.find('[data-test="category-income"]').text()).toContain('No revenue.');
        expect(w.find('[data-test="category-expense"]').text()).toContain('No expenses.');
        expect(w.find('[data-test="day-empty"]').text()).toBe('No days.');
        expect(w.find('[data-test="entries-empty"]').text()).toBe('No entries.');
        expect(w.find('[data-test="day-totals"]').exists()).toBe(false);
    });
});

describe('Financial/Reports/CashFlow — lançamentos e exportação', () => {
    it('lançamentos: data no idioma, tipo com texto + ícone, status em badge-soft e despesa com sinal', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const rows = mountPage().findAll('[data-test="entry-row"]');

        expect(text(rows[0].find('[data-test="entry-date"]'))).toBe('05/09/2026');
        expect(rows[0].find('[data-test="entry-category"]').text()).toBe('—');
        expect(rows[0].find('[data-test="entry-payment-method"]').text()).toBe('Cash');
        expect(rows[0].find('[data-test="entry-type"]').text()).toBe('Revenue');
        expect(rows[0].find('[data-test="entry-type"] i').attributes('aria-hidden')).toBe('true');
        expect(rows[0].find('[data-test="entry-status"]').text()).toBe('Pending');
        expect(rows[0].find('[data-test="entry-status"]').classes()).toContain('badge-soft-warning');
        expect(text(rows[0].find('[data-test="entry-value"]'))).toBe(signed(50));
        expect(rows[1].find('[data-test="entry-type"]').text()).toBe('Expense');
        expect(rows[1].find('[data-test="entry-payment-method"]').text()).toBe('—');
        expect(text(rows[1].find('[data-test="entry-value"]'))).toBe(signed(-80));
        expect(warn.mock.calls.flat().join(' ')).not.toMatch(/Duplicate keys/i);
        warn.mockRestore();
    });

    it('exporta CSV, Excel e PDF do período aplicado e mostra o total de lançamentos', () => {
        const w = mountPage();
        const links = w.findAll('a[data-format]');

        expect(links.map((a) => a.attributes('data-format'))).toEqual(['csv', 'xlsx', 'pdf']);
        expect(links.map((a) => a.text())).toEqual(['CSV', 'Excel (.xlsx)', 'PDF']);
        expect(links[1].attributes('href')).toBe('/reports/cash-flow/export?from=2026-09-01&to=2026-09-26&format=xlsx');
        expect(w.find('.dd').attributes('data-title')).toBe('Export the applied period (01/09/2026 to 26/09/2026)');
        expect(w.find('.dd-trigger').text()).toBe('Export');
        expect(w.find('.page-header-total').text()).toBe('Entries: 2');
    });
});

describe('Financial/Reports/CashFlow — período', () => {
    it('atalho do PeriodFilter aplica na URL a partir do "hoje" do servidor; rótulos de t.shared.period', async () => {
        const w = mountPage();

        expect(w.find('.period-filter').attributes('aria-label')).toBe('Period');
        expect(w.find('[data-test="period-preset"]').element.value).toBe('month');

        await w.find('[data-test="period-preset"]').setValue('last_month');

        expect(router.get).toHaveBeenCalledWith(
            '/reports/cash-flow',
            { from: '2026-08-01', to: '2026-08-31' },
            expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }),
        );
    });

    it('período invertido mostra o aviso e não recarrega', async () => {
        const w = mountPage();

        await w.find('[data-test="period-from"]').setValue('2026-10-01');

        expect(router.get).not.toHaveBeenCalled();
        expect(w.find('[data-test="period-error"]').text()).toContain('Start must be before end.');
    });

    it('erro de rede aparece na tela e o filtro volta ao período exibido', async () => {
        let options;
        vi.mocked(router.get).mockImplementation((url, data, opts) => {
            options = opts;
            opts.onStart?.();
        });

        const w = mountPage();
        await w.find('[data-test="period-preset"]').setValue('last_month');
        await nextTick();

        expect(w.find('[data-test="loading-status"]').text()).toBe('Loading…');
        expect(w.find('[data-test="kpi-received"]').exists()).toBe(false); // placeholder do KpiCard

        expect(options.onNetworkError(new Error('offline'))).toBe(false);
        options.onFinish();
        await nextTick();

        expect(w.find('[data-test="load-error"]').text()).toBe('Could not load.');
        expect(w.find('[data-test="period-from"]').element.value).toBe('2026-09-01');
    });
});
