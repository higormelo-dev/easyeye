import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import ReportsIndex from '@/Pages/Panel/Stock/Reports/Index.vue';

/**
 * Relatórios de estoque no layout de Panel/Patients/Index: textos via `t`,
 * "Exportar CSV" no cabeçalho, período na barra de filtros (preserva a
 * ordenação), abas acessíveis, ordenação por relatório e moeda/números/datas
 * no idioma do usuário.
 */

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ColumnOrderMenu.vue', () => ({ default: { template: '<div />' } }));

const t = {
    page_title: 'Stock reports', btn_export: 'Export CSV', export_title: 'Export ":report" as CSV',
    period_label: 'Period:', period_from: 'Start date', period_to: 'End date', period_until: 'to',
    period_invalid: 'Start must be before end.', period_required: 'Enter both dates.', tabs_label: 'Reports',
    tab_inventory: 'Valued inventory', tab_turnover: 'Turnover', tab_consumption: 'Consumption', tab_purchases: 'Purchases',
    col_product: 'Product', col_category: 'Category', col_qty_on_hand: 'On hand', col_cost_avg: 'Average cost',
    col_total_value: 'Total value', col_cumulative_pct: 'Cumulative %', col_abc_class: 'Class',
    col_qty_out: 'Out', col_current_qty: 'Current', col_turnover_ratio: 'Turnover ratio',
    col_procedure: 'Procedure', col_doctor: 'Doctor', col_executed_at: 'Performed on', col_materials: 'Materials',
    col_total_cost: 'Total cost', col_supplier: 'Supplier', col_orders_count: 'Orders', col_total_spent: 'Spent',
    sort_by: 'Sort by :column', abc_class_title: 'ABC class :class', inventory_total: 'Total stock value:',
    empty_inventory: 'No products.', empty_turnover: 'No turnover.', empty_consumption: 'No consumption.', empty_purchases: 'No purchases.',
};

const DEFAULT_SORTS = {
    inventory:   { sort: 'total_value', direction: 'desc' },
    turnover:    { sort: 'qty_out', direction: 'desc' },
    consumption: { sort: 'total_cost', direction: 'desc' },
    purchases:   { sort: 'total_spent', direction: 'desc' },
};

const SORTABLE = {
    inventory:   ['total_value', 'name', 'category_name', 'qty_on_hand', 'cost_avg', 'cumulative_pct'],
    turnover:    ['qty_out', 'name', 'qty_on_hand', 'turnover_ratio'],
    consumption: ['total_cost', 'procedure_name', 'doctor_name', 'executed_at'],
    purchases:   ['total_spent', 'supplier_name', 'orders_count'],
};

function filters(overrides = {}) {
    return { from: '2026-09-01', to: '2026-09-26', report: 'inventory', sort: 'total_value', direction: 'desc', sorts: DEFAULT_SORTS, ...overrides };
}

function baseProps(overrides = {}) {
    return {
        breadcrumbs: [],
        filters: filters(),
        valuedInventory: {
            total_value: 1234.5,
            items: [
                { id: 'p1', name: 'Lente IOL', code: 'PRD-1', category_name: null, unit: 'Unidade', qty_on_hand: 2.5, cost_avg: 50, total_value: 125, cumulative_pct: 80.5, abc_class: 'A' },
                { id: 'p2', name: 'Colírio', code: 'PRD-2', category_name: 'Medicamentos', unit: null, qty_on_hand: 0, cost_avg: 0, total_value: 0, cumulative_pct: 100, abc_class: null },
            ],
        },
        turnover: [{ id: 'p1', name: 'Lente IOL', code: 'PRD-1', qty_out: 1200, qty_on_hand: 3, turnover_ratio: 0.25 }],
        consumptionByProcedure: [{
            procedure_name: 'Facectomia', doctor_name: 'Dra Ana', executed_at: '2026-09-26', total_cost: 200,
            items: [{ product_name: 'Lente IOL', quantity: 1, unit_cost: 200, total_cost: 200 }],
        }],
        purchasesBySupplier: [{ supplier_name: 'Alfa Óptica', orders_count: 3, total_spent: 1500 }],
        sortable: SORTABLE,
        routes: { index: '/stock/reports', export: '/stock/reports/export' },
        t,
        ...overrides,
    };
}

let wrapper;

