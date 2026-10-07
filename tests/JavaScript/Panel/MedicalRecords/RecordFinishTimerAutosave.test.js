import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

/**
 * Fase 1 / B2 — prontuário: confirmação ao finalizar, cronômetro e autosave.
 *
 *  - "Finalizar consulta" (barra e guard de saída) abre um modal com o que vai
 *    acontecer: salvar, "Atendido" (ou a trava de caixa), volta pra Agenda,
 *    rascunhos fora do form descartados e a duração do atendimento. Só envia
 *    depois de confirmar.
 *  - Cronômetro: desde o "Atender" (started_at do servidor); sem agendamento,
 *    desde a abertura; atendido = duração parada; assinado = some.
 *  - Autosave: grava no SERVIDOR a cada 30 s só com mudança, na hora ao
 *    ocultar a aba (keepalive), para no prontuário assinado (423), nada no
 *    armazenamento do navegador; oferta de recuperar/descartar ao abrir.
 */
const inertia = vi.hoisted(() => ({ handlers: {}, submits: [] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { preferences: {} } }, flash: {}, errors: {}, locale: 'pt_BR' } }),
        router: {
            on: vi.fn((type, callback) => {
                inertia.handlers[type] = callback;
                return () => {};
            }),
            visit: vi.fn(),
            reload: vi.fn(),
        },
        useForm: (data) => {
            const form = actual.useForm(data);
            for (const method of ['put', 'post']) {
                form[method] = vi.fn((url, options) =>
                    inertia.submits.push({ method, url, options, data: form.data() }),
                );
            }
            return form;
        },
    };
});

const MedicalRecordForm = (await import('@/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue')).default;
const AttendanceTimer = (await import('@/Pages/Panel/MedicalRecords/Components/AttendanceTimer.vue')).default;
const { useRecordAutosave } = await import('@/composables/useRecordAutosave.js');

enableAutoUnmount(afterEach);

const T = {
    finish_confirm_title: 'Finalizar a consulta de :name?',
    finish_confirm_status: 'A consulta passa para "Atendido" na Agenda.',
    finish_confirm_cash: 'Esta clínica exige lançamento no caixa.',
    finish_confirm_drafts: 'Texto ainda não registrado será descartado.',
    finish_confirm_duration: 'Duração do atendimento: :time',
    finish_confirm_cancel: 'Voltar ao prontuário',
    finish_confirm_ok: 'Finalizar consulta',
    timer_label: 'Tempo de atendimento',
    timer_done: 'Atendimento: :time',
    timer_since_open: 'Contando desde a abertura do prontuário',
    timer_since_start: 'Contando desde o início do atendimento, às :time',
    draft_saved_at: 'Rascunho salvo às :time',
    draft_found_text: 'Há um rascunho de :date.',
    draft_recover: 'Recuperar rascunho',
    draft_discard: 'Descartar',
};

const STUBS = {
    teleport: true,
    AiAssistantPanel: true,
    TinyMceEditor: true,
    SearchSelect: true,
    AcuitySelect: true,
    MedicalRecordFileUploadModal: true,
    MedicalRecordImagingModal: true,
    MedicalRecordProceduresModal: true,
    ContactLensCalculatorModal: true,
    PdfPreviewModal: true,
};

const NOW = new Date('2026-10-06T10:30:00');

const record = (over = {}) => ({ id: 'r1', main_complaint: 'Baixa visual', is_locked: false, ...over });

function mountForm({ edit = true, medicalrecord, urls = {}, ...over } = {}) {
    return mount(MedicalRecordForm, {
        props: {
            patient: { id: 'p1', full_name: 'Paciente Teste' },
            medicalrecord: medicalrecord ?? (edit ? record() : null),
            isEdit: edit,
            isDoctor: true,
            currentDoctorId: 'd1',
            catalogs: {},
            urls: { store: '/store', update: '/update', list: '/list', schedules: '/agenda', draft: '/draft', ...urls },
            t: T,
            ...over,
        },
        global: { stubs: STUBS },
    });
}

const flow = (over = {}) => ({
    id: 's1',
    situation: 6,
    update_url: '/sit',
    patient_name: 'MARIA DA SILVA',
    cash_blocked: false,
    started_at: '2026-10-06T10:07:00',
    finished_at: null,
    ...over,
});

function jsonResponse(status, body = {}) {
    return Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });
}

