import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () =>
    (await import('../Manager/RecallProtocols/support/inertiaMock.js')).buildInertiaMock(),
);

import { resetInertiaMock } from '../Manager/RecallProtocols/support/inertiaMock.js';
import IntravitrealCoursesPanel from '@/Components/Panel/Intravitreal/IntravitrealCoursesPanel.vue';
import IntravitrealRequestFields from '@/Components/Panel/Intravitreal/IntravitrealRequestFields.vue';
import IntravitrealExecutionFields from '@/Components/Panel/Intravitreal/IntravitrealExecutionFields.vue';
import MedicalRecordProceduresModal from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordProceduresModal.vue';
import t from './support/recall_course.json';

const global = { stubs: { Teleport: true } };

const course = (overrides = {}) => ({
    id: 'c-od',
    eye: 'right',
    drug: 'Aflibercepte',
    indication: null,
    regimen: 'loading_tae',
    regimen_label: 'Ataque e depois trate e estenda',
    status: 'active',
    status_label: 'Em andamento',
    open: true,
    started_on: '2026-01-05',
    ended_on: null,
    end_reason: null,
    last_applied_on: '2026-03-10',
    next_due_on: '2026-05-05',
    days_to_next: 56,
    interval_weeks: 8,
    applications_count: 3,
    planned: { id: 'a-4', sequence_no: 4, schedule_id: null },
    last_applications: [
        {
            id: 'a-3',
            sequence_no: 3,
            lot_number: 'LOT-9',
            lot_expiry: '2027-01-31',
            authorization_label: 'Aprovada',
            performed_at: '2026-03-10T10:00:00-03:00',
        },
    ],
    ...overrides,
});

beforeEach(() => {
    resetInertiaMock();
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('IntravitrealCoursesPanel', () => {
    const urls = { close: '/c/__COURSE_ID__/close', schedule: '/c/__COURSE_ID__/schedule-next' };
    const panel = (props = {}) =>
        mount(IntravitrealCoursesPanel, { props: { courses: [course()], canManage: true, urls, t, ...props }, global });

    it('mostra o curso do olho com droga, aplicações, intervalo, próxima data e o último lote', () => {
        const w = panel();
        const text = w.get('[data-testid="ivt-course-right"]').text();

        expect(text).toContain('OD');
        expect(text).toContain('Aflibercepte');
        expect(text).toContain('3 aplicações');
        expect(text).toContain('intervalo decidido: 8 semanas');
        expect(text).toContain('Próxima aplicação');
        expect(text).toContain('em 56 dias');
        expect(text).toContain('LOT-9');
        expect(text).toContain('Aprovada');
    });

    it('atraso aparece em TEXTO além da cor', () => {
        const w = panel({ courses: [course({ days_to_next: -9, next_due_on: '2026-02-28' })] });

        expect(w.get('[data-testid="ivt-next"]').text()).toContain('atrasada há 9 dias');
        expect(w.find('[data-testid="ivt-next"] .recall-tone--danger').exists()).toBe(true);
    });

    it('sem próxima aplicação planejada diz isso, sem inventar data', () => {
        const w = panel({ courses: [course({ days_to_next: null, next_due_on: null })] });

        expect(w.get('[data-testid="ivt-next"]').text()).toContain(t.panel.no_next);
    });

    it('curso encerrado mostra o motivo e não oferece ações', () => {
        const w = panel({
            courses: [
                course({
                    open: false,
                    status: 'completed',
                    status_label: 'Concluído',
                    ended_on: '2026-04-01',
                    end_reason: 'Mácula seca',
                }),
            ],
        });

        expect(w.text()).toContain('Mácula seca');
        expect(w.find('[data-testid="ivt-schedule-open"]').exists()).toBe(false);
        expect(w.find('[data-testid="ivt-close-open"]').exists()).toBe(false);
    });

    it('sem permissão de gerir o curso (recepção) só lê', () => {
        const w = panel({ canManage: false });

        expect(w.find('[data-testid="ivt-close-open"]').exists()).toBe(false);
        expect(w.find('[data-testid="ivt-schedule-open"]').exists()).toBe(false);
    });

    it('não renderiza nada sem cursos', () => {
        expect(panel({ courses: [] }).find('[data-testid="ivt-panel"]').exists()).toBe(false);
    });

    it('encerrar pede motivo (3+ letras), envia status+motivo ao curso certo e avisa o pai', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);

        const w = panel();
        await w.get('[data-testid="ivt-close-open"]').trigger('click');

        const confirm = w.get('[data-testid="ivt-close-confirm"]');
        expect(confirm.attributes('disabled')).toBeDefined();

        await w.get('#ivt-close-reason').setValue('ab');
        expect(confirm.attributes('disabled')).toBeDefined();

        await w.get('#ivt-close-status').setValue('discontinued');
        await w.get('#ivt-close-reason').setValue('Mudou de cidade');
        await confirm.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/c/c-od/close');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            status: 'discontinued',
            reason: 'Mudou de cidade',
        });
        expect(fetchMock.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('tok');
        expect(w.emitted('changed')).toHaveLength(1);
        expect(w.find('[data-testid="ivt-action"]').exists()).toBe(false);
    });

    it('agendar a próxima só habilita com data e envia date_time', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);

        const w = panel();
        await w.get('[data-testid="ivt-schedule-open"]').trigger('click');

        const confirm = w.get('[data-testid="ivt-schedule-confirm"]');
        expect(confirm.attributes('disabled')).toBeDefined();

        await w.get('#ivt-schedule-at').setValue('2026-05-05T09:00');
        await confirm.trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/c/c-od/schedule-next');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ date_time: '2026-05-05T09:00' });
    });

    it('recusa do servidor (422) aparece na tela e o formulário não fecha', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue({ ok: false, json: async () => ({ message: 'Horário bloqueado.', errors: null }) }),
        );

        const w = panel();
        await w.get('[data-testid="ivt-schedule-open"]').trigger('click');
        await w.get('#ivt-schedule-at').setValue('2026-05-05T09:00');
        await w.get('[data-testid="ivt-schedule-confirm"]').trigger('click');
        await flushPromises();

        expect(w.get('[data-testid="ivt-error"]').text()).toBe('Horário bloqueado.');
        expect(w.find('[data-testid="ivt-action"]').exists()).toBe(true);
        expect(w.emitted('changed')).toBeUndefined();
    });

    it('já agendada: não oferece agendar de novo', () => {
        const w = panel({ courses: [course({ planned: { id: 'a-4', sequence_no: 4, schedule_id: 's-1' } })] });

        expect(w.find('[data-testid="ivt-schedule-open"]').exists()).toBe(false);
        expect(w.text()).toContain(t.panel.has_schedule);
    });
});