beforeEach(() => {
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
});
afterEach(() => wrapper?.unmount());

function mountPage(overrides = {}) {
    wrapper = mount(ReportsIndex, { props: baseProps(overrides), attachTo: document.body });

    return wrapper;
}

// Intl usa espaço não separável em "R$ 1.234,50" — normaliza para comparar.
const text = (el) => el.text().replace(/ | /g, ' ');
const panel = (w, report) => w.find(`#stock-report-panel-${report}`);
const isVisible = (el) => el.attributes('style') === undefined || !el.attributes('style').includes('display: none');

describe('Stock/Reports/Index', () => {
    it('usa os textos traduzidos no título, abas, exportação e cabeçalhos', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Stock reports');
        expect(w.find('h4').text()).toBe('Stock reports');
        expect(w.findAll('[role="tab"]').map((tab) => tab.text())).toEqual(['Valued inventory', 'Turnover', 'Consumption', 'Purchases']);
        expect(w.find('a.btn-outline-secondary').text()).toBe('Export CSV');
        expect(w.find('a.btn-outline-secondary').classes()).toEqual(expect.arrayContaining(['fs-13', 'btn-md']));
        expect(panel(w, 'inventory').findAll('thead th').map((th) => th.text())).toEqual([
            'Product', 'Category', 'On hand', 'Average cost', 'Total value', 'Cumulative %', 'Class',
        ]);
        expect(w.find('#stock-reports-from').attributes('aria-label')).toBe('Start date');
        expect(w.find('#stock-reports-to').attributes('aria-label')).toBe('End date');
    });

    it('formata moeda, quantidade e percentual no idioma do usuário', () => {
        const w = mountPage();
        const inventory = panel(w, 'inventory');
        const firstRow = inventory.findAll('tbody tr')[0].findAll('td');

        // Espaço visual entre rótulo e valor vem de `ms-1`, não de texto.
        expect(text(inventory)).toContain('Total stock value:');
        expect(text(inventory.find('strong'))).toBe('R$ 1.234,50');
        expect(firstRow[0].find('.fw-medium').text()).toBe('Lente IOL');
        expect(firstRow[0].find('.text-muted').text()).toBe('(PRD-1)');
        expect(text(firstRow[1])).toBe('—');
        expect(text(firstRow[2])).toBe('2,5 Unidade');
        expect(text(firstRow[3])).toBe('R$ 50,00');
        expect(text(firstRow[5])).toBe('80,5%');
        expect(text(panel(w, 'turnover').findAll('tbody td')[1])).toBe('1.200');
        expect(text(panel(w, 'turnover').findAll('tbody td')[3])).toBe('0,25');
    });

    it('mostra a classe ABC como badge (e travessão sem classe)', () => {
        const w = mountPage();
        const rows = panel(w, 'inventory').findAll('tbody tr');
        const badge = rows[0].find('.badge');

        expect(badge.text()).toBe('A');
        expect(badge.classes()).toEqual(expect.arrayContaining(['badge-soft-success', 'text-success', 'border-success']));
        expect(badge.attributes('title')).toBe('ABC class A');
        expect(rows[1].find('.badge').exists()).toBe(false);
        expect(rows[1].findAll('td').at(-1).text()).toBe('—');
    });

    it('consumo mostra data local, materiais e custo', () => {
        const cells = panel(mountPage(), 'consumption').findAll('tbody td');

        expect(text(cells[2])).toBe('26/09/2026');
        expect(text(cells[3])).toBe('Lente IOL: 1 × R$ 200,00 = R$ 200,00');
        expect(text(cells[4])).toBe('R$ 200,00');
    });

    it('quantidades usam o formatador compartilhado (até 3 casas, sem zeros à direita)', () => {
        const w = mountPage({
            turnover: [{ id: 'p1', name: 'Lente IOL', code: null, qty_out: '2.500', qty_on_hand: 2.12345, turnover_ratio: null }],
            consumptionByProcedure: [{
                procedure_name: 'Facectomia', doctor_name: 'Dra Ana', executed_at: null, total_cost: null,
                items: [{ product_name: null, quantity: 1.25, unit_cost: null, total_cost: null }],
            }],
        });
        const turnoverCells = panel(w, 'turnover').findAll('tbody td');
        const consumptionCells = panel(w, 'consumption').findAll('tbody td');

        expect(text(turnoverCells[1])).toBe('2,5');
        expect(text(turnoverCells[2])).toBe('2,123');
        expect(text(turnoverCells[3])).toBe('—');
        expect(text(consumptionCells[2])).toBe('—');
        expect(text(consumptionCells[3])).toBe('—: 1,25 × — = —');
    });

    it('só oferece ordenação nas colunas da whitelist do servidor', () => {
        const w = mountPage();
        const consumptionHeaders = panel(w, 'consumption').findAll('thead th');

        expect(consumptionHeaders.map((th) => Boolean(th.find('button').exists()))).toEqual([true, true, true, false, true]);
        expect(panel(w, 'inventory').findAll('thead th').at(-1).find('button').exists()).toBe(false);
    });

    it('abre na aba da URL e troca de aba sem ir ao servidor', async () => {
        const w = mountPage({ filters: filters({ report: 'turnover', sort: 'name', direction: 'asc', sorts: { ...DEFAULT_SORTS, turnover: { sort: 'name', direction: 'asc' } } }) });

        expect(isVisible(panel(w, 'turnover'))).toBe(true);
        expect(isVisible(panel(w, 'inventory'))).toBe(false);
        expect(w.find('#stock-report-tab-turnover').attributes('aria-selected')).toBe('true');

        await w.find('#stock-report-tab-purchases').trigger('click');

        expect(isVisible(panel(w, 'purchases'))).toBe(true);
        expect(w.find('#stock-report-tab-purchases').attributes('tabindex')).toBe('0');
        expect(w.find('#stock-report-tab-turnover').attributes('tabindex')).toBe('-1');
        expect(router.get).not.toHaveBeenCalled();
    });

    it('navega entre as abas pelo teclado', async () => {
        const w = mountPage();

        await w.find('#stock-report-tab-inventory').trigger('keydown', { key: 'ArrowRight' });
        await nextTick();
        expect(w.find('#stock-report-tab-turnover').attributes('aria-selected')).toBe('true');
        expect(document.activeElement?.id).toBe('stock-report-tab-turnover');

        await w.find('#stock-report-tab-turnover').trigger('keydown', { key: 'End' });
        await nextTick();
        expect(w.find('#stock-report-tab-purchases').attributes('aria-selected')).toBe('true');

        await w.find('#stock-report-tab-purchases').trigger('keydown', { key: 'ArrowRight' });
        await nextTick();
        expect(w.find('#stock-report-tab-inventory').attributes('aria-selected')).toBe('true');
    });

    it('o CSV exporta a aba ativa com o período aplicado', async () => {
        const w = mountPage();
        const exportLink = () => w.find('a.btn-outline-secondary');

        expect(exportLink().attributes('href')).toBe('/stock/reports/export?report=inventory&from=2026-09-01&to=2026-09-26');

        await w.find('#stock-report-tab-consumption').trigger('click');

        expect(exportLink().attributes('href')).toBe('/stock/reports/export?report=consumption&from=2026-09-01&to=2026-09-26');
        expect(exportLink().attributes('title')).toBe('Export "Consumption" as CSV');
    });

    it('mudar o período recarrega preservando o relatório e a ordenação atuais', async () => {
        const sorts = { ...DEFAULT_SORTS, turnover: { sort: 'name', direction: 'asc' } };
        const w = mountPage({ filters: filters({ report: 'turnover', sort: 'name', direction: 'asc', sorts }) });

        await w.find('#stock-reports-from').setValue('2026-08-01');

        expect(router.get).toHaveBeenCalledWith(
            '/stock/reports',
            { from: '2026-08-01', to: '2026-09-26', report: 'turnover', sort: 'name', direction: 'asc' },
            expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }),
        );
    });

    it('mudar o período grava a aba ativa e mantém a ordenação das outras abas', async () => {
        const sorts = { ...DEFAULT_SORTS, inventory: { sort: 'name', direction: 'asc' } };
        const w = mountPage({ filters: filters({ report: 'inventory', sort: 'name', direction: 'asc', sorts }) });

        await w.find('#stock-report-tab-purchases').trigger('click');
        await w.find('#stock-reports-from').setValue('2026-08-01');

        expect(router.get).toHaveBeenCalledWith(
            '/stock/reports',
            {
                from: '2026-08-01', to: '2026-09-26', report: 'purchases', sort: 'total_spent', direction: 'desc',
                sorts: { inventory: { sort: 'name', direction: 'asc' } },
            },
            expect.objectContaining({ replace: true }),
        );
    });

    it('data apagada sinaliza o campo vazio e não recarrega', async () => {
        const w = mountPage();

        await w.find('#stock-reports-to').setValue('');

        expect(router.get).not.toHaveBeenCalled();
        expect(w.find('#stock-reports-period-error').text()).toBe('Enter both dates.');
        expect(w.find('#stock-reports-to').attributes('aria-invalid')).toBe('true');
        expect(w.find('#stock-reports-to').classes()).toContain('is-invalid');
        expect(w.find('#stock-reports-to').attributes('aria-describedby')).toBe('stock-reports-period-error');
        expect(w.find('#stock-reports-from').attributes('aria-invalid')).toBeUndefined();
        expect(w.find('#stock-reports-from').classes()).not.toContain('is-invalid');
    });

    it('período invertido não recarrega e sinaliza as duas datas', async () => {
        const w = mountPage();

        await w.find('#stock-reports-to').setValue('2026-08-01');

        expect(router.get).not.toHaveBeenCalled();
        expect(w.find('#stock-reports-period-error').text()).toBe('Start must be before end.');
        expect(w.find('#stock-reports-from').attributes('aria-invalid')).toBe('true');
        expect(w.find('#stock-reports-to').attributes('aria-invalid')).toBe('true');
        expect(w.find('#stock-reports-from').attributes('aria-describedby')).toBe('stock-reports-period-error');

        // Corrigido o período, o aviso some e a página recarrega.
        await w.find('#stock-reports-from').setValue('2026-07-01');

        expect(w.find('#stock-reports-period-error').exists()).toBe(false);
        expect(router.get).toHaveBeenCalledTimes(1);
    });

    it('ordenar um relatório envia o relatório, a ordenação e o período aplicado', async () => {
        const w = mountPage();
        const supplierHeader = panel(w, 'purchases').findAll('thead th')[0];

        await supplierHeader.find('button').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/stock/reports',
            { from: '2026-09-01', to: '2026-09-26', report: 'purchases', sort: 'supplier_name', direction: 'asc' },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );
    });

    it('ordenar uma aba reenvia a ordenação que as outras abas já tinham', async () => {
        const sorts = { ...DEFAULT_SORTS, inventory: { sort: 'name', direction: 'asc' } };
        const w = mountPage({ filters: filters({ report: 'inventory', sort: 'name', direction: 'asc', sorts }) });

        await w.find('#stock-report-tab-purchases').trigger('click');
        await panel(w, 'purchases').findAll('thead th')[0].find('button').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/stock/reports',
            {
                from: '2026-09-01', to: '2026-09-26', report: 'purchases', sort: 'supplier_name', direction: 'asc',
                sorts: { inventory: { sort: 'name', direction: 'asc' } },
            },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );
    });

    it('cada aba mostra a ordenação que o servidor aplicou nela', () => {
        const sorts = { ...DEFAULT_SORTS, purchases: { sort: 'supplier_name', direction: 'asc' } };
        const w = mountPage({ filters: filters({ report: 'purchases', sort: 'supplier_name', direction: 'asc', sorts }) });

        expect(panel(w, 'purchases').findAll('thead th')[0].attributes('aria-sort')).toBe('ascending');
        expect(panel(w, 'inventory').findAll('thead th')[4].attributes('aria-sort')).toBe('descending');
    });

    it('sincroniza as datas com o período normalizado pelo servidor', async () => {
        const w = mountPage();

        await w.setProps({ filters: filters({ from: '2026-01-01', to: '2026-01-31' }) });

        expect(w.find('#stock-reports-from').element.value).toBe('2026-01-01');
        expect(w.find('#stock-reports-to').element.value).toBe('2026-01-31');
    });

    it('mostra o estado vazio de cada relatório', () => {
        const w = mountPage({ valuedInventory: { items: [], total_value: 0 }, turnover: [], consumptionByProcedure: [], purchasesBySupplier: [] });

        expect(panel(w, 'inventory').text()).toContain('No products.');
        expect(panel(w, 'turnover').text()).toContain('No turnover.');
        expect(panel(w, 'consumption').text()).toContain('No consumption.');
        expect(panel(w, 'purchases').text()).toContain('No purchases.');
    });
});