const draftCalls = () => globalThis.fetch.mock.calls.filter(([url]) => String(url).startsWith('/draft'));

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
    inertia.handlers = {};
    inertia.submits = [];
    window.confirm = vi.fn(() => true);
    window.showSuccessToast = vi.fn();
    window.history.replaceState(null, '', '/panel/patients/p1/medicalrecords/r1/edit');
    globalThis.fetch = vi.fn(() => jsonResponse(200, { saved_at_label: '10:31' }));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Finalizar consulta — confirmação', () => {
    it('o botão da barra abre o modal com o que vai acontecer e só envia ao confirmar', async () => {
        const wrapper = mountForm({ scheduleFlow: flow() });
        await flushPromises();

        await wrapper.find('.pmr-flow-finish').trigger('click');
        await flushPromises();

        const modal = wrapper.find('[data-testid="pmr-finish-modal"]');
        expect(modal.exists()).toBe(true);
        expect(modal.text()).toContain('Finalizar a consulta de MARIA DA SILVA?');
        expect(modal.text()).toContain('A consulta passa para "Atendido" na Agenda.');
        expect(wrapper.find('[data-testid="pmr-finish-duration"]').text()).toBe('Duração do atendimento: 23 min');
        expect(wrapper.find('[data-testid="pmr-finish-cash"]').exists()).toBe(false);
        expect(inertia.submits).toHaveLength(0);

        await wrapper.find('[data-testid="pmr-finish-confirm"]').trigger('click');

        expect(inertia.submits).toHaveLength(1);
        expect(inertia.submits[0].data.flow_action).toBe('finish');
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('com a trava de caixa da clínica, avisa que o "Atendido" não muda sem o caixa', async () => {
        const wrapper = mountForm({ scheduleFlow: flow({ cash_blocked: true }) });
        await flushPromises();

        wrapper.vm.submitFlow('finish');
        await flushPromises();

        expect(wrapper.find('[data-testid="pmr-finish-cash"]').text()).toContain('exige lançamento no caixa');
        expect(wrapper.find('[data-testid="pmr-finish-modal"]').text()).not.toContain('passa para "Atendido"');
    });

    it('Salvar, Dilatar e Exame não pedem a confirmação de finalizar', async () => {
        const wrapper = mountForm({ scheduleFlow: flow() });
        await flushPromises();

        await wrapper.find('.pmr-flow-dilate').trigger('click');

        expect(wrapper.find('[data-testid="pmr-finish-modal"]').exists()).toBe(false);
        expect(inertia.submits[0].data.flow_action).toBe('dilate');
    });
});

describe('Cronômetro do atendimento', () => {
    const mountTimer = (props) => mount(AttendanceTimer, { props: { t: T, ...props } });

    it('com agendamento conta desde o início do atendimento e anda de segundo em segundo', async () => {
        const wrapper = mountTimer({ flow: flow({ started_at: '2026-10-06T10:07:00' }) });

        expect(wrapper.find('.pmr-timer__value').text()).toBe('23:00');
        expect(wrapper.attributes('title')).toContain('Contando desde o início do atendimento');

        await vi.advanceTimersByTimeAsync(65_000);
        expect(wrapper.find('.pmr-timer__value').text()).toBe('24:05');

        await vi.advanceTimersByTimeAsync(40 * 60_000);
        expect(wrapper.find('.pmr-timer__value').text()).toBe('1:04:05');
    });

    it('sem agendamento conta desde a abertura', async () => {
        const wrapper = mountTimer({ flow: null });

        expect(wrapper.find('.pmr-timer__value').text()).toBe('00:00');
        expect(wrapper.attributes('title')).toBe('Contando desde a abertura do prontuário');

        await vi.advanceTimersByTimeAsync(90_000);
        expect(wrapper.find('.pmr-timer__value').text()).toBe('01:30');
    });

    it('atendimento já finalizado mostra a duração final, parada', async () => {
        const wrapper = mountTimer({
            flow: flow({ situation: 7, started_at: '2026-10-06T09:00:00', finished_at: '2026-10-06T09:18:00' }),
        });

        expect(wrapper.text()).toContain('Atendimento: 18 min');
        await vi.advanceTimersByTimeAsync(120_000);
        expect(wrapper.text()).toContain('Atendimento: 18 min');
        expect(vi.getTimerCount()).toBe(0);
    });

    it('prontuário assinado (sem horários) ou atendido sem histórico: não aparece', () => {
        expect(mountTimer({ flow: null, locked: true }).find('[data-testid="pmr-timer"]').exists()).toBe(false);
        expect(
            mountTimer({ flow: flow({ situation: 7, started_at: null }) })
                .find('[data-testid="pmr-timer"]')
                .exists(),
        ).toBe(false);
    });

    it('sair da tela para o relógio', () => {
        const wrapper = mountTimer({ flow: null });
        expect(vi.getTimerCount()).toBe(1);
        wrapper.unmount();
        expect(vi.getTimerCount()).toBe(0);
    });
});