describe('IntravitrealRequestFields', () => {
    const model = { drug_key: '', drug_label: '', regimen: 'loading_tae', indication: '' };
    const drugs = [
        { value: 'aflibercept', label: 'Aflibercepte' },
        { value: 'other', label: 'Outra droga' },
    ];
    const regimens = [{ value: 'loading_tae', label: 'Ataque e depois trate e estenda' }];
    const fields = (props = {}) =>
        mount(IntravitrealRequestFields, {
            props: { modelValue: model, drugs, regimens, eye: 'right', t, ...props },
            global,
        });

    it('avisa que o curso é de um olho só quando o olho não é OD/OE', () => {
        expect(fields({ eye: '' }).find('[data-testid="ivt-eye-required"]').exists()).toBe(true);
        expect(fields({ eye: 'both' }).find('[data-testid="ivt-eye-required"]').exists()).toBe(true);
        expect(fields({ eye: 'left' }).find('[data-testid="ivt-eye-required"]').exists()).toBe(false);
    });

    it('emite um objeto NOVO ao escolher a droga (não muda o recebido)', async () => {
        const w = fields();
        await w.get('#ivt-drug').setValue('aflibercept');

        const [emitted] = w.emitted('update:modelValue').at(-1);
        expect(emitted).toEqual({ ...model, drug_key: 'aflibercept' });
        expect(emitted).not.toBe(model);
        expect(model.drug_key).toBe('');
    });

    it('"outra droga" pede o nome', () => {
        expect(fields().find('#ivt-drug-label').exists()).toBe(false);
        expect(
            fields({ modelValue: { ...model, drug_key: 'other' } })
                .find('#ivt-drug-label')
                .exists(),
        ).toBe(true);
    });

    it('mostra o erro do servidor no campo da droga', () => {
        const w = fields({ errors: { drug_key: 'Escolha a droga do curso.' } });

        expect(w.text()).toContain('Escolha a droga do curso.');
        expect(w.get('#ivt-drug').classes()).toContain('is-invalid');
    });
});

