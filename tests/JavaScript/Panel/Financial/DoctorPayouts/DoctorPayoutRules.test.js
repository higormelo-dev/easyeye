import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import DoctorPayoutRules from '@/Pages/Panel/Financial/DoctorPayouts/Rules.vue';
import { t, doctors, paginator } from './fixtures.js';

/**
 * Regras de repasse: política "médicos veem os próprios repasses" (só admin
 * altera), filtros na URL (todas / gerais / de um médico, tipo, situação),
 * tabela × cards persistido, rótulos de escopo/cálculo/vigência e exclusão
 * com confirmação.
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

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel', 'view'],
        emits: ['set-view'],
        template: `<div><span class="total">{{ totalLabel }} {{ total }}</span>
            <button type="button" class="to-cards" @click="$emit('set-view', 'cards')" /><slot name="actions" /></div>`,
    },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { props: ['data'], template: '<nav class="pagination-stub" />' } }));
vi.mock('@/Pages/Panel/Financial/DoctorPayouts/RuleFormModal.vue', () => ({
    default: {
        props: ['open', 'rule', 'options', 'routes'],
        emits: ['close'],
        template: '<div class="rule-modal-stub" :data-open="String(open)" :data-rule="rule?.id ?? \'\'" />',
    },
}));

const routes = {
    index: '/doctor-payouts/rules',
    store: '/doctor-payouts/rules',
    update: '/doctor-payouts/rules/__ID__',
    destroy: '/doctor-payouts/rules/__ID__',
    settings: '/doctor-payouts/settings',
};

const RULES = [
    {
        id: 'r1', doctor_id: null, doctor_name: null, service_type: 'consultation', item_kind: null, item_id: null, item_name: null,
        payer_scope: 'any', covenant_id: null, covenant_name: null, calculation: 'percentage', percentage: 60, fixed_amount: null,
        valid_from: null, valid_until: null, active: true, notes: null, is_general: true,
    },
    {
        id: 'r2', doctor_id: 'd1', doctor_name: 'Dra. Ana Lima', service_type: 'procedure', item_kind: 'procedure', item_id: 'pr1',
        item_name: 'Facectomia', payer_scope: 'covenant', covenant_id: 'c1', covenant_name: 'Unimed', calculation: 'fixed',
        percentage: null, fixed_amount: 350, valid_from: '2026-01-01', valid_until: '2026-12-31', active: false, notes: 'Pacote', is_general: false,
    },
];

let wrapper;

function mountPage(props = {}) {
    wrapper = mount(DoctorPayoutRules, {
        props: {
            breadcrumbs: [],
            tabs: { apuracao: '/doctor-payouts', closings: '/doctor-payouts/closings', rules: '/doctor-payouts/rules' },
            rules: paginator(RULES),
            filters: { doctor: '', service_type: '', status: '' },
            options: { doctors, visit_types: [], procedures: [], exam_types: [], covenants: [] },
            settings: { doctor_payouts_visible: false, can_manage: true },
            routes,
            t,
            shared: {},
            ...props,
        },
    });

    return wrapper;
}

beforeEach(() => {
    window.localStorage.clear();
    inertia.pageProps.flash = {};
    vi.mocked(router.get).mockReset();
    vi.mocked(router.patch).mockReset();
    vi.mocked(router.delete).mockReset();
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.unstubAllGlobals();
});

describe('Financial/DoctorPayouts/Rules', () => {
    it('título, total, introdução e aba atual', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Payout rules');
        expect(w.find('.total').text()).toBe('Total: 2');
        expect(w.find('[data-test="rules-intro"]').text()).toBe('The most specific rule wins.');
        expect(w.find('[data-test="tab-rules"]').attributes('aria-current')).toBe('page');
    });

    it('admin liga "médicos veem os próprios repasses" com PATCH na configuração', async () => {
        const w = mountPage();

        const toggle = w.find('[data-test="settings-visible"]');
        expect(toggle.attributes('role')).toBe('switch');
        expect(toggle.attributes('disabled')).toBeUndefined();
        expect(w.find('[data-test="settings-admin-only"]').exists()).toBe(false);

        await toggle.setValue(true);

        expect(router.patch).toHaveBeenCalledWith(
            '/doctor-payouts/settings',
            { doctor_payouts_visible: true },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('durante a visita o switch fica travado; ao terminar volta ao valor do servidor (ex.: recusado)', async () => {
        let visit = null;
        vi.mocked(router.patch).mockImplementation((url, data, options) => { visit = options; options.onStart?.(); });
        const w = mountPage();

        const toggle = w.find('[data-test="settings-visible"]');
        await toggle.setValue(true);
        expect(toggle.element.checked).toBe(true);
        expect(toggle.attributes('disabled')).toBeDefined();

        // Visita assíncrona terminou sem mudar a configuração (prop continua false).
        visit.onFinish?.();
        await nextTick();

        expect(toggle.element.checked).toBe(false);
        expect(toggle.attributes('disabled')).toBeUndefined();
    });

    it('quem não é admin vê o switch desabilitado e o aviso', async () => {
        const w = mountPage({ settings: { doctor_payouts_visible: true, can_manage: false } });

        const toggle = w.find('[data-test="settings-visible"]');
        expect(toggle.element.checked).toBe(true);
        expect(toggle.attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="settings-admin-only"]').text()).toBe('Only administrators can perform this operation.');

        await toggle.trigger('change');
        expect(router.patch).not.toHaveBeenCalled();
    });

    it('filtro de médico: todas as regras, gerais (todos os médicos) ou de um médico', async () => {
        const w = mountPage();

        const doctorFilter = w.find('[data-test="filter-doctor"]');
        expect(doctorFilter.findAll('option').map((o) => [o.attributes('value'), o.text()])).toEqual([
            ['', 'All rules'],
            ['general', 'All doctors'],
            ['d1', 'Dra. Ana Lima'],
            ['d2', 'Dr. Beto Reis (inactive)'],
        ]);

        await doctorFilter.setValue('general');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts/rules',
            { doctor: 'general', service_type: '', status: '' },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );

        await w.find('[data-test="filter-status"]').setValue('inactive');
        expect(router.get).toHaveBeenLastCalledWith('/doctor-payouts/rules', expect.objectContaining({ status: 'inactive' }), expect.any(Object));
    });

    it('tabela: escopo, cálculo, vigência e situação por regra', () => {
        const w = mountPage();

        const [general, specific] = w.findAll('[data-test="rule-row"]');
        expect(general.text()).toContain('All doctors');
        expect(general.text()).toContain('Consultation');
        expect(general.text()).toContain('Any item');
        expect(general.text()).toContain('Any payer');
        expect(general.find('[data-test="rule-calculation"]').text()).toBe('60% of the charged amount');
        expect(general.text()).toContain('Always');
        expect(general.text()).toContain('Active');

        expect(specific.text()).toContain('Dra. Ana Lima');
        expect(specific.text()).toContain('Facectomia');
        expect(specific.text()).toContain('Insurance');
        expect(specific.text()).toContain('Unimed');
        expect(specific.find('[data-test="rule-calculation"]').text()).toBe(`${new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(350)} fixed`);
        expect(specific.text()).toContain('01/01/2026 to 31/12/2026');
        expect(specific.text()).toContain('Inactive');
    });

    it('alterna para cards (mesmo paginator) e guarda a preferência', async () => {
        const w = mountPage();

        await w.find('.to-cards').trigger('click');

        expect(w.findAll('[data-test="rule-card"]')).toHaveLength(2);
        expect(w.find('[data-test="rule-row"]').exists()).toBe(false);
        expect(window.localStorage.getItem('doctor_payout_rules_view')).toBe('cards');
    });

    it('sem regras: mensagem de vazio', () => {
        const w = mountPage({ rules: paginator([]) });

        expect(w.find('[data-test="rules-empty"]').text()).toBe('No rules yet.');
    });

    it('excluir confirma com o aviso e apaga pela rota da regra', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('confirm', confirm);
        const w = mountPage();

        await w.findAll('[data-test="rule-delete"]')[1].trigger('click');

        expect(confirm).toHaveBeenCalledWith('Delete this rule?\n\nPast closings do not change.');
        expect(router.delete).toHaveBeenCalledWith('/doctor-payouts/rules/r2', { preserveScroll: true });
    });

    it('excluir cancelado não apaga', async () => {
        vi.stubGlobal('confirm', vi.fn(() => false));
        const w = mountPage();

        await w.findAll('[data-test="rule-delete"]')[0].trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
    });

    it('nova regra e editar abrem o painel', async () => {
        const w = mountPage();

        await w.find('[data-test="rule-new"]').trigger('click');
        expect(w.find('.rule-modal-stub').attributes('data-open')).toBe('true');
        expect(w.find('.rule-modal-stub').attributes('data-rule')).toBe('');

        await w.findAll('[data-test="rule-edit"]')[1].trigger('click');
        expect(w.find('.rule-modal-stub').attributes('data-rule')).toBe('r2');
    });
});