describe('Autosave — composable', () => {
    function harness(state, options = {}) {
        let api = null;
        const Comp = defineComponent({
            setup() {
                api = useRecordAutosave({
                    url: () => '/draft',
                    enabled: () => state.enabled ?? true,
                    payload: () => ({ ...state.data }),
                    saved: () => ({ ...state.saved }),
                    context: () => state.context ?? { medical_record_id: 'r1' },
                    ...options,
                });
                return () => h('div');
            },
        });
        const wrapper = mount(Comp);
        return { wrapper, api: () => api };
    }

    it('a cada 30 s grava só se mudou (e não é igual ao prontuário salvo)', async () => {
        const state = { data: { main_complaint: 'A' }, saved: { main_complaint: 'A' } };
        harness(state);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(globalThis.fetch).not.toHaveBeenCalled(); // igual ao salvo

        state.data.main_complaint = 'A, com dor';
        await vi.advanceTimersByTimeAsync(30_000);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/draft');
        expect(init.method).toBe('PUT');
        expect(JSON.parse(init.body)).toEqual({ payload: { main_complaint: 'A, com dor' }, medical_record_id: 'r1' });

        await vi.advanceTimersByTimeAsync(30_000);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1); // nada novo
    });

    it('ocultar a aba grava na hora com keepalive', async () => {
        const state = { data: { hda: 'novo' }, saved: { hda: '' } };
        harness(state);

        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(globalThis.fetch.mock.calls[0][1].keepalive).toBe(true);
    });

    it('prontuário assinado (423) para de tentar; desligado não grava', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(423, { message: 'assinado' }));
        const state = { data: { hda: 'x' }, saved: { hda: '' } };
        const { api } = harness(state);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(api().status.value).toBe('locked');
        state.data.hda = 'y';
        await vi.advanceTimersByTimeAsync(60_000);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);

        globalThis.fetch = vi.fn(() => jsonResponse(200));
        harness({ data: { hda: 'z' }, saved: {}, enabled: false });
        await vi.advanceTimersByTimeAsync(60_000);
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('falha de rede vira status de erro (sem erro solto) e tenta de novo no próximo ciclo', async () => {
        globalThis.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        const { api } = harness({ data: { hda: 'x' }, saved: {} });

        await vi.advanceTimersByTimeAsync(30_000);
        expect(api().status.value).toBe('error');

        globalThis.fetch = vi.fn(() => jsonResponse(200, { saved_at_label: '10:31' }));
        await vi.advanceTimersByTimeAsync(30_000);
        expect(api().status.value).toBe('saved');
        expect(api().savedAtLabel.value).toBe('10:31');
    });

    it('sair da tela para o intervalo e o ouvinte da aba; nada vai para o armazenamento do navegador', async () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem');
        const removeListener = vi.spyOn(document, 'removeEventListener');
        const { wrapper } = harness({ data: { hda: 'x' }, saved: {} });

        await vi.advanceTimersByTimeAsync(30_000);
        wrapper.unmount();

        expect(vi.getTimerCount()).toBe(0);
        expect(removeListener).toHaveBeenCalledWith('visibilitychange', expect.any(Function));
        expect(setItem).not.toHaveBeenCalled();
    });

    it('recuperar/descartar usam o contexto da tela na URL', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(200, { payload: { hda: 'rascunho' } }));
        const { api } = harness({ data: {}, saved: {}, context: { schedule_id: 's9' } });

        expect(await api().recover()).toEqual({ hda: 'rascunho' });
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/draft?schedule_id=s9');

        await api().discard();
        expect(globalThis.fetch.mock.calls[1][0]).toBe('/draft?schedule_id=s9');
        expect(globalThis.fetch.mock.calls[1][1].method).toBe('DELETE');
    });
});

