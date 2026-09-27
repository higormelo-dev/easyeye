import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import CovenantsReport from '@/Pages/Panel/Financial/Reports/Covenants.vue';

/**
 * Relatório por convênio (fase 3): KPIs em KpiCard (Recebido/Glosado com % do
 * faturado), tabela ordenável no cliente (SortableTh, aria-sort), primeira
 * coluna fixa, % Glosa com selo em TEXTO acima do limiar, rodapé de totais,
 * linha expansível (aria-expanded/aria-controls, Esc devolve o foco) e
 * período pelo PeriodFilter; exportação do período aplicado.
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR', flash: {}, errors: {} } }),
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd" :data-title="title"><span class="dd-trigger"><slot name="trigger" /></span><ul><slot /></ul></div>' },
}));

const t = {
    loading: 'Loading…', load_error: 'Could not load.', sort_by: 'Sort by :column',
    export: 'Export', export_title: 'Export the applied period (:from to :to)', export_csv: 'CSV', export_xlsx: 'Excel (.xlsx)', export_pdf: 'PDF',
    claim_status: { submitted: 'Submitted', paid: 'Paid', denied: 'Denied' },
    covenants: {
        title: 'Billing by insurer report', period_basis: 'Period by attendance date.', total_label: 'Claims:', kpis_label: 'Period indicators',
        kpi_claims: 'Claims', kpi_claims_hint: 'Billed claims.', kpi_billed: 'Total billed', kpi_billed_hint: 'Sum billed.',
        kpi_received: 'Received', kpi_received_hint: 'Paid claims.', kpi_glosa: 'Denied', kpi_glosa_hint: 'Denied amount.',
        kpi_open: 'Outstanding', kpi_open_hint: 'Awaiting payment.', rate_of_billed: ':percent of billed',
        by_covenant: 'Consolidated by insurer', col_covenant: 'Insurer', col_billed: 'Billed', col_received: 'Received', col_glosa: 'Denied',
        col_open: 'Outstanding', col_glosa_rate: '% Denied', col_received_rate: '% Received',
        claims_count_one: ':count claim', claims_count_other: ':count claims',
        inactive_badge: 'Inactive', inactive_hint: 'Insurer removed from the registry.',
        glosa_alert_badge: 'High', glosa_alert_hint: 'Denials above :threshold of billed.',
        glosa_alert_legend: '"High" = denials above :threshold of billed.', footer_total: 'Total', no_data: 'No billed claims.',
        toggle_hint: 'Select an insurer to see its claims.', claims_title: ':covenant claims in the period', claims_loading: 'Loading claims…',
        claims_error: 'Could not load the claims.', claims_retry: 'Try again', claims_empty: 'No claims.', claims_close: 'Close',
        claims_privacy_note: 'Patient shown by code and initials.', col_guide: 'Claim', col_attendance_date: 'Attendance date',
        col_patient: 'Patient (code · initials)', col_status: 'Status', col_value: 'Amount', view_in_billing: 'View in billing',
        view_glosas: 'View denials', pagination_label: 'Claims pagination', pagination_previous: 'Previous page', pagination_next: 'Next page',
        pagination_status: 'Page :current of :last',
    },
    shared: {
        period: {
            label: 'Period', from: 'From', to: 'To', invalid_range: 'Start must be before end.', invalid_date: 'Invalid date.', after_max: 'Not after :date.',
            presets: { today: 'Today', yesterday: 'Yesterday', last7: 'Last 7 days', month: 'This month', last_month: 'Last month', year: 'This year', custom: 'Custom' },
        },
    },
};

const ROWS = [
    { covenant_id: 'c-uni', covenant: 'UNIMED', inactive: false, claims: 3, amount: 350, paid: 180, denied: 70, open: 100, glosa_rate: 20, received_rate: 51.4, glosa_alert: true },
    { covenant_id: 'c-ame', covenant: 'AMIL', inactive: false, claims: 1, amount: 1000, paid: 1000, denied: 0, open: 0, glosa_rate: 0, received_rate: 100, glosa_alert: false },
    { covenant_id: 'c-old', covenant: 'BRADESCO', inactive: true, claims: 2, amount: 0, paid: 0, denied: 0, open: 0, glosa_rate: null, received_rate: null, glosa_alert: false },
];

function baseProps(overrides = {}) {
    return {
        breadcrumbs: [],
        filters: { from: '2026-09-01', to: '2026-09-26' },
        today: '2026-09-26',
        summary: {
            total_claims: 1234, total_amount: 1350, total_paid: 1180, total_denied: 70, total_open: 100,
            glosa_rate: 5.2, received_rate: 87.4, glosa_alert: false,
        },
        byCovenant: ROWS,
        glosa_alert_threshold: 10,
        routes: { index: '/reports/covenants', export: '/reports/covenants/export', claims: '/reports/covenants/claims' },
        export_formats: ['csv', 'xlsx'],
        t,
        ...overrides,
    };
}

const CLAIMS_PAGE = {
    data: [{ id: 'g1', code: 'GUI-1', attendance_date: '2026-09-05', status: 'paid', patient: 'PAC-0000000001 · J. S.', amount: 200, received: 180, glosa: 20 }],
    meta: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
};

function jsonResponse(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

let wrapper;

beforeEach(() => {
    vi.mocked(router.get).mockReset();
    globalThis.fetch = vi.fn(() => jsonResponse(200, CLAIMS_PAGE));
});
afterEach(() => wrapper?.unmount());

function mountPage(overrides = {}) {
    wrapper = mount(CovenantsReport, { props: baseProps(overrides), attachTo: document.body });

    return wrapper;
}

const clean = (value) => value.replace(/ | /g, ' ');
const text = (el) => clean(el.text());
const brl = (v) => clean(new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const pct = (v, digits = 1) => clean(new Intl.NumberFormat('pt-BR', { style: 'percent', minimumFractionDigits: digits, maximumFractionDigits: digits }).format(v / 100));
const kpiValue = (w, key) => text(w.find(`[data-test="kpi-${key}"]`));
const kpiCard = (w, key) => w.find(`[data-kpi="${key}"] .kpi-card`);
const rowNames = (w) => w.findAll('[data-test="covenant-name"]').map((n) => n.text());
const headerButton = (w, label) => w.findAll('thead th button').find((b) => b.text() === label);
const headerTh = (w, label) => w.findAll('thead th').find((th) => th.text() === label);

describe('Financial/Reports/Covenants — indicadores', () => {
    it('KPIs no idioma, com % do faturado em Recebido/Glosado e dica', () => {
        const w = mountPage();

        expect(kpiValue(w, 'claims')).toBe('1.234');
        expect(kpiValue(w, 'billed')).toBe(brl(1350));
        expect(kpiValue(w, 'received')).toBe(brl(1180));
        expect(kpiCard(w, 'received').text()).toContain(`${pct(87.4)} of billed`);
        expect(kpiValue(w, 'glosa')).toBe(brl(70));
        expect(kpiCard(w, 'glosa').text()).toContain(`${pct(5.2)} of billed`);
        expect(kpiValue(w, 'open')).toBe(brl(100));
        expect(kpiCard(w, 'open').attributes('title')).toBe('Awaiting payment.');
        expect(w.find('[data-test="period-basis"]').text()).toBe('Period by attendance date.');
        expect(w.find('.page-header-total').text()).toBe('Claims: 1.234');
    });

    it('Glosado em alerta só quando existe valor glosado', () => {
        expect(kpiCard(mountPage(), 'glosa').classes()).toContain('border-danger');
        wrapper.unmount();

        const w = mountPage({ summary: { total_claims: 1, total_amount: 100, total_paid: 100, total_denied: 0, total_open: 0, glosa_rate: 0, received_rate: 100 } });
        expect(kpiCard(w, 'glosa').classes()).toContain('border-secondary');
    });
});

describe('Financial/Reports/Covenants — tabela', () => {
    it('ordena por faturado (maior primeiro) com aria-sort e colunas secundárias só em telas grandes', () => {
        const w = mountPage();

        expect(rowNames(w)).toEqual(['AMIL', 'UNIMED', 'BRADESCO']);
        expect(headerTh(w, 'Billed').attributes('aria-sort')).toBe('descending');
        expect(headerTh(w, 'Insurer').attributes('aria-sort')).toBe('none');
        expect(headerTh(w, 'Insurer').attributes('scope')).toBe('col');
        expect(headerButton(w, 'Billed').attributes('title')).toBe('Sort by Billed');
        expect(w.findAll('thead th').map((th) => th.text())).toEqual(['Insurer', 'Billed', 'Received', 'Denied', 'Outstanding', '% Denied', '% Received']);

        for (const label of ['Denied', 'Outstanding', '% Received']) {
            expect(headerTh(w, label).classes()).toEqual(expect.arrayContaining(['d-none', 'd-lg-table-cell']));
        }
        expect(w.find('[data-test="col-open"]').classes()).toEqual(expect.arrayContaining(['d-none', 'd-lg-table-cell']));
    });

    it('ordenação no cliente: nome (A–Z) e % Glosa com percentual indefinido sempre por último', async () => {
        const w = mountPage();

        await headerButton(w, 'Insurer').trigger('click');
        expect(rowNames(w)).toEqual(['AMIL', 'BRADESCO', 'UNIMED']);
        expect(headerTh(w, 'Insurer').attributes('aria-sort')).toBe('ascending');

        await headerButton(w, '% Denied').trigger('click');
        expect(rowNames(w)).toEqual(['AMIL', 'UNIMED', 'BRADESCO']);

        await headerButton(w, '% Denied').trigger('click');
        expect(headerTh(w, '% Denied').attributes('aria-sort')).toBe('descending');
        expect(rowNames(w)).toEqual(['UNIMED', 'AMIL', 'BRADESCO']);
        expect(router.get).not.toHaveBeenCalled();
    });

    it('linha: guias no plural do idioma, valores, % Glosa com selo "High" em texto e "—" sem faturado', () => {
        const w = mountPage();
        const unimed = w.find('[data-covenant="c-uni"]');
        const amil = w.find('[data-covenant="c-ame"]');
        const bradesco = w.find('[data-covenant="c-old"]');

        expect(unimed.find('[data-test="covenant-claims"]').text()).toBe('3 claims');
        expect(amil.find('[data-test="covenant-claims"]').text()).toBe('1 claim');
        expect(text(unimed.find('[data-test="col-amount"]'))).toBe(brl(350));
        expect(text(unimed.find('[data-test="col-open"]'))).toBe(brl(100));

        const alert = unimed.find('[data-test="glosa-alert"]');
        expect(text(alert)).toBe(`${pct(20)} · High`);
        expect(alert.classes()).toContain('badge-soft-danger');
        expect(alert.attributes('title')).toBe(`Denials above ${pct(10, 0)} of billed.`);
        expect(alert.find('i').attributes('aria-hidden')).toBe('true');

        expect(amil.find('[data-test="glosa-alert"]').exists()).toBe(false);
        expect(text(amil.find('[data-test="col-glosa-rate"]'))).toBe(pct(0));
        expect(bradesco.find('[data-test="col-glosa-rate"]').text()).toBe('—');
        expect(bradesco.find('[data-test="covenant-inactive"]').text()).toBe('Inactive');
        expect(bradesco.find('[data-test="covenant-inactive"]').attributes('title')).toBe('Insurer removed from the registry.');
        expect(w.find('[data-test="glosa-legend"]').text()).toBe(`"High" = denials above ${pct(10, 0)} of billed.`);
    });

    it('primeira coluna fixa no cabeçalho, nas linhas e no rodapé de totais', () => {
        const w = mountPage();

        expect(headerTh(w, 'Insurer').classes()).toContain('covenants-table__sticky');
        expect(w.find('[data-covenant="c-uni"] th').classes()).toContain('covenants-table__sticky');
        expect(w.find('[data-covenant="c-uni"] th').attributes('scope')).toBe('row');

        const totals = w.find('[data-test="covenant-totals"]');
        expect(totals.find('th').classes()).toContain('covenants-table__sticky');
        expect(totals.find('th').text()).toContain('Total');
        expect(totals.find('th').text()).toContain('1.234 claims');
        expect(text(totals.find('[data-test="total-amount"]'))).toBe(brl(1350));
        expect(text(totals.find('[data-test="total-paid"]'))).toBe(brl(1180));
        expect(text(totals.find('[data-test="total-glosa-rate"]'))).toBe(pct(5.2));
        expect(text(totals.find('[data-test="total-received-rate"]'))).toBe(pct(87.4));
    });

    it('estado vazio sem rodapé nem legenda', () => {
        const w = mountPage({ byCovenant: [] });

        expect(w.find('[data-test="covenants-empty"]').text()).toBe('No billed claims.');
        expect(w.find('[data-test="covenant-totals"]').exists()).toBe(false);
        expect(w.find('[data-test="glosa-legend"]').exists()).toBe(false);
    });
});

describe('Financial/Reports/Covenants — guias do convênio (linha expansível)', () => {
    it('abre com aria-expanded/aria-controls e busca as guias do período aplicado', async () => {
        const w = mountPage();
        const toggle = w.find('[data-covenant="c-uni"] [data-test="covenant-toggle"]');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(w.find('[data-test="covenant-detail"]').exists()).toBe(false);

        await toggle.trigger('click');
        await flushPromises();

        expect(toggle.attributes('aria-expanded')).toBe('true');
        const panel = w.find('[data-test="claims-panel"]');
        expect(panel.attributes('id')).toBe(toggle.attributes('aria-controls'));
        expect(fetch).toHaveBeenCalledWith(
            '/reports/covenants/claims?covenant_id=c-uni&from=2026-09-01&to=2026-09-26&page=1',
            expect.objectContaining({ headers: expect.objectContaining({ Accept: 'application/json' }) }),
        );
        expect(panel.find('[data-test="claim-patient"]').text()).toBe('PAC-0000000001 · J. S.');
    });

    it('um convênio aberto por vez; clicar de novo recolhe', async () => {
        const w = mountPage();

        await w.find('[data-covenant="c-uni"] [data-test="covenant-toggle"]').trigger('click');
        await w.find('[data-covenant="c-ame"] [data-test="covenant-toggle"]').trigger('click');
        await flushPromises();

        expect(w.findAll('[data-test="covenant-detail"]')).toHaveLength(1);
        expect(w.find('[data-covenant="c-uni"] [data-test="covenant-toggle"]').attributes('aria-expanded')).toBe('false');

        await w.find('[data-covenant="c-ame"] [data-test="covenant-toggle"]').trigger('click');
        expect(w.find('[data-test="covenant-detail"]').exists()).toBe(false);
    });

    it('Esc dentro das guias (ou "Close") recolhe e devolve o foco ao convênio', async () => {
        const w = mountPage();
        const toggle = w.find('[data-covenant="c-uni"] [data-test="covenant-toggle"]');

        await toggle.trigger('click');
        await flushPromises();

        await w.find('[data-test="claims-panel"]').trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(w.find('[data-test="covenant-detail"]').exists()).toBe(false);
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(toggle.element);

        await toggle.trigger('click');
        await flushPromises();
        await w.find('[data-test="claims-close"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-test="covenant-detail"]').exists()).toBe(false);
        expect(document.activeElement).toBe(toggle.element);
    });

    it('Esc no próprio botão do convênio aberto também recolhe', async () => {
        const w = mountPage();
        const toggle = w.find('[data-covenant="c-ame"] [data-test="covenant-toggle"]');

        await toggle.trigger('click');
        await toggle.trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(w.find('[data-test="covenant-detail"]').exists()).toBe(false);
    });

    it('troca de período sem o convênio aberto fecha a linha', async () => {
        const w = mountPage();

        await w.find('[data-covenant="c-old"] [data-test="covenant-toggle"]').trigger('click');
        await flushPromises();
        await w.setProps({ byCovenant: ROWS.slice(0, 2), filters: { from: '2026-08-01', to: '2026-08-31' } });
        await nextTick();

        expect(w.find('[data-test="covenant-detail"]').exists()).toBe(false);
    });
});

describe('Financial/Reports/Covenants — período e exportação', () => {
    it('PeriodFilter aplica na URL; período invertido não recarrega', async () => {
        const w = mountPage();

        await w.find('[data-test="period-from"]').setValue('2026-08-15');
        expect(router.get).toHaveBeenCalledWith('/reports/covenants', { from: '2026-08-15', to: '2026-09-26' }, expect.objectContaining({ replace: true }));

        vi.mocked(router.get).mockClear();
        await w.find('[data-test="period-from"]').setValue('2026-10-15');

        expect(router.get).not.toHaveBeenCalled();
        expect(w.find('[data-test="period-error"]').text()).toContain('Start must be before end.');
    });

    it('exporta CSV e Excel do período aplicado', () => {
        const w = mountPage();
        const links = w.findAll('a[data-format]');

        expect(links.map((a) => a.attributes('data-format'))).toEqual(['csv', 'xlsx']);
        expect(links[0].attributes('href')).toBe('/reports/covenants/export?from=2026-09-01&to=2026-09-26&format=csv');
        expect(w.find('.dd').attributes('data-title')).toBe('Export the applied period (01/09/2026 to 26/09/2026)');
    });
});
