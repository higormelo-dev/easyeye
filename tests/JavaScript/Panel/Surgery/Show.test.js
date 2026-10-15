import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __router } = await import('@inertiajs/vue3');
const Show = (await import('@/Pages/Panel/Surgery/Show.vue')).default;

const layout = { template: '<div><slot /></div>' };
const modal = {
    template: '<div v-if="open" data-test="modal"><slot name="header" /><slot /><slot name="footer" /></div>',
    props: ['open', 'size'],
};

const t = {
    ui: {
        show: {
            urgent: 'Urgência',
            back: 'Voltar',
            edit: 'Editar pedido',
            resume: 'Retomar',
            suspend: 'Suspender',
            cancel: 'Cancelar cirurgia',
            delete: 'Excluir rascunho',
            delete_confirm: 'Excluir?',
            back_modal: 'Voltar',
            cancel_title: 'Cancelar cirurgia',
            cancel_reason: 'Motivo do cancelamento',
            cancel_confirm: 'Cancelar cirurgia',
            suspend_title: 'Suspender',
            suspend_reason: 'Motivo',
            suspend_confirm: 'Suspender',
            cancelled_banner: 'Cirurgia cancelada.',
            suspended_banner: 'Cirurgia suspensa.',
            authorization_expired: 'A autorização venceu.',
            summary_authorization: 'Autorização',
            summary_valid_until: 'Validade',
            summary_regime: 'Regime',
            summary_anesthesia: 'Anestesia',
            tabs_label: 'Etapas',
            tab_unavailable: 'Etapa indisponível.',
        },
    },
};

const surgicalCase = (over = {}) => ({
    id: 'c1',
    code: 'CIR-0000000001',
    title: 'Facoemulsificação',
    eye: { value: 'od', label: 'Olho direito (OD)' },
    status: { value: 'authorized', label: 'Autorizada', tone: 'info' },
    authorization: {
        status: 'authorized',
        label: 'Autorizada',
        number: 'AUT-1',
        valid_until: '2026-11-30',
        expired: false,
    },
    patient: { id: 'p1', name: 'MARIA SILVA' },
    surgeon: { id: 'd1', name: 'DR. CIRURGIAO' },
    covenant: 'UNIMED',
    regime: 'Ambulatorial',
    urgent: false,
    anesthesia: null,
    scheduled_start: null,
    room: null,
    revision: 3,
    cid_codes: [],
    ...over,
});

const base = (over = {}) => ({
    breadcrumbs: [],
    surgicalCase: surgicalCase(),
    tabs: [
        { key: 'request', label: 'Pedido' },
        { key: 'authorization', label: 'Autorização' },
        { key: 'history', label: 'Histórico' },
    ],
    sections: {
        request: { procedures: [], materials: [], iol_plans: [], exams: [], team: [], safety: {} },
        authorization: {
            state: { label: 'Autorizada' },
            problems: [],
            events: [],
            actions: {},
            items: [],
            channels: [],
        },
        history: { entries: [] },
    },
    options: {},
    can: { manage: true, perform: false, edit: true, cancel: true, suspend: true, resume: false, delete: false },
    urls: {
        index: '/surgery',
        cancel: '/surgery/c1/cancel',
        suspend: '/surgery/c1/suspend',
        resume: '/surgery/c1/resume',
        destroy: '/surgery/c1',
        update: '/surgery/c1',
    },
    t,
    ...over,
});

const mountShow = (props) =>
    mount(Show, {
        props,
        global: {
            mocks: { route: globalThis.route },
            stubs: {
                AppLayout: layout,
                CenteredModal: modal,
                CaseFormModal: { template: '<div data-test="form" :data-open="open" />', props: ['open'] },
            },
        },
    });

beforeEach(() => {
    __router.post.mockClear();
    __router.reload.mockClear();
    window.location.hash = '';
});

