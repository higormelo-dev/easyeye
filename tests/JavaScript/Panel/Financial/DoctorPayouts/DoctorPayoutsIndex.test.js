import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import DoctorPayoutsIndex from '@/Pages/Panel/Financial/DoctorPayouts/Index.vue';
import { t, doctors, itemRows, paginator, brl } from './fixtures.js';

/**
 * Apuração do repasse: sem médico → orientação; com médico → indicadores,
 * resumo por tipo com total, itens (regra, status com atalho ao fechamento,
 * alertas), exportação do período aplicado, filtros na URL e "Fechar
 * período" liberado só quando a prévia do servidor permite.
 */

const inertia = vi.hoisted(() => ({ pageProps: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {}, t_ui: { close: 'Close' } });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title', 'breadcrumbs'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { props: ['title'], template: '<div><h4>{{ title }}</h4><slot name="actions" /></div>' } }));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { props: ['data'], template: '<nav class="pagination-stub" />' } }));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd" :data-title="title"><slot name="trigger" /><ul><slot /></ul></div>' },
}));
vi.mock('@/Components/Panel/PeriodFilter.vue', () => ({
    default: {
        props: ['from', 'to', 'today', 'max', 'labels', 'disabled'],
        emits: ['change'],
        template: `<button type="button" class="period-stub" :data-from="from" :data-to="to" :data-max="max"
            @click="$emit('change', { from: '2026-08-01', to: '2026-08-31', preset: 'last_month' })" />`,
    },
}));
vi.mock('@/Pages/Panel/Financial/DoctorPayouts/ClosePeriodModal.vue', () => ({
    default: {
        props: ['open', 'preview', 'doctorId', 'doctor', 'action'],
        emits: ['close'],
        template: '<div class="close-modal-stub" :data-open="String(open)" :data-doctor="doctorId" :data-action="action" />',
    },
}));

const routes = {
    index: '/doctor-payouts',
    export: '/doctor-payouts/export',
    close: '/doctor-payouts/closings',
    rules: '/doctor-payouts/rules',
    closing_show: '/doctor-payouts/closings/__ID__',
};

const tabs = { apuracao: '/doctor-payouts', closings: '/doctor-payouts/closings', rules: '/doctor-payouts/rules' };

const filters = { doctor: 'd1', from: '2026-09-01', to: '2026-09-27', status: '', service_type: '' };

const kpis = { production_count: 3, charged: 750.5, payout_total: 260, paid: 80, to_pay: 0, pending: 180, no_rule: 1 };

const summary = [
    { service_type: 'consultation', count: 2, charged: 300, payout: 260 },
    { service_type: 'exam', count: 0, charged: 0, payout: 0 },
    { service_type: 'procedure', count: 1, charged: 450.5, payout: 0 },
];

const openPreview = {
    period_start: '2026-09-01', period_end: '2026-09-27', count: 2, charged_cents: 75050, payout_cents: 18000,
    blocking: 0, warnings: {}, can_close: true,
};

let wrapper;

function mountPage(props = {}) {
    wrapper = mount(DoctorPayoutsIndex, {
        props: {
            breadcrumbs: [],
            tabs,
            filters,
            period_capped: null,
            today: '2026-09-28',
            options: { doctors },
            selected_doctor: doctors[0],
            kpis,
            summary,
            items: paginator(itemRows),
            close_preview: openPreview,
            routes,
            t,
            shared: { period: {} },
            ...props,
        },
    });

    return wrapper;
}

const closeButton = (w) => w.find('[data-test="close-open"]');

