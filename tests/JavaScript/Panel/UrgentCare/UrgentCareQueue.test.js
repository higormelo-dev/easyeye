import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Fase 1 / B1 — Pronto atendimento.
 *
 *  - Fila: ordem do servidor (prioridade, chegada), prioridade com TEXTO (não
 *    só cor), espera ao vivo, ações por perfil (Atender só médico com
 *    cadastro; Chamar só com painel), desistiu/removido com motivo, relógio
 *    limpo ao sair da tela.
 *  - Formulário: motivo sugere prioridade (queimadura química → imediata, com
 *    alerta), idade 60+ sugere a prioridade por lei, escolha manual não é
 *    sobrescrita, "Outro" exige descrição, paciente já na fila não entra de
 *    novo, cadastro rápido com nascimento e homônimo.
 */
const { routerPost, formState } = vi.hoisted(() => ({
    routerPost: vi.fn(),
    formState: { last: null },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: r } = await import('vue');

    return {
        usePage: () => ({ props: { locale: 'pt_BR', t_ui: { close: 'Fechar' } } }),
        router: { post: routerPost, reload: vi.fn(), get: vi.fn(), visit: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        Head: { template: '<div />' },
        useForm: (data) => {
            const form = r({
                ...data,
                errors: {},
                processing: false,
                transform: vi.fn(function (fn) {
                    form._transform = fn;
                    return form;
                }),
                clearErrors: vi.fn(),
                post: vi.fn(),
                put: vi.fn(),
            });
            formState.last = form;
            return form;
        },
    };
});

const UrgentCareIndex = (await import('@/Pages/Panel/UrgentCare/Index.vue')).default;
const UrgentCareFormModal = (await import('@/Pages/Panel/UrgentCare/UrgentCareFormModal.vue')).default;

const t = {
    title: 'Pronto atendimento',
    add: 'Adicionar paciente',
    add_title: 'Adicionar ao pronto atendimento',
    edit_title: 'Editar item da fila',
    summary_queued: 'Na fila',
    summary_immediate: 'Imediatas',
    summary_longest: 'Maior espera',
    live_region: ':count na fila',
    updated_at: 'Atualizado às :time',
    queue_title: 'Fila agora',
    queue_label: 'Fila',
    queue_empty: 'Ninguém na fila do pronto atendimento.',
    queue_empty_hint: 'Quem chegar…',
    closed_title: 'Encerrados hoje',
    closed_empty: 'Nada encerrado hoje.',
    position: ':n.º',
    age_years: ':age anos',
    arrived_at: 'Chegou às :time',
    called_at: 'Chamado às :time',
    called_times: ':count chamadas',
    attended_at: 'Atendido às :time por :doctor',
    closed_at: 'Às :time — :reason',
    minutes_short: ':m min',
    hours_short: ':h h :m min',
    no_doctor: 'Qualquer médico',
    no_covenant: 'Sem convênio',
    action_call: 'Chamar',
    action_call_again: 'Chamar de novo',
    action_attend: 'Atender',
    action_edit: 'Editar',
    action_left: 'Desistiu',
    action_remove: 'Remover da fila',
    action_more: 'Mais ações',
    action_open_record: 'Abrir prontuário',
    attend_staff_hint: 'O médico atende pela tela dele.',
    field_close_reason: 'Motivo',
    close_left_title: 'Paciente desistiu',
    close_removed_title: 'Remover da fila',
    close_confirm: 'Confirmar',
    cancel: 'Cancelar',
    field_patient: 'Paciente',
    field_reason: 'Motivo',
    field_reason_details: 'Descrição da queixa',
    field_priority: 'Prioridade',
    field_doctor: 'Médico preferido',
    field_covenant: 'Convênio',
    patient_search_ph: 'Nome…',
    patient_none: 'Nenhum paciente encontrado.',
    patient_new: 'Cadastrar paciente',
    patient_change: 'Trocar paciente',
    patient_already: 'Já está na fila',
    quick_title: 'Cadastro rápido',
    quick_name: 'Nome',
    quick_cellphone: 'Celular',
    quick_birth_date: 'Nascimento',
    quick_save: 'Cadastrar e selecionar',
    quick_homonym_confirm: 'Confirmo que é outro paciente',
    priority_suggested: 'Prioridade sugerida pelo motivo.',
    priority_suggested_age: 'Paciente com :age anos: prioridade por lei sugerida.',
    chemical_burn_alert: 'Queimadura química: avise o médico agora.',
    reason_details_required: 'Descreva a queixa (obrigatório em "Outro").',
    save: 'Salvar',
    saving: 'Salvando…',
};

const reasons = [
    { value: 1, key: 'ocular_trauma', label: 'Trauma ocular', suggested_priority: 1 },
    { value: 3, key: 'chemical_burn', label: 'Queimadura química', suggested_priority: 1 },
    { value: 4, key: 'red_eye', label: 'Olho vermelho', suggested_priority: null },
    { value: 99, key: 'other', label: 'Outro', suggested_priority: null },
];
const priorities = [
    { value: 1, key: 'immediate', label: 'Imediata', hint: 'Risco à visão' },
    { value: 2, key: 'legal', label: 'Prioritária por lei', hint: 'Lei 10.048/2000' },
    { value: 3, key: 'normal', label: 'Normal', hint: 'Ordem de chegada' },
];

const NOW = new Date('2026-10-06T10:00:00');

function row(overrides = {}) {
    return {
        id: 'e1',
        position: 1,
        patient_id: 'p1',
        patient_name: 'MARIA',
        age: 40,
        reason: 4,
        reason_label: 'Olho vermelho',
        reason_details: null,
        priority: 3,
        priority_key: 'normal',
        priority_label: 'Normal',
        status_key: 'waiting',
        preferred_doctor: null,
        covenant: null,
        arrived_at: new Date(NOW.getTime() - 25 * 60_000).toISOString(),
        arrived_time: '09:35',
        call_count: 0,
        ...overrides,
    };
}

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    PageHeader: { props: ['title', 'subtitle'], template: '<header><slot name="actions" /></header>' },
    Teleport: true,
};