describe('Show — abas do módulo', () => {
    it('monta só a primeira aba; as demais entram quando visitadas e ficam montadas', async () => {
        const wrapper = mountShow(base());

        expect(wrapper.find('#surgery-panel-request').exists()).toBe(true);
        expect(wrapper.find('#surgery-panel-authorization').exists()).toBe(false);

        await wrapper.find('[data-testid="surgery-tab-authorization"]').trigger('click');
        expect(wrapper.find('#surgery-panel-authorization').attributes('style') ?? '').not.toContain('display: none');
        expect(wrapper.find('#surgery-panel-request').attributes('style')).toContain('display: none');

        await wrapper.find('[data-testid="surgery-tab-request"]').trigger('click');
        expect(wrapper.find('#surgery-panel-authorization').exists()).toBe(true);
    });

    it('acha o componente da aba pelo nome do arquivo (PostOpTab ↔ postop)', async () => {
        const wrapper = mountShow(base({ tabs: [{ key: 'nada', label: 'Nada' }], sections: { nada: {} } }));

        expect(wrapper.text()).toContain('Etapa indisponível.');
    });

    it('navega entre abas com as setas do teclado', async () => {
        const wrapper = mountShow(base());

        await wrapper.find('[data-testid="surgery-tab-request"]').trigger('keydown', { key: 'ArrowRight' });

        expect(wrapper.find('[data-testid="surgery-tab-authorization"]').attributes('aria-selected')).toBe('true');
    });

    it('Editar da aba Pedido abre o formulário também com o pedido travado (autorizado): equipe e parte clínica seguem editáveis', async () => {
        const wrapper = mountShow(
            base({
                sections: {
                    ...base().sections,
                    request: {
                        ...base().sections.request,
                        can_edit_request: false,
                        can_edit_clinical: true,
                        can_edit_team: true,
                    },
                },
                can: { ...base().can, edit: true },
            }),
        );

        expect(wrapper.find('[data-test="form"]').attributes('data-open')).toBe('false');

        await wrapper.find('[data-testid="request-edit"]').trigger('click');

        expect(wrapper.find('[data-test="form"]').attributes('data-open')).toBe('true');
    });

    it('abre direto na aba do hash (#tab=history)', () => {
        window.location.hash = '#tab=history';
        const wrapper = mountShow(base());

        expect(wrapper.find('[data-testid="surgery-tab-history"]').attributes('aria-selected')).toBe('true');
    });
});

describe('Show — ações de estado', () => {
    it('cancelar pede o motivo e envia com a revisão da tela', async () => {
        const wrapper = mountShow(base());

        await wrapper.find('[data-testid="surgery-cancel"]').trigger('click');
        await wrapper.find('#surgery-reason').setValue('Paciente desistiu');
        const confirm = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Cancelar cirurgia' && b.classes().includes('btn-danger'));
        await confirm.trigger('click');

        expect(__router.post).toHaveBeenCalledWith(
            '/surgery/c1/cancel',
            { reason: 'Paciente desistiu', expected_revision: 3 },
            expect.any(Object),
        );
    });

    it('esconde as ações que o servidor não liberou', () => {
        const wrapper = mountShow(
            base({ can: { manage: true, edit: false, cancel: false, suspend: false, resume: false, delete: false } }),
        );

        for (const id of ['edit', 'cancel', 'suspend', 'resume', 'delete']) {
            expect(wrapper.find(`[data-testid="surgery-${id}"]`).exists()).toBe(false);
        }
    });

    it('caso suspenso mostra o aviso e "Retomar"', () => {
        const wrapper = mountShow(
            base({
                surgicalCase: surgicalCase({
                    status: { value: 'suspended', label: 'Suspensa', tone: 'dark' },
                    suspension_reason: 'Febre',
                }),
                can: { manage: true, edit: false, cancel: true, suspend: false, resume: true, delete: false },
            }),
        );

        expect(wrapper.text()).toContain('Cirurgia suspensa.');
        expect(wrapper.text()).toContain('Febre');
        expect(wrapper.find('[data-testid="surgery-resume"]').exists()).toBe(true);
    });

    it('autorização vencida alerta, exceto depois de realizada', () => {
        const expired = {
            status: 'authorized',
            label: 'Vencida',
            number: null,
            valid_until: '2026-10-01',
            expired: true,
        };

        expect(
            mountShow(base({ surgicalCase: surgicalCase({ authorization: expired }) }))
                .find('[data-testid="surgery-auth-expired"]')
                .exists(),
        ).toBe(true);
        expect(
            mountShow(
                base({
                    surgicalCase: surgicalCase({
                        authorization: expired,
                        status: { value: 'performed', label: 'Realizada', tone: 'success' },
                    }),
                }),
            )
                .find('[data-testid="surgery-auth-expired"]')
                .exists(),
        ).toBe(false);
    });

    it('editar abre o formulário do pedido', async () => {
        const wrapper = mountShow(base());

        expect(wrapper.find('[data-test="form"]').attributes('data-open')).toBe('false');
        await wrapper.find('[data-testid="surgery-edit"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="form"]').attributes('data-open')).toBe('true');
    });
});
