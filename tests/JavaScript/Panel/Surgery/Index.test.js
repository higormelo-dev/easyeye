import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __router } = await import('@inertiajs/vue3');
const Index = (await import('@/Pages/Panel/Surgery/Index.vue')).default;

const layout = { template: '<div><slot /></div>' };

const t = {
    title: 'Cirurgias',
    subtitle: 'Do pedido à alta',
    ui: {
        index: {
            new: 'Nova cirurgia',
            filter_label: 'Filtrar',
            group_all: 'Todas',
            group_draft: 'Rascunhos',
            group_awaiting: 'Aguardando',
            group_authorized: 'Autorizadas',
            group_scheduled: 'Agendadas',
            group_performed: 'Realizadas',
            group_closed: 'Encerradas',
            search_label: 'Buscar',
            search_placeholder: 'Paciente',
            surgeon_filter: 'Cirurgião',
            all_surgeons: 'Todos',
            empty: 'Nenhuma cirurgia por aqui',
            empty_hint: 'Crie o pedido',
            urgent: 'Urgência',
            open: 'Abrir',
            col_code: 'Código',
            col_patient: 'Paciente',
            col_surgery: 'Cirurgia',
            col_surgeon: 'Cirurgião',
            col_status: 'Situação',
            col_authorization: 'Autorização',
            col_date: 'Data',
            col_actions: 'Ações',
            showing: 'Exibindo',
            of: 'de',
            suffix: 'cirurgias',
        },
    },
};

const row = (over = {}) => ({
    id: 'c1',
    code: 'CIR-1',
    title: 'Catarata',
    eye: { value: 'od', label: 'OD' },
    status: { value: 'draft', label: 'Rascunho', tone: 'secondary' },
    authorization: { status: 'not_requested', label: 'Não solicitada', expired: false },
    patient: { name: 'MARIA' },
    surgeon: { name: 'DR. A' },
    scheduled_start: null,
    urgent: false,
    ...over,
});

const props = (over = {}) => ({
    cases: { data: [row()], last_page: 1 },
    counts: { all: 3, draft: 1, awaiting: 1, authorized: 0, scheduled: 1, performed: 0, closed: 0 },
    filters: { group: 'all', q: '', surgeon: '' },
    options: {
        surgeons: [
            { id: 'd1', name: 'DR. A' },
            { id: 'd2', name: 'DR. B' },
        ],
    },
    can: { manage: true },
    doctorMissing: false,
    doctorMissingText: '',
    urls: { index: '/panel/surgery' },
    t,
    ...over,
});

const mountIndex = (p) =>
    mount(Index, {
        props: p,
        global: {
            mocks: { route: globalThis.route },
            stubs: {
                AppLayout: layout,
                CaseFormModal: { template: '<div data-test="form" :data-open="open" />', props: ['open'] },
            },
        },
    });

beforeEach(() => {
    __router.get.mockClear();
    vi.useRealTimers();
});

describe('Surgery Index', () => {
    it('mostra cada grupo com a contagem do servidor e marca o ativo', () => {
        const wrapper = mountIndex(props({ filters: { group: 'awaiting', q: '', surgeon: '' } }));

        expect(wrapper.find('[data-testid="surgery-group-draft"]').text()).toContain('1');
        expect(wrapper.find('[data-testid="surgery-group-all"]').text()).toContain('3');
        expect(wrapper.find('[data-testid="surgery-group-awaiting"]').attributes('aria-current')).toBe('true');
    });

    it('trocar o grupo refaz a busca só com o necessário (e volta à 1ª página)', async () => {
        const wrapper = mountIndex(props());

        await wrapper.find('[data-testid="surgery-group-scheduled"]').trigger('click');

        expect(__router.get).toHaveBeenCalledWith(
            '/panel/surgery',
            { group: 'scheduled', q: undefined, surgeon: undefined, page: undefined },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('a busca espera o usuário parar de digitar', async () => {
        vi.useFakeTimers();
        const wrapper = mountIndex(props());

        await wrapper.find('[data-testid="surgery-search"]').setValue('mar');
        expect(__router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        expect(__router.get).toHaveBeenCalledTimes(1);
        expect(__router.get.mock.calls[0][1].q).toBe('mar');
    });

    it('lista vazia mostra o estado vazio, não a tabela', () => {
        const wrapper = mountIndex(props({ cases: { data: [], last_page: 1 } }));

        expect(wrapper.find('[data-testid="surgery-empty"]').exists()).toBe(true);
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('perfil médico sem cadastro vê o aviso e nenhuma lista nem botão de criar', () => {
        const wrapper = mountIndex(props({ doctorMissing: true, doctorMissingText: 'Sem cadastro de médico' }));

        expect(wrapper.text()).toContain('Sem cadastro de médico');
        expect(wrapper.find('[data-testid="surgery-new"]').exists()).toBe(false);
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('só quem gerencia vê "Nova cirurgia"', async () => {
        expect(
            mountIndex(props({ can: { manage: false } }))
                .find('[data-testid="surgery-new"]')
                .exists(),
        ).toBe(false);

        const wrapper = mountIndex(props());
        await wrapper.find('[data-testid="surgery-new"]').trigger('click');

        expect(wrapper.find('[data-test="form"]').attributes('data-open')).toBe('true');
    });

    it('urgência aparece em texto e a autorização vencida em vermelho', () => {
        const wrapper = mountIndex(
            props({
                cases: {
                    data: [
                        row({ urgent: true, authorization: { status: 'authorized', label: 'Vencida', expired: true } }),
                    ],
                    last_page: 1,
                },
            }),
        );

        expect(wrapper.find('[data-testid="surgery-row"]').text()).toContain('Urgência');
        expect(wrapper.find('[data-testid="surgery-row"] .text-bg-danger').exists()).toBe(true);
    });
});
