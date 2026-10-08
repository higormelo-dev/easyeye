import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import SettingsExamKits from '@/Pages/Panel/Settings/ExamKits/Index.vue';
import ManagerExamKits from '@/Pages/Panel/Manager/ExamKits/Index.vue';

/**
 * Configurações › Kits de exames (Fase 2 / B2) e manager: kits da clínica
 * com convênios ("todos" quando vazio), cópia do padrão (desabilitada se já
 * copiado), editar/excluir; no manager, exclusão com justificativa.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: reactive({ flash: {}, errors: {}, t_ui: {} }) }),
        router: { reload: vi.fn(), visit: vi.fn() },
        Link: { template: '<a><slot /></a>' },
        Head: { template: '<div />' },
    };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title', 'subtitle'], template: '<header><h1>{{ title }}</h1><slot name="actions" /></header>' },
}));

enableAutoUnmount(afterEach);

const T = {
    kits: {
        covenants_all: 'Todos os convênios',
        copy: 'Copiar para a clínica',
        already_copied: 'Já copiado',
        delete: 'Excluir',
    },
};
const GLOBAL = [
    { id: 'g1', name: 'Glaucoma', items: [{ id: 'i1', name: 'Campimetria', tuss_code: '40103137' }], copied: true },
    { id: 'g2', name: 'Ceratocone', items: [{ id: 'i2', name: 'Topografia', tuss_code: '41301080' }], copied: false },
];
const CLINIC = [
    {
        id: 'c1',
        name: 'Glaucoma UNIMED',
        active: true,
        source_kit_id: 'g1',
        items: [{ id: 'i3', name: 'Campimetria', tuss_code: '40103137' }],
        covenants: [{ id: 'cv1', name: 'UNIMED', active: true }],
    },
    {
        id: 'c2',
        name: 'Pré-op',
        active: false,
        items: [{ id: 'i4', name: 'Risco cirúrgico', tuss_code: null }],
        covenants: [],
    },
];

let calls;
beforeEach(() => {
    calls = [];
    globalThis.route = vi.fn((name, id) => `/${name}${id ? `/${id}` : ''}`);
    globalThis.fetch = vi.fn((url, init = {}) => {
        calls.push({ url, method: init.method, body: init.body ? JSON.parse(init.body) : null });
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ message: 'ok' }) });
    });
    router.reload.mockClear();
});

describe('Configurações › Kits de exames', () => {
    const mountPage = () =>
        mount(SettingsExamKits, {
            props: { clinicKits: CLINIC, globalKits: GLOBAL, covenants: [{ id: 'cv1', name: 'UNIMED' }], t: T },
            global: { stubs: { teleport: true, ExamKitEditor: true } },
        });

    it('mostra convênios do kit ou "Todos os convênios", e o código TUSS (ou sem código)', () => {
        const w = mountPage();
        expect(w.find('[data-testid="clinic-kit-c1"]').text()).toContain('UNIMED');
        expect(w.find('[data-testid="clinic-kit-c2"]').text()).toContain('Todos os convênios');
        expect(w.find('[data-testid="clinic-kit-c2"]').text()).toContain('sem código');
    });

    it('padrão já copiado fica desabilitado; copiar chama o servidor e recarrega', async () => {
        const w = mountPage();
        expect(w.find('[data-testid="copy-kit-g1"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="copy-kit-g1"]').text()).toContain('Já copiado');

        await w.find('[data-testid="copy-kit-g2"]').trigger('click');
        await flushPromises();
        expect(calls[0]).toMatchObject({ url: '/panel.setting.exam-kits.copy/g2', method: 'POST' });
        expect(router.reload).toHaveBeenCalled();
    });

    it('excluir pede confirmação antes de chamar o servidor', async () => {
        const w = mountPage();
        await w.find('[data-testid="clinic-kit-c2"] .btn-outline-danger').trigger('click');
        expect(calls).toHaveLength(0);
        await w.find('[data-testid="clinic-kit-c2"] .btn-danger').trigger('click');
        await flushPromises();
        expect(calls[0]).toMatchObject({ url: '/panel.setting.exam-kits.destroy/c2', method: 'DELETE' });
    });
});

describe('Manager › Kits padrão', () => {
    it('exclusão vai com a justificativa', async () => {
        const w = mount(ManagerExamKits, {
            props: { kits: GLOBAL, t: T },
            global: { stubs: { teleport: true, ExamKitEditor: true, ConfirmationWithReasonModal: true } },
        });
        await w.find('[data-testid="manager-kit-g2"] .btn-outline-danger').trigger('click');
        await w
            .findComponent({ name: 'ConfirmationWithReasonModal' })
            .vm.$emit('confirm', 'Kit revisado pela equipe médica do SaaS');
        await flushPromises();
        expect(calls[0]).toMatchObject({
            url: '/manager.exam-kits.destroy/g2',
            method: 'DELETE',
            body: { reason: 'Kit revisado pela equipe médica do SaaS' },
        });
    });
});