describe('IntravitrealExecutionFields', () => {
    const base = {
        stock_lot_id: '',
        lot_number: '',
        lot_expiry: '',
        authorization_required: false,
        authorization_status: 'pending',
        authorization_number: '',
        authorization_valid_until: '',
        authorization_override: false,
        authorization_notes: '',
        end_course: '',
        end_reason: '',
        next_interval_weeks: '',
        schedule_at: '',
    };
    const statuses = [
        { value: 'pending', label: 'A solicitar' },
        { value: 'approved', label: 'Aprovada' },
    ];
    const fields = (model = {}, props = {}) =>
        mount(IntravitrealExecutionFields, {
            props: { modelValue: { ...base, ...model }, statuses, weeks: { min: 2, max: 16 }, t, ...props },
            global,
        });

    it('lote digitado é obrigatório; vindo do estoque, esconde os campos manuais', () => {
        expect(fields().find('#ivt-lot').exists()).toBe(true);
        expect(fields().find('#ivt-expiry').exists()).toBe(true);

        const lots = [{ id: 'l-1', lot_number: 'ESTQ-9', expiry_date: '2026-12-31' }];

        expect(fields({}, { lots }).find('#ivt-stock-lot').exists()).toBe(true);
        expect(fields({ stock_lot_id: 'l-1' }, { lots }).find('#ivt-lot').exists()).toBe(false);
    });

    it('autorização: só aparece o aviso de "aplicar assim mesmo" quando exigida e não aprovada', () => {
        expect(fields().find('[data-testid="ivt-authorization"]').exists()).toBe(false);
        expect(
            fields({ authorization_required: true, authorization_status: 'pending' })
                .find('[data-testid="ivt-override"]')
                .exists(),
        ).toBe(true);
        expect(
            fields({ authorization_required: true, authorization_status: 'approved' })
                .find('[data-testid="ivt-override"]')
                .exists(),
        ).toBe(false);
    });

    describe('autorização "aprovada" com a validade vencida', () => {
        afterEach(() => {
            vi.useRealTimers();
        });

        const override = (model) =>
            fields({ authorization_required: true, authorization_status: 'approved', ...model }).find(
                '[data-testid="ivt-override"]',
            );

        it('o servidor a trata como vencida: a tela mostra a confirmação e a justificativa sem trocar o status à mão', () => {
            // 23:30 no relógio local: toISOString() já seria "amanhã" em UTC.
            vi.useFakeTimers();
            vi.setSystemTime(new Date(2026, 4, 10, 23, 30));

            expect(override({ authorization_valid_until: '2026-05-09' }).exists()).toBe(true);
        });

        it('vale até o próprio dia da validade e sem data não há vencimento', () => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date(2026, 4, 10, 23, 30));

            expect(override({ authorization_valid_until: '2026-05-10' }).exists()).toBe(false);
            expect(override({ authorization_valid_until: '2026-05-11' }).exists()).toBe(false);
            expect(override({ authorization_valid_until: '' }).exists()).toBe(false);
        });

        it('o erro do servidor (justificativa) aparece nos campos e o status segue "aprovada"', () => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date(2026, 4, 10, 10, 0));

            const w = fields(
                {
                    authorization_required: true,
                    authorization_status: 'approved',
                    authorization_valid_until: '2026-01-01',
                },
                { errors: { authorization_status: 'Justifique para aplicar assim mesmo.' } },
            );

            expect(w.get('[data-testid="ivt-override"]').text()).toContain('Justifique para aplicar assim mesmo.');
            expect(w.get('#ivt-auth-status').element.value).toBe('approved');
        });

        it('sem exigir autorização, a validade vencida não pede nada', () => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date(2026, 4, 10, 10, 0));

            expect(
                fields({
                    authorization_required: false,
                    authorization_status: 'approved',
                    authorization_valid_until: '2026-01-01',
                })
                    .find('[data-testid="ivt-override"]')
                    .exists(),
            ).toBe(false);
        });
    });

    it('encerrar o curso troca o intervalo pelo motivo', () => {
        const open = fields();
        expect(open.find('#ivt-interval').exists()).toBe(true);
        expect(open.find('#ivt-end-reason').exists()).toBe(false);

        const closing = fields({ end_course: 'completed' });
        expect(closing.find('#ivt-interval').exists()).toBe(false);
        expect(closing.find('#ivt-end-reason').exists()).toBe(true);
    });

    it('o intervalo respeita a faixa do servidor e emite número', async () => {
        const w = fields({}, { weeks: { min: 2, max: 16 } });
        const input = w.get('#ivt-interval');

        expect(input.attributes('min')).toBe('2');
        expect(input.attributes('max')).toBe('16');

        await input.setValue('8');
        expect(w.emitted('update:modelValue').at(-1)[0].next_interval_weeks).toBe(8);
    });

    it('título traz o número da aplicação quando se sabe', () => {
        expect(fields({}, { sequence: 4 }).text()).toContain('Aplicação intravítrea nº 4');
        expect(fields().text()).toContain('Aplicação intravítrea');
    });
});