function mountIndex(props = {}) {
    return mount(UrgentCareIndex, {
        props: {
            queue: [],
            closed: [],
            reasons,
            priorities,
            canAttend: false,
            callPanelEnabled: false,
            urls: { store: '/panel/urgent-care', patient_search: '/panel/patients/search', patient_quick: '/q' },
            t,
            ...props,
        },
        global: { stubs },
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
    routerPost.mockReset();
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve([]) }));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Pronto atendimento — fila', () => {
    it('mostra a fila na ordem do servidor, prioridade com texto e a espera de cada um', () => {
        const wrapper = mountIndex({
            queue: [
                row({
                    id: 'a',
                    position: 1,
                    priority_key: 'immediate',
                    priority_label: 'Imediata',
                    patient_name: 'QUEIMADURA',
                }),
                row({
                    id: 'b',
                    position: 2,
                    priority_key: 'legal',
                    priority_label: 'Prioritária por lei',
                    patient_name: 'IDOSO',
                    age: 72,
                }),
                row({ id: 'c', position: 3, patient_name: 'NORMAL' }),
            ],
        });

        const rows = wrapper.findAll('[data-testid="uc-row"]');
        expect(rows.map((r) => r.attributes('data-priority'))).toEqual(['immediate', 'legal', 'normal']);
        expect(rows[0].text()).toContain('Imediata');
        expect(rows[0].text()).toContain('QUEIMADURA');
        expect(rows[1].text()).toContain('72 anos');
        expect(rows[2].find('[data-testid="uc-wait"]').text()).toBe('25 min');
        expect(wrapper.find('[data-testid="uc-summary-queued"]').text()).toBe('3');
        expect(wrapper.find('[data-testid="uc-summary-immediate"]').text()).toBe('1');
    });

    it('a espera anda sozinha (relógio de 15 s) e o relógio para ao sair da tela', async () => {
        const wrapper = mountIndex({ queue: [row()] });
        expect(wrapper.find('[data-testid="uc-wait"]').text()).toBe('25 min');

        await vi.advanceTimersByTimeAsync(60_000);
        expect(wrapper.find('[data-testid="uc-wait"]').text()).toBe('26 min');

        await vi.advanceTimersByTimeAsync(40 * 60_000);
        expect(wrapper.find('[data-testid="uc-wait"]').text()).toBe('1 h 6 min');

        const before = vi.getTimerCount();
        wrapper.unmount();
        // Relógio da espera + polling da fila: nada fica rodando depois de sair.
        expect(vi.getTimerCount()).toBeLessThan(before);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('fila vazia: mensagem e orientação', () => {
        const wrapper = mountIndex();

        expect(wrapper.find('[data-testid="uc-empty"]').text()).toContain('Ninguém na fila do pronto atendimento.');
        expect(wrapper.findAll('[data-testid="uc-row"]')).toHaveLength(0);
    });

    it('recepção (sem cadastro de médico): sem "Atender", com o aviso; sem painel ativo, sem "Chamar"', () => {
        const wrapper = mountIndex({ queue: [row()], canAttend: false, isDoctor: false, callPanelEnabled: false });

        expect(wrapper.find('[data-testid="uc-attend"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="uc-call"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('O médico atende pela tela dele.');
    });

    it('médico: "Atender" e "Chamar" postam para o item certo', async () => {
        const wrapper = mountIndex({
            queue: [row({ id: 'x1', status_key: 'called', called_time: '09:50', call_count: 2 })],
            canAttend: true,
            isDoctor: true,
            callPanelEnabled: true,
        });

        expect(wrapper.find('[data-testid="uc-called"]').text()).toContain('Chamado às 09:50');
        expect(wrapper.find('[data-testid="uc-call"]').text()).toContain('Chamar de novo');

        await wrapper.find('[data-testid="uc-attend"]').trigger('click');
        expect(routerPost).toHaveBeenCalledWith('/_routes/panel.urgent-care.attend/x1', {}, expect.any(Object));
    });

    it('desistiu exige motivo e envia o status certo', async () => {
        const wrapper = mountIndex({ queue: [row({ id: 'z9' })] });

        await wrapper.find('[data-testid="uc-left"]').trigger('click');
        const confirm = wrapper.find('[data-testid="uc-close-confirm"]');
        expect(confirm.attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="uc-close-reason"]').setValue('Foi embora');
        await wrapper.find('[data-testid="uc-close-confirm"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith(
            '/_routes/panel.urgent-care.close/z9',
            { status: 4, reason: 'Foi embora' },
            expect.any(Object),
        );
    });

    it('"Abrir prontuário" só para o médico que atendeu', () => {
        const closed = [
            row({
                id: 'm',
                status_key: 'in_care',
                status_label: 'Em atendimento',
                schedule_id: 's1',
                attended_by_id: 'doc-a',
            }),
            row({
                id: 'o',
                status_key: 'in_care',
                status_label: 'Em atendimento',
                schedule_id: 's2',
                attended_by_id: 'doc-b',
            }),
        ];
        const wrapper = mountIndex({ closed, canAttend: true, isDoctor: true, currentDoctorId: 'doc-a' });

        const links = wrapper.findAll('[data-testid="uc-open-record"]');
        expect(links).toHaveLength(1);
        expect(links[0].attributes('href')).toBe('/_routes/panel.schedules.attend/s1');
    });
});

describe('Pronto atendimento — formulário', () => {
    function mountForm(props = {}) {
        return mount(UrgentCareFormModal, {
            props: {
                open: true,
                reasons,
                priorities,
                doctors: [{ id: 'd1', name: 'DRA. ANA' }],
                covenants: [{ id: 'c1', name: 'UNIMED' }],
                legalPriorityAge: 60,
                canRegisterPatient: true,
                urls: { store: '/panel/urgent-care', patient_search: '/panel/patients/search', patient_quick: '/q' },
                t,
                ...props,
            },
            global: { stubs: { Teleport: true } },
        });
    }

    it('queimadura química sugere "Imediata" e mostra o alerta; escolha manual não é sobrescrita', async () => {
        const wrapper = mountForm();
        const form = formState.last;

        await wrapper.find('[data-reason="chemical_burn"]').setValue(true);
        expect(form.priority).toBe(1);
        expect(wrapper.find('[data-testid="uc-chemical-alert"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="uc-suggestion"]').text()).toContain('sugerida pelo motivo');
        expect(wrapper.find('[data-priority="immediate"]').element.checked).toBe(true);

        await wrapper.find('[data-priority="normal"]').setValue(true);
        await wrapper.find('[data-reason="ocular_trauma"]').setValue(true);
        expect(form.priority).toBe(3); // a recepção escolheu: o motivo não muda mais
    });

    it('paciente com 60+ sugere a prioridade por lei e traz o convênio do cadastro', async () => {
        const wrapper = mountForm();
        const form = formState.last;

        wrapper.vm.selectPatient({ id: 'p9', full_name: 'SR. JOSE', code: 'PAC-9', age: 72, covenant_id: 'c1' });
        await flushPromises();

        expect(form.patient_id).toBe('p9');
        expect(form.priority).toBe(2);
        expect(form.covenant_id).toBe('c1');
        expect(wrapper.find('[data-testid="uc-suggestion"]').text()).toContain('72 anos');
        expect(wrapper.find('[data-testid="uc-selected-patient"]').text()).toContain('SR. JOSE');
    });

    it('"Outro" exige a descrição antes de enviar; enviado, posta para a fila', async () => {
        const wrapper = mountForm();
        const form = formState.last;
        wrapper.vm.selectPatient({ id: 'p1', full_name: 'ANA', code: 'PAC-1', age: 30 });

        await wrapper.find('[data-reason="other"]').setValue(true);
        await flushPromises();
        expect(wrapper.find('[data-testid="uc-submit"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="uc-details"]').setValue('Visão dupla desde ontem');
        expect(wrapper.find('[data-testid="uc-submit"]').attributes('disabled')).toBeUndefined();

        await wrapper.find('form').trigger('submit');
        expect(form.post).toHaveBeenCalledWith('/panel/urgent-care', expect.any(Object));
    });

    it('paciente já na fila aparece desabilitado na busca', async () => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve([
                        { id: 'p1', full_name: 'NA FILA', code: 'PAC-1' },
                        { id: 'p2', full_name: 'LIVRE', code: 'PAC-2' },
                    ]),
            }),
        );
        const wrapper = mountForm({ queuedPatientIds: ['p1'] });

        await wrapper.find('[data-testid="uc-patient-search"]').setValue('na');
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();

        const options = wrapper.findAll('[data-testid="uc-patient-option"]');
        expect(options).toHaveLength(2);
        expect(options[0].attributes('disabled')).toBeDefined();
        expect(options[0].text()).toContain('Já está na fila');
        expect(options[1].attributes('disabled')).toBeUndefined();
    });

    it('busca com falha de rede não estoura erro solto; o timer da busca morre com o modal', async () => {
        globalThis.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        const wrapper = mountForm();

        await wrapper.find('[data-testid="uc-patient-search"]').setValue('jo');
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();
        expect(wrapper.text()).toContain('Nenhum paciente encontrado.');

        await wrapper.find('[data-testid="uc-patient-search"]').setValue('jos');
        wrapper.unmount();
        globalThis.fetch.mockClear();
        await vi.advanceTimersByTimeAsync(1000);
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('cadastro rápido envia o nascimento e, em homônimo, reenvia confirmado', async () => {
        const responses = [
            {
                ok: false,
                status: 422,
                json: () =>
                    Promise.resolve({ errors: { confirm_homonym: ['Já existe paciente com este nome (PAC-7).'] } }),
            },
            {
                ok: true,
                status: 201,
                json: () => Promise.resolve({ patient: { id: 'new', full_name: 'ANA LIMA', code: 'PAC-8', age: 66 } }),
            },
        ];
        globalThis.fetch = vi.fn(() => Promise.resolve(responses.shift()));
        const wrapper = mountForm();
        const form = formState.last;

        await wrapper.find('[data-testid="uc-quick-open"]').trigger('click');
        await wrapper.find('[data-testid="uc-quick-name"]').setValue('Ana Lima');
        await wrapper.find('[data-testid="uc-quick-cellphone"]').setValue('11999990000');
        await wrapper.find('[data-testid="uc-quick-birth"]').setValue('1960-01-01');
        await wrapper.find('[data-testid="uc-quick-save"]').trigger('click');
        await flushPromises();

        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toMatchObject({
            name: 'Ana Lima',
            birth_date: '1960-01-01',
            confirm_homonym: false,
        });
        expect(wrapper.find('[data-testid="uc-quick-homonym"]').text()).toContain('PAC-7');

        await wrapper.find('#uc-quick-homonym').setValue(true);
        await wrapper.find('[data-testid="uc-quick-save"]').trigger('click');
        await flushPromises();

        expect(JSON.parse(globalThis.fetch.mock.calls[1][1].body).confirm_homonym).toBe(true);
        expect(form.patient_id).toBe('new');
        expect(form.priority).toBe(2); // 66 anos
    });

    it('edição não troca o paciente e não manda patient_id', async () => {
        const entry = row({
            id: 'e5',
            patient_id: 'p5',
            patient_name: 'EDITAR',
            priority: 1,
            priority_key: 'immediate',
            reason: 1,
        });
        const wrapper = mountForm({ entry });
        const form = formState.last;

        expect(wrapper.text()).toContain('Editar item da fila');
        expect(wrapper.text()).not.toContain('Trocar paciente');

        await wrapper.find('form').trigger('submit');
        expect(form.put).toHaveBeenCalledWith('/_routes/panel.urgent-care.update/e5', expect.any(Object));
        expect(form._transform({ patient_id: 'p5', reason: 1 })).toEqual({ reason: 1 });
    });
});