describe('Autosave — no prontuário', () => {
    it('digitou: grava o rascunho do prontuário em edição (só campos do prontuário)', async () => {
        const wrapper = mountForm();
        await flushPromises();
        await wrapper.find('[name="main_complaint"]').setValue('Dor ocular');

        await vi.advanceTimersByTimeAsync(30_000);

        const [[url, init]] = draftCalls();
        const body = JSON.parse(init.body);
        expect(url).toBe('/draft');
        expect(body.medical_record_id).toBe('r1');
        expect(body.payload.main_complaint).toBe('Dor ocular');
        expect(body.payload).not.toHaveProperty('doctor_id');
        expect(body.payload).not.toHaveProperty('flow_action');
        expect(wrapper.find('[data-testid="pmr-autosave-status"]').text()).toContain('Rascunho salvo às 10:31');
    });

    it('prontuário assinado ou sem permissão (sem URL): nunca grava rascunho', async () => {
        const locked = mountForm({ medicalrecord: record({ is_locked: true }) });
        await flushPromises();
        await vi.advanceTimersByTimeAsync(60_000);
        expect(draftCalls()).toHaveLength(0);
        expect(locked.find('[data-testid="pmr-autosave-status"]').exists()).toBe(false);

        const noUrl = mountForm({ urls: { draft: null } });
        await flushPromises();
        await noUrl.find('[name="main_complaint"]').setValue('Outra coisa');
        await vi.advanceTimersByTimeAsync(60_000);
        expect(draftCalls()).toHaveLength(0);
    });

    it('rascunho mais novo: oferece recuperar e aplica no formulário', async () => {
        globalThis.fetch = vi.fn((url) =>
            String(url).startsWith('/draft')
                ? jsonResponse(200, {
                      payload: {
                          main_complaint: 'Recuperado',
                          clinical_conduct: 'Colírio X',
                          diagnosis_cids: [{ code: 'H10', description: 'Conjuntivite' }],
                      },
                  })
                : jsonResponse(200),
        );
        const wrapper = mountForm({
            draft: { updated_at: '2026-10-06T10:20:00', updated_at_label: '06/10/2026 10:20' },
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="pmr-draft-offer"]').text()).toContain('06/10/2026 10:20');

        await wrapper.find('[data-testid="pmr-draft-recover"]').trigger('click');
        await flushPromises();

        expect(draftCalls()[0][0]).toBe('/draft?medical_record_id=r1');
        expect(wrapper.find('[name="main_complaint"]').element.value).toBe('Recuperado');
        expect(wrapper.find('[data-testid="pmr-draft-offer"]').exists()).toBe(false);
        expect(window.showSuccessToast).toHaveBeenCalled();
    });

    it('descartar apaga o rascunho no servidor e some a oferta', async () => {
        const wrapper = mountForm({ draft: { updated_at: '2026-10-06T10:20:00', updated_at_label: '10:20' } });
        await flushPromises();

        await wrapper.find('[data-testid="pmr-draft-discard"]').trigger('click');
        await flushPromises();

        expect(draftCalls()[0][1].method).toBe('DELETE');
        expect(wrapper.find('[data-testid="pmr-draft-offer"]').exists()).toBe(false);
    });

    it('salvou o prontuário: o próximo ciclo não regrava o mesmo conteúdo', async () => {
        const wrapper = mountForm();
        await flushPromises();
        await wrapper.find('[name="main_complaint"]').setValue('Dor ocular');
        await wrapper.find('form').trigger('submit');
        await wrapper.setProps({ medicalrecord: record({ main_complaint: 'Dor ocular' }) });
        inertia.submits.at(-1).options.onSuccess();
        inertia.submits.at(-1).options.onFinish();
        await flushPromises();

        await vi.advanceTimersByTimeAsync(30_000);
        expect(draftCalls()).toHaveLength(0);
    });
});

describe('Duração por extenso', () => {
    it('menos de 1 minuto não vira "0 min"; horas com minutos em 2 dígitos', async () => {
        const { formatDuration } = await import('@/composables/useAttendanceClock.js');
        const labels = { minutes: ':m min', hours: ':h h :m min', underMinute: 'menos de 1 min' };

        expect(formatDuration(38_000, labels)).toBe('menos de 1 min');
        expect(formatDuration(23 * 60_000, labels)).toBe('23 min');
        expect(formatDuration(65 * 60_000, labels)).toBe('1 h 05 min');
    });
});