describe('MedicalRecordProceduresModal — injeção intravítrea', () => {
    const urls = {
        medicalrecordprocedures_index: '/procs',
        medicalrecordprocedures_store: '/procs/store',
        medicalrecordprocedure_mark_done_template: '/procs/__PROCEDURE_ID__/done',
        medicalrecordprocedure_cancel_template: '/procs/__PROCEDURE_ID__/cancel',
        procedure_bom_template: '/bom/__PROCEDURE_ID__',
        procedure_search: '/search',
        intravitreal_courses_index: '/courses',
        intravitreal_course_close_template: '/courses/__COURSE_ID__/close',
        intravitreal_course_schedule_next_template: '/courses/__COURSE_ID__/schedule-next',
    };

    const ivtProc = {
        id: 'mrp-1',
        procedure_id: 'cat-ivt',
        procedure_name: 'Injeção intravítrea',
        clinical_kind: 'intravitreal_injection',
        eye: 'right',
        status: 'requested',
        status_label: 'Solicitado',
        doctor_name: 'Dra. Ana',
        created_at: '10/03/2026 09:00',
    };
    const plainProc = {
        ...ivtProc,
        id: 'mrp-2',
        procedure_id: 'cat-x',
        procedure_name: 'Curativo',
        clinical_kind: null,
        eye: null,
    };

    const coursesPayload = {
        data: {
            courses: [course()],
            drugs: [
                { value: 'aflibercept', label: 'Aflibercepte' },
                { value: 'other', label: 'Outra droga' },
            ],
            regimens: [{ value: 'loading_tae', label: 'Ataque e depois trate e estenda' }],
            statuses: [
                { value: 'pending', label: 'A solicitar' },
                { value: 'approved', label: 'Aprovada' },
            ],
            weeks: { min: 2, max: 16 },
            t,
        },
    };

    /** fetch por URL: devolve o que cada rota diria; guarda as chamadas. */
    function router(overrides = {}) {
        const calls = [];
        const routes = {
            '/procs': () => ({ ok: true, json: async () => ({ data: [ivtProc, plainProc] }) }),
            '/courses': () => ({ ok: true, json: async () => coursesPayload }),
            '/bom/cat-ivt': () => ({ ok: true, json: async () => ({ data: [] }) }),
            '/bom/cat-x': () => ({ ok: true, json: async () => ({ data: [] }) }),
            ...overrides,
        };
        const fetchMock = vi.fn(async (url, init = {}) => {
            calls.push({ url, init, body: init.body ? JSON.parse(init.body) : null });
            const handler = routes[String(url).split('?')[0]];

            return handler ? handler(url, init) : { ok: false, json: async () => ({}) };
        });
        vi.stubGlobal('fetch', fetchMock);

        return calls;
    }

    const open = async (props = {}) => {
        const w = mount(MedicalRecordProceduresModal, {
            props: { show: false, urls, isDoctor: true, isLocked: false, t: {}, ...props },
            global,
        });
        await w.setProps({ show: true });
        await flushPromises();

        return w;
    };

    it('abre com o painel dos cursos do paciente e a lista de procedimentos', async () => {
        router();
        const w = await open();

        expect(w.find('[data-testid="ivt-panel"]').exists()).toBe(true);
        expect(w.text()).toContain('Aflibercepte');
        expect(w.text()).toContain('Injeção intravítrea');
        expect(w.text()).toContain('Curativo');
    });

    it('confirmar execução de uma injeção mostra os campos da aplicação (nº da aplicação, intervalo pré-preenchido)', async () => {
        router();
        const w = await open();

        await w
            .findAll('button')
            .find((b) => b.text().includes('Confirmar execução'))
            .trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="ivt-execution"]').exists()).toBe(true);
        expect(w.text()).toContain('Aplicação intravítrea nº 4');
        expect(w.get('#ivt-interval').element.value).toBe('8');
        // O painel dos cursos some enquanto o formulário está aberto.
        expect(w.find('[data-testid="ivt-panel"]').exists()).toBe(false);
    });

    it('procedimento comum NÃO mostra campos de injeção', async () => {
        router();
        const w = await open();

        const buttons = w.findAll('button').filter((b) => b.text().includes('Confirmar execução'));
        await buttons[1].trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="ivt-execution"]').exists()).toBe(false);
    });

    it('envia a aplicação (lote, validade, intervalo) junto com a execução e mostra a próxima data', async () => {
        const calls = router({
            '/procs/mrp-1/done': () => ({
                ok: true,
                json: async () => ({
                    data: { ...ivtProc, status: 'done', status_label: 'Executado' },
                    intravitreal: {
                        message: 'Aplicação registrada. Próxima aplicação prevista para 05/05/2026.',
                        next_due_on: '2026-05-05',
                        closed: false,
                        schedule: null,
                    },
                }),
            }),
        });
        const w = await open();

        await w
            .findAll('button')
            .find((b) => b.text().includes('Confirmar execução'))
            .trigger('click');
        await flushPromises();

        await w.get('#ivt-lot').setValue('LOT-123');
        await w.get('#ivt-expiry').setValue('2027-01-31');
        await w.get('#ivt-interval').setValue('10');

        await w
            .findAll('button')
            .find((b) => b.classes().includes('btn-success'))
            .trigger('click');
        await flushPromises();

        const done = calls.find((c) => c.url === '/procs/mrp-1/done');

        expect(done.body.intravitreal).toMatchObject({
            lot_number: 'LOT-123',
            lot_expiry: '2027-01-31',
            next_interval_weeks: 10,
            authorization_required: false,
            authorization_status: null,
            end_course: null,
        });
        expect(w.get('[data-testid="ivt-notice"]').text()).toContain('Próxima aplicação prevista para 05/05/2026');
        // Recarregou os cursos depois da aplicação.
        expect(calls.filter((c) => c.url === '/courses').length).toBeGreaterThanOrEqual(2);
    });

    it('recusa do servidor (lote vencido) fica na tela, com o formulário aberto e nada fechado', async () => {
        router({
            '/procs/mrp-1/done': () => ({
                ok: false,
                json: async () => ({
                    message: 'O lote está vencido: não aplique este medicamento.',
                    errors: { lot_expiry: ['O lote está vencido: não aplique este medicamento.'] },
                }),
            }),
        });
        const w = await open();

        await w
            .findAll('button')
            .find((b) => b.text().includes('Confirmar execução'))
            .trigger('click');
        await flushPromises();
        await w.get('#ivt-lot').setValue('LOT-OLD');
        await w.get('#ivt-expiry').setValue('2020-01-01');
        await w
            .findAll('button')
            .find((b) => b.classes().includes('btn-success'))
            .trigger('click');
        await flushPromises();

        expect(w.text()).toContain('O lote está vencido');
        expect(w.find('[data-testid="ivt-execution"]').exists()).toBe(true);
        expect(w.get('#ivt-expiry').classes()).toContain('is-invalid');
    });

    it('encerrar o curso na aplicação envia o motivo e NÃO envia intervalo nem agendamento', async () => {
        const calls = router({
            '/procs/mrp-1/done': () => ({
                ok: true,
                json: async () => ({
                    data: { ...ivtProc, status: 'done' },
                    intravitreal: { message: 'Aplicação registrada e curso encerrado.', closed: true, schedule: null },
                }),
            }),
        });
        const w = await open();

        await w
            .findAll('button')
            .find((b) => b.text().includes('Confirmar execução'))
            .trigger('click');
        await flushPromises();
        await w.get('#ivt-lot').setValue('LOT-1');
        await w.get('#ivt-expiry').setValue('2027-01-31');
        await w.get('#ivt-end-completed').setValue(true);
        await w.get('#ivt-end-reason').setValue('Mácula seca por 3 visitas');
        await w
            .findAll('button')
            .find((b) => b.classes().includes('btn-success'))
            .trigger('click');
        await flushPromises();

        const body = calls.find((c) => c.url === '/procs/mrp-1/done').body.intravitreal;

        expect(body.end_course).toBe('completed');
        expect(body.end_reason).toBe('Mácula seca por 3 visitas');
        expect(body.next_interval_weeks).toBeNull();
        expect(body.schedule_at).toBeNull();
    });

    it('solicitar injeção em AO (ou sem olho) é barrado na tela, sem chamar o servidor', async () => {
        const calls = router({
            '/search': () => ({
                ok: true,
                json: async () => [
                    {
                        id: 'cat-ivt',
                        code: 'IVT',
                        name: 'Injeção intravítrea',
                        clinical_kind: 'intravitreal_injection',
                    },
                ],
            }),
        });
        const w = await open();

        await w
            .findAll('button')
            .find((b) => b.text().includes('Solicitar procedimento'))
            .trigger('click');
        await flushPromises();

        await w.get('input[type="text"]').setValue('inje');
        await w.get('input[type="text"]').trigger('input');
        // Espera o debounce da busca (250 ms); timers falsos desalinham o relógio dos eventos do Vue.
        await new Promise((resolve) => setTimeout(resolve, 300));
        await flushPromises();

        await w.get('li.list-group-item').trigger('mousedown');
        await flushPromises();

        expect(w.find('[data-testid="ivt-request"]').exists()).toBe(true);

        await w
            .findAll('select')
            .find((s) => s.text().includes('AO'))
            .setValue('both');
        await w
            .findAll('button')
            .find((b) => b.text() === 'Solicitar')
            .trigger('click');
        await flushPromises();

        expect(calls.find((c) => c.url === '/procs/store')).toBeUndefined();
        expect(w.text()).toContain(t.request.eye_required);
    });

    it('solicitar injeção em OD envia a droga e o esquema do curso', async () => {
        const calls = router({
            '/search': () => ({
                ok: true,
                json: async () => [
                    {
                        id: 'cat-ivt',
                        code: 'IVT',
                        name: 'Injeção intravítrea',
                        clinical_kind: 'intravitreal_injection',
                    },
                ],
            }),
            '/procs/store': () => ({ ok: true, json: async () => ({ data: { ...ivtProc, id: 'mrp-9' } }) }),
        });
        const w = await open();

        await w
            .findAll('button')
            .find((b) => b.text().includes('Solicitar procedimento'))
            .trigger('click');
        await flushPromises();

        await w.get('input[type="text"]').setValue('inje');
        await w.get('input[type="text"]').trigger('input');
        // Espera o debounce da busca (250 ms); timers falsos desalinham o relógio dos eventos do Vue.
        await new Promise((resolve) => setTimeout(resolve, 300));
        await flushPromises();
        await w.get('li.list-group-item').trigger('mousedown');
        await flushPromises();

        await w
            .findAll('select')
            .find((s) => s.text().includes('AO'))
            .setValue('right');
        await w.get('#ivt-drug').setValue('aflibercept');
        await w
            .findAll('button')
            .find((b) => b.text() === 'Solicitar')
            .trigger('click');
        await flushPromises();

        const store = calls.find((c) => c.url === '/procs/store');

        expect(store.body.procedure_id).toBe('cat-ivt');
        expect(store.body.eye).toBe('right');
        expect(store.body.intravitreal).toEqual({
            drug_key: 'aflibercept',
            drug_label: '',
            regimen: 'loading_tae',
            indication: '',
        });
    });

    it('procedimento comum solicitado NÃO envia dados de curso', async () => {
        const calls = router({
            '/search': () => ({
                ok: true,
                json: async () => [{ id: 'cat-x', code: 'X', name: 'Curativo', clinical_kind: null }],
            }),
            '/procs/store': () => ({ ok: true, json: async () => ({ data: { ...plainProc, id: 'mrp-8' } }) }),
        });
        const w = await open();

        await w
            .findAll('button')
            .find((b) => b.text().includes('Solicitar procedimento'))
            .trigger('click');
        await flushPromises();

        await w.get('input[type="text"]').setValue('cura');
        await w.get('input[type="text"]').trigger('input');
        // Espera o debounce da busca (250 ms); timers falsos desalinham o relógio dos eventos do Vue.
        await new Promise((resolve) => setTimeout(resolve, 300));
        await flushPromises();
        await w.get('li.list-group-item').trigger('mousedown');
        await flushPromises();

        expect(w.find('[data-testid="ivt-request"]').exists()).toBe(false);

        await w
            .findAll('button')
            .find((b) => b.text() === 'Solicitar')
            .trigger('click');
        await flushPromises();

        expect(calls.find((c) => c.url === '/procs/store').body).not.toHaveProperty('intravitreal');
    });

    // O curso não é do prontuário: as tabelas e os endpoints dele não olham a assinatura. Com o atendimento de origem
    // ASSINADO (isLocked) o médico continua podendo agendar a próxima aplicação e encerrar o tratamento; o que escreve
    // NO prontuário (solicitar, confirmar execução, cancelar) segue travado.
    describe('com o prontuário de origem assinado (isLocked)', () => {
        const buttonTexts = (w) => w.findAll('button').map((b) => b.text());

        it('o médico ainda gere o curso: "Agendar a próxima" e "Encerrar curso" aparecem', async () => {
            router();
            const w = await open({ isLocked: true });

            expect(w.find('[data-testid="ivt-schedule-open"]').exists()).toBe(true);
            expect(w.find('[data-testid="ivt-close-open"]').exists()).toBe(true);
        });

        it('mas o que escreve no prontuário segue travado (solicitar, confirmar execução, cancelar)', async () => {
            router();

            const unlocked = await open();
            expect(buttonTexts(unlocked).some((text) => text.includes('Solicitar procedimento'))).toBe(true);
            expect(buttonTexts(unlocked).some((text) => text.includes('Confirmar execução'))).toBe(true);

            const locked = await open({ isLocked: true });
            expect(buttonTexts(locked).some((text) => text.includes('Solicitar procedimento'))).toBe(false);
            expect(buttonTexts(locked).some((text) => text.includes('Confirmar execução'))).toBe(false);
            expect(locked.text()).toContain('Injeção intravítrea');
        });

        it('agendar a próxima de um atendimento assinado chega ao servidor, no curso certo', async () => {
            const calls = router({
                '/courses/c-od/schedule-next': () => ({
                    ok: true,
                    json: async () => ({ data: { schedule_id: 's-1' } }),
                }),
            });
            const w = await open({ isLocked: true });

            await w.get('[data-testid="ivt-schedule-open"]').trigger('click');
            await w.get('#ivt-schedule-at').setValue('2026-06-02T09:15');
            await w.get('[data-testid="ivt-schedule-confirm"]').trigger('click');
            await flushPromises();

            expect(calls.find((c) => c.url === '/courses/c-od/schedule-next').body).toEqual({
                date_time: '2026-06-02T09:15',
            });
        });

        it('encerrar o curso de um atendimento assinado chega ao servidor, com o motivo', async () => {
            const calls = router({
                '/courses/c-od/close': () => ({ ok: true, json: async () => ({ data: { id: 'c-od' } }) }),
            });
            const w = await open({ isLocked: true });

            await w.get('[data-testid="ivt-close-open"]').trigger('click');
            await w.get('#ivt-close-reason').setValue('Mácula seca');
            await w.get('[data-testid="ivt-close-confirm"]').trigger('click');
            await flushPromises();

            expect(calls.find((c) => c.url === '/courses/c-od/close').body).toEqual({
                status: 'completed',
                reason: 'Mácula seca',
            });
        });
    });

    it('quem não é médico (recepção) só lê o curso, com o prontuário aberto ou assinado', async () => {
        router();

        for (const isLocked of [false, true]) {
            const w = await open({ isDoctor: false, isLocked });

            expect(w.find('[data-testid="ivt-panel"]').exists()).toBe(true);
            expect(w.find('[data-testid="ivt-schedule-open"]').exists()).toBe(false);
            expect(w.find('[data-testid="ivt-close-open"]').exists()).toBe(false);
        }
    });

    it('sem as URLs de gerir o curso nem o médico vê os botões (a tela não os teria como chamar)', async () => {
        router();
        const w = await open({
            urls: {
                ...urls,
                intravitreal_course_close_template: undefined,
                intravitreal_course_schedule_next_template: undefined,
            },
        });

        expect(w.find('[data-testid="ivt-panel"]').exists()).toBe(true);
        expect(w.find('[data-testid="ivt-schedule-open"]').exists()).toBe(false);
        expect(w.find('[data-testid="ivt-close-open"]').exists()).toBe(false);
    });
});
