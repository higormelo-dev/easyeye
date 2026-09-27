import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import CashFlowReport from '@/Pages/Panel/Financial/Reports/CashFlow.vue';
import { SEARCH_DEBOUNCE_MS } from '@/Pages/Panel/Financial/Reports/useEntryFilters.js';

/**
 * Relatório de fluxo de caixa (fase 4) — lista de lançamentos paginada no
 * servidor: busca com debounce e filtros de tipo/status/categoria aplicados
 * sozinhos (recarga parcial só de `entries`/`filters`, volta à página 1),
 * cabeçalhos ordenáveis (SortableTh), paginação (TablePagination), atalho
 * "Abrir no fluxo de caixa" por linha, aviso de período recortado e troca de
 * período que mantém os filtros da lista.
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR', flash: {}, errors: {} } }),
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd"><slot name="trigger" /><ul><slot /></ul></div>' },
}));

const t = {
    loading: 'Loading…', load_error: 'Could not load.', sort_by: 'Sort by :column',
    export: 'Export', export_title: 'Export (:from to :to)', export_csv: 'CSV', export_xlsx: 'Excel', export_pdf: 'PDF',
    entry_type: { income: 'Revenue', expense: 'Expense' },
    entry_status: { pending: 'Pending', paid: 'Paid', cancelled: 'Cancelled' },
    cashflow: {
        title: 'Cash flow report', total_label: 'Entries:', kpis_label: 'Indicators',
        by_category_income: 'Revenue by category', by_category_expense: 'Expenses by category',
        col_category: 'Category', col_total: 'Total', col_share: '% of total',
        by_day: 'By day', col_day: 'Day', col_income: 'Revenue', col_expense: 'Expenses',
        entries: 'Entries', col_date: 'Date', col_code: 'Code', col_description: 'Description', col_covenant: 'Insurer',
        col_payment_method: 'Payment method', col_type: 'Type', col_status: 'Status', col_value: 'Amount', no_entries: 'No entries.',
        period_capped: 'Requested :requested_from to :requested_to exceeds :days days. Showing :from to :to.',
        filters_label: 'Entry list filters', filters_scope_hint: 'Filters apply only to the list.',
        search_placeholder: 'Search by description or code', search_clear: 'Clear search',
        filter_type: 'Filter by type', filter_type_all: 'All types',
        filter_status: 'Filter by status', filter_status_all: 'All statuses',
        filter_category: 'Filter by category', filter_category_all: 'All categories',
        filters_clear: 'Clear filters',
        filtered_count_one: ':count entry found', filtered_count_other: ':count entries found',
        no_entries_filtered: 'No entries match.', list_loading: 'Updating…', col_actions: 'Actions',
        open_in_cash_flow: 'Open in cash flow', open_in_cash_flow_code: 'Open :code in cash flow',
        pagination_label: 'Entries pagination', pagination_showing: 'Showing', pagination_of: 'of', pagination_suffix: 'entries',
        pagination_previous: 'Previous page', pagination_next: 'Next page',
    },
    shared: {
        period: {
            label: 'Period', from: 'From', to: 'To',
            invalid_range: 'Start must be before end.', invalid_date: 'Invalid date.', after_max: 'Not after :date.',
            presets: { today: 'Today', yesterday: 'Yesterday', last7: 'Last 7 days', month: 'This month', last_month: 'Last month', year: 'This year', custom: 'Custom' },
        },
    },
};

const ROWS = [
    { id: 'a', code: 'FLC0000000001', entry_date: '2026-09-05', description: 'Consulta', category_name: 'CONSULTAS', covenant_name: null, payment_method_label: null, type: 'income', status: 'paid', amount: 50, cash_flow_url: '/cash-flow?from=2026-09-01&to=2026-09-26&search=FLC0000000001' },
    { id: 'b', code: null, entry_date: '2026-09-06', description: 'Legado', category_name: null, covenant_name: null, payment_method_label: null, type: 'expense', status: 'pending', amount: 80, cash_flow_url: '/cash-flow?from=2026-09-06&to=2026-09-06' },
];

function paginator(overrides = {}) {
    return {
        data: ROWS,
        current_page: 1, last_page: 3, per_page: 2, total: 6, from: 1, to: 2,
        prev_page_url: null, next_page_url: '/reports/cash-flow?entries_page=2',
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            { url: '/reports/cash-flow?entries_page=1', label: '1', active: true },
            { url: '/reports/cash-flow?entries_page=2', label: '2', active: false },
            { url: '/reports/cash-flow?entries_page=3', label: '3', active: false },
            { url: '/reports/cash-flow?entries_page=2', label: 'Next &raquo;', active: false },
        ],
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        breadcrumbs: [],
        filters: { from: '2026-09-01', to: '2026-09-26', search: '', type: null, status: null, category_id: null, sort: 'entry_date', direction: 'asc' },
        period_capped: null,
        today: '2026-09-26',
        summary: { income: 50, expense: 80, balance: -30, pending: 80 },
        overview: { received: 50, receivable: 0, paid: 0, payable: 80, realized_balance: 50, projected_balance: -30, entries_count: 6 },
        byCategory: [],
        byDay: [],
        categories: [
            { id: 'c-exp', name: 'ALUGUEL', type: 'expense' },
            { id: 'c-inc', name: 'CONSULTAS', type: 'income' },
        ],
        entries: paginator(),
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

afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

function mountPage(overrides = {}) {
    wrapper = mount(CashFlowReport, { props: baseProps(overrides), attachTo: document.body });

    return wrapper;
}

/** Última chamada do router.get: [url, data, options]. */
const lastVisit = () => vi.mocked(router.get).mock.calls.at(-1);