beforeEach(() => {
    inertia.pageProps.flash = {};
    vi.mocked(router.get).mockReset();
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('Financial/DoctorPayouts/Index — apuração', () => {
    it('sem médico: orienta a escolher, sem indicadores, exportação ou "Fechar período"', () => {
        const w = mountPage({ filters: { ...filters, doctor: '' }, selected_doctor: null, kpis: null, summary: null, items: null, close_preview: null });

        expect(w.find('[data-test="select-doctor"]').text()).toContain('Select a doctor');
        expect(w.find('[data-test="select-doctor"]').text()).toContain('Choose the doctor and the period.');
        expect(w.find('[data-test="kpis"]').exists()).toBe(false);
        expect(w.find('[data-test="items"]').exists()).toBe(false);
        expect(closeButton(w).exists()).toBe(false);
        expect(w.find('.dd').exists()).toBe(false);
        expect(w.find('.close-modal-stub').exists()).toBe(false);

        // Médico inativo aparece com o aviso; status/tipo só com médico escolhido.
        const options = w.findAll('[data-test="filter-doctor"] option').map((o) => o.text());
        expect(options).toEqual(['Select the doctor', 'Dra. Ana Lima', 'Dr. Beto Reis (inactive)']);
        expect(w.find('[data-test="filter-status"]').exists()).toBe(false);
        expect(w.find('[data-test="filter-type"]').exists()).toBe(false);
    });

    it('com médico: indicadores formatados e "sem regra" em destaque com atalho para as regras', () => {
        const w = mountPage();

        expect(w.find('[data-test="kpi-production"]').text()).toBe('3');
        expect(w.find('[data-kpi="production"]').text()).toContain('3 acts in the period');
        expect(w.find('[data-test="kpi-charged"]').text()).toBe(brl(750.5));
        expect(w.find('[data-test="kpi-payout_total"]').text()).toBe(brl(260));
        expect(w.find('[data-test="kpi-paid"]').text()).toBe(brl(80));
        expect(w.find('[data-test="kpi-to_pay"]').text()).toBe(brl(0));
        expect(w.find('[data-test="kpi-pending"]').text()).toBe(brl(180));
        expect(w.find('[data-test="kpi-no_rule"]').text()).toBe('1');

        const noRule = w.find('[data-kpi="no_rule"] a');
        expect(noRule.attributes('href')).toBe('/doctor-payouts/rules');
        expect(noRule.classes()).toContain('border-danger');
    });

    it('resumo por tipo com linha de total (somas em centavos)', () => {
        const w = mountPage();

        const rows = w.findAll('[data-test="summary-row"]');
        expect(rows.map((r) => r.find('th').text())).toEqual(['Consultations', 'Exams', 'Procedures and surgeries']);
        expect(rows[2].text()).toContain(brl(450.5));

        const total = w.find('[data-test="summary-total"]');
        expect(total.text()).toContain('Total');
        expect(total.text()).toContain('3');
        expect(total.text()).toContain(brl(750.5));
        expect(total.text()).toContain(brl(260));
    });

    it('itens: regra aplicada, pagador, origem do valor, alertas e status com atalho ao fechamento', () => {
        const w = mountPage();

        const rows = w.findAll('[data-test="item-row"]');
        expect(rows).toHaveLength(3);

        expect(rows[0].text()).toContain('Maria Souza');
        expect(rows[0].text()).toContain('P0001');
        expect(rows[0].text()).toContain('Private pay');
        expect(rows[0].find('[data-test="item-rule"]').text()).toBe('60% of the charged amount');
        expect(rows[0].find('[data-test="item-payout"]').text()).toBe(brl(180));
        expect(rows[0].find('[data-status="pending"]').text()).toBe('Pending');

        expect(rows[1].text()).toContain('Unimed');
        expect(rows[1].find('[data-test="item-rule"]').text()).toBe('No rule');
        expect(rows[1].find('[data-base-source="table"]').attributes('title')).toBe('From the price table.');
        const warning = rows[1].find('[data-warning="no_rule"]');
        expect(warning.text()).toContain('No rule');
        expect(warning.attributes('title')).toBe('Create a rule to close.');

        expect(rows[2].find('[data-test="item-rule"]').text()).toBe(`${brl(80)} fixed`);
        const closing = rows[2].find('[data-test="item-closing-link"]');
        expect(closing.attributes('href')).toBe('/doctor-payouts/closings/po1');
        expect(closing.text()).toContain('Paid');
        expect(closing.text()).toContain('Closing RM-000001');
    });

    it('sem itens no período: mensagem de vazio na tabela', () => {
        const w = mountPage({ items: paginator([]) });

        expect(w.find('[data-test="items-empty"]').text()).toBe('No attendance in the period.');
    });

    it('prévia liberada: "Fechar período" abre a confirmação com o médico e a rota de fechamento', async () => {
        const w = mountPage();

        expect(closeButton(w).attributes('disabled')).toBeUndefined();
        expect(w.find('.close-modal-stub').attributes('data-open')).toBe('false');

        await closeButton(w).trigger('click');

        const modal = w.find('.close-modal-stub');
        expect(modal.attributes('data-open')).toBe('true');
        expect(modal.attributes('data-doctor')).toBe('d1');
        expect(modal.attributes('data-action')).toBe('/doctor-payouts/closings');
    });

    it('itens sem regra: botão desabilitado, aviso com a contagem e atalho para as regras', async () => {
        const w = mountPage({ close_preview: { ...openPreview, blocking: 3, can_close: false } });

        expect(closeButton(w).attributes('disabled')).toBeDefined();

        const blocked = w.find('[data-test="close-blocked"]');
        expect(blocked.text()).toContain('3 item(s) without a payout rule.');
        expect(blocked.find('[data-test="go-to-rules"]').attributes('href')).toBe('/doctor-payouts/rules');
        expect(closeButton(w).attributes('aria-describedby')).toBe(blocked.attributes('id'));

        await closeButton(w).trigger('click');
        expect(w.find('.close-modal-stub').attributes('data-open')).toBe('false');
    });

    it('nada pendente: botão desabilitado com a explicação', () => {
        const w = mountPage({ close_preview: { ...openPreview, count: 0, payout_cents: 0, can_close: false } });

        expect(closeButton(w).attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="close-blocked"]').exists()).toBe(false);
        expect(w.find('[data-test="close-hint"]').text()).toBe('There are no pending items in this period.');
    });

    it('trocar médico, período, status ou tipo visita a URL com o conjunto completo de filtros', async () => {
        const w = mountPage();

        await w.find('[data-test="filter-doctor"]').setValue('d2');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            { doctor: 'd2', from: '2026-09-01', to: '2026-09-27', status: '', service_type: '' },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );

        await w.find('.period-stub').trigger('click');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            { doctor: 'd1', from: '2026-08-01', to: '2026-08-31', status: '', service_type: '' },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );

        await w.find('[data-test="filter-status"]').setValue('paid');
        expect(router.get).toHaveBeenLastCalledWith('/doctor-payouts', expect.objectContaining({ doctor: 'd1', status: 'paid' }), expect.any(Object));

        await w.find('[data-test="filter-type"]').setValue('exam');
        expect(router.get).toHaveBeenLastCalledWith('/doctor-payouts', expect.objectContaining({ service_type: 'exam' }), expect.any(Object));
    });

    it('o período não aceita datas futuras (máximo = hoje do servidor)', () => {
        const w = mountPage();

        expect(w.find('.period-stub').attributes('data-max')).toBe('2026-09-28');
    });

    it('limpar filtros da lista mantém médico e período', async () => {
        const w = mountPage({ filters: { ...filters, status: 'pending', service_type: 'exam' } });

        await w.find('[data-test="filters-clear"]').trigger('click');

        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            { doctor: 'd1', from: '2026-09-01', to: '2026-09-27', status: '', service_type: '' },
            expect.any(Object),
        );
    });

    it('exportação: CSV e Excel com médico e período aplicados', () => {
        const w = mountPage();

        const links = w.findAll('.dd a').map((a) => a.attributes('href'));
        expect(links).toEqual([
            '/doctor-payouts/export?doctor=d1&from=2026-09-01&to=2026-09-27&format=csv',
            '/doctor-payouts/export?doctor=d1&from=2026-09-01&to=2026-09-27&format=xlsx',
        ]);
        expect(w.find('.dd').text()).toContain('CSV spreadsheet');
        expect(w.find('.dd').text()).toContain('Excel spreadsheet');
    });

    it('período acima do limite: aviso com as datas pedidas e as mostradas', () => {
        const w = mountPage({ period_capped: { requested_from: '2024-01-01', requested_to: '2026-09-27', max_days: 366 } });

        expect(w.find('[data-test="period-capped"]').text())
            .toBe('Requested 01/01/2024 to 27/09/2026 exceeds 366 days. Showing 01/09/2026 to 27/09/2026.');
    });

    it('abas: a apuração é a página atual', () => {
        const w = mountPage();

        expect(w.find('[data-test="tab-apuracao"]').attributes('aria-current')).toBe('page');
        expect(w.find('[data-test="tab-closings"]').attributes('aria-current')).toBeUndefined();
        expect(w.find('[data-test="tab-rules"]').attributes('href')).toBe('/doctor-payouts/rules');
    });

    it('mostra o retorno do servidor (flash.message) e o fecha com estado local', async () => {
        inertia.pageProps.flash = { message: 'Payout closed: RM-000002.' };
        const w = mountPage();

        const alert = w.find('[data-test="flash-message"]');
        expect(alert.text()).toContain('Payout closed: RM-000002.');

        await alert.find('button[aria-label="Close"]').trigger('click');
        expect(w.find('[data-test="flash-message"]').exists()).toBe(false);
    });
});