describe('Financial/Reports/CashFlow — lista paginada', () => {
    it('mostra a página do servidor, a paginação traduzida e o atalho para o Fluxo de caixa por linha', () => {
        const w = mountPage();
        const rows = w.findAll('[data-test="entry-row"]');

        expect(rows).toHaveLength(2);

        const open = rows[0].find('[data-test="entry-open"]');
        expect(open.attributes('href')).toBe(ROWS[0].cash_flow_url);
        expect(open.attributes('aria-label')).toBe('Open FLC0000000001 in cash flow');
        // Sem código (legado): rótulo genérico, link para o dia do lançamento.
        expect(rows[1].find('[data-test="entry-open"]').attributes('aria-label')).toBe('Open in cash flow');
        expect(rows[1].find('[data-test="entry-open"]').attributes('href')).toBe(ROWS[1].cash_flow_url);

        const pagination = w.find('[data-test="entries-pagination"]');
        expect(pagination.find('nav').attributes('aria-label')).toBe('Entries pagination');
        expect(pagination.text()).toContain('Showing 1–2 of 6 entries');
        expect(pagination.find('[aria-label="Next page"]').attributes('href')).toBe('/reports/cash-flow?entries_page=2');

        // Total do cabeçalho = período inteiro (overview), não a página.
        expect(w.find('.page-header-total').text()).toBe('Entries: 6');
    });

    it('uma página só: sem paginação', () => {
        const w = mountPage({ entries: paginator({ last_page: 1, total: 2, next_page_url: null }) });

        expect(w.find('[data-test="entries-pagination"]').exists()).toBe(false);
    });

    it('cabeçalho ordenável: aria-sort na coluna atual e clique recarrega só a lista, na página 1', async () => {
        const w = mountPage();
        const headers = w.find('[data-test="entries"]').findAll('thead th');

        expect(headers[0].attributes('aria-sort')).toBe('ascending');
        expect(headers[1].attributes('aria-sort')).toBe('none');
        expect(headers[0].find('button').attributes('title')).toBe('Sort by Date');

        const amount = headers.find((th) => th.text() === 'Amount');
        await amount.find('button').trigger('click');

        const [url, data, options] = lastVisit();
        expect(url).toBe('/reports/cash-flow');
        expect(data).toEqual({ from: '2026-09-01', to: '2026-09-26', sort: 'amount', direction: 'asc' });
        expect(options).toEqual(expect.objectContaining({ only: ['entries', 'filters'], preserveState: true, preserveScroll: true, replace: true }));
        expect(data).not.toHaveProperty('entries_page');
    });
});

describe('Financial/Reports/CashFlow — busca e filtros da lista', () => {
    it('selects aplicam na hora, sem botão, mantendo a ordenação', async () => {
        const w = mountPage({ filters: { ...baseProps().filters, sort: 'amount', direction: 'desc' } });

        await w.find('[data-test="filter-type"]').setValue('expense');
        await nextTick();

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1]).toEqual({ from: '2026-09-01', to: '2026-09-26', type: 'expense', sort: 'amount', direction: 'desc' });

        await w.find('[data-test="filter-status"]').setValue('pending');
        await nextTick();

        expect(lastVisit()[1]).toEqual(expect.objectContaining({ type: 'expense', status: 'pending' }));
    });

    it('categorias do período agrupadas por tipo', async () => {
        const w = mountPage();
        const groups = w.findAll('[data-test="filter-category"] optgroup');

        expect(groups.map((g) => g.attributes('label'))).toEqual(['Revenue', 'Expenses']);
        expect(groups[0].findAll('option').map((o) => o.text())).toEqual(['CONSULTAS']);

        await w.find('[data-test="filter-category"]').setValue('c-exp');
        await nextTick();

        expect(lastVisit()[1]).toEqual(expect.objectContaining({ category_id: 'c-exp' }));
    });

    it('busca com debounce: uma visita só, com % e _ como digitados', async () => {
        vi.useFakeTimers();
        const w = mountPage();
        const input = w.find('[data-test="entries-toolbar"] input');

        await input.setValue('50');
        await input.setValue('50%_');
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 1);
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1]).toEqual(expect.objectContaining({ search: '50%_' }));
        expect(lastVisit()[2].only).toEqual(['entries', 'filters']);
    });

    it('com filtros aplicados: contagem no plural do idioma, vazio próprio e "Limpar filtros" numa visita só', async () => {
        vi.useFakeTimers();
        const filters = { ...baseProps().filters, search: 'xyz', type: 'income' };
        const w = mountPage({ filters, entries: paginator({ total: 3, last_page: 2 }) });

        expect(w.find('[data-test="entries-status"]').text()).toBe('3 entries found');

        await w.setProps({ entries: paginator({ data: [ROWS[0]], total: 1, last_page: 1 }) });
        expect(w.find('[data-test="entries-status"]').text()).toBe('1 entry found');

        await w.setProps({ entries: paginator({ data: [], total: 0, last_page: 1, from: null, to: null }) });
        expect(w.find('[data-test="entries-empty"]').text()).toBe('No entries match.');

        await w.find('[data-test="filters-clear"]').trigger('click');
        await nextTick();
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS);

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1]).toEqual({ from: '2026-09-01', to: '2026-09-26', sort: 'entry_date', direction: 'asc' });
    });

    it('sem filtros: sem contagem, sem "Limpar filtros" e vazio do período', () => {
        const w = mountPage({ entries: paginator({ data: [], total: 0, last_page: 1, from: null, to: null }) });

        expect(w.find('[data-test="entries-status"]').text()).toBe('');
        expect(w.find('[data-test="filters-clear"]').exists()).toBe(false);
        expect(w.find('[data-test="entries-empty"]').text()).toBe('No entries.');
        expect(w.find('[data-test="filters-scope-hint"]').text()).toBe('Filters apply only to the list.');
    });

    it('filtro normalizado pelo servidor volta à barra sem nova visita', async () => {
        const w = mountPage({ filters: { ...baseProps().filters, category_id: 'c-inc' } });

        await w.setProps({ filters: { ...baseProps().filters, category_id: null } });
        await nextTick();

        expect(w.find('[data-test="filter-category"]').element.value).toBe('');
        expect(router.get).not.toHaveBeenCalled();
    });

    it('erro de rede na lista: aviso dentro da lista (não no topo) e o Inertia não abre o modal de erro', async () => {
        let options;
        vi.mocked(router.get).mockImplementation((url, data, opts) => { options = opts; });

        const w = mountPage();
        await w.find('[data-test="filter-type"]').setValue('income');
        await nextTick();

        expect(options.onNetworkError(new Error('offline'))).toBe(false);
        await nextTick();

        expect(w.find('[data-test="list-error"]').text()).toBe('Could not load.');
        expect(w.find('[data-test="load-error"]').exists()).toBe(false);
    });
});

describe('Financial/Reports/CashFlow — período', () => {
    it('trocar o período mantém busca/filtros/ordem da lista e recarrega a tela inteira', async () => {
        const w = mountPage({ filters: { ...baseProps().filters, search: 'consulta', status: 'paid', sort: 'amount', direction: 'desc' } });

        await w.find('[data-test="period-preset"]').setValue('last_month');

        const [url, data, options] = lastVisit();
        expect(url).toBe('/reports/cash-flow');
        expect(data).toEqual({ from: '2026-08-01', to: '2026-08-31', search: 'consulta', status: 'paid', sort: 'amount', direction: 'desc' });
        expect(options.only).toBeUndefined();
    });

    it('período recortado: aviso traduzido com as datas no idioma do usuário', () => {
        const w = mountPage({
            filters: { ...baseProps().filters, from: '2025-09-27', to: '2026-09-27' },
            period_capped: { requested_from: '2024-01-01', requested_to: '2026-09-27', max_days: 366 },
        });

        const alert = w.find('[data-test="period-capped"]');
        expect(alert.attributes('role')).toBe('status');
        expect(alert.text()).toBe('Requested 01/01/2024 to 27/09/2026 exceeds 366 days. Showing 27/09/2025 to 27/09/2026.');
    });

    it('sem recorte, sem aviso', () => {
        expect(mountPage().find('[data-test="period-capped"]').exists()).toBe(false);
    });
});
