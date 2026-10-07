import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * "1 error" solto do Vitest (sem teste falhando) na rodada do Bloco A: timers
 * de telas da Agenda sobreviviam à tela — o "salvo" de 3 s da escala do
 * médico, a prévia da série (400 ms) e as buscas de paciente (350/400 ms) — e
 * disparavam depois do fim do arquivo de teste (re-render/fetch num ambiente
 * já desmontado); a prévia da série e as buscas também deixavam a promessa
 * rejeitar sem catch em falha de rede. Aqui: sair da tela não deixa timer
 * pendente e falha de rede não vira erro solto.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, usePage: () => ({ props: { t_ui: { patient_form: {} }, locale: 'pt_BR' } }) };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const SearchSelectStub = { props: ['modelValue', 'options'], template: '<div />' };
const SlotPickerStub = { props: ['modelValue'], template: '<div />', methods: { setDate() {}, reset() {} } };
const CenteredModalStub = { props: ['open'], template: '<div><slot /><slot name="footer" /></div>' };

const ScheduleFormModal = (await import('@/Pages/Panel/Schedules/ScheduleFormModal.vue')).default;
const WaitingListFormModal = (await import('@/Pages/Panel/Schedules/WaitingListFormModal.vue')).default;
const WorkSchedule = (await import('@/Pages/Panel/Doctors/WorkSchedule.vue')).default;
const ScheduleDetailDrawer = (await import('@/Pages/Panel/Schedules/ScheduleDetailDrawer.vue')).default;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

let unhandled = [];
const onUnhandled = (reason) => unhandled.push(reason);

beforeEach(() => {
    vi.useFakeTimers();
    unhandled = [];
    process.on('unhandledRejection', onUnhandled);
    globalThis.fetch = vi.fn(() => jsonResponse({ message: 'Salvo' }));
});

afterEach(() => {
    process.off('unhandledRejection', onUnhandled);
    vi.useRealTimers();
});

async function mountScheduleForm() {
    const wrapper = mount(ScheduleFormModal, {
        props: { open: false, doctors: [{ id: 'd1', name: 'DRA' }], storeUrl: '/store', t: {} },
        global: {
            stubs: {
                SearchSelect: SearchSelectStub,
                CenteredModal: CenteredModalStub,
                SlotPicker: SlotPickerStub,
                PatientFormSections: true,
            },
            directives: { mask: {} },
        },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    return wrapper;
}

describe('timers das telas da Agenda morrem com a tela', () => {
    it('escala do médico: o aviso "salvo" (3 s) não fica pendente depois de sair', async () => {
        const wrapper = mount(WorkSchedule, {
            props: {
                doctor: { id: 'd1', name: 'DRA', color: '#000', photo_url: '/x.png', code: 'DOC-1' },
                days: [{ day: 1, name: 'Segunda', active: true, ranges: [{ starts_at: '08:00', ends_at: '12:00' }] }],
                urls: { sync: '/sync', store_block: '/b', destroy_block: '/b', back: '/back' },
                t: {},
            },
            global: { stubs: { SearchSelect: true } },
        });

        const setSpy = vi.spyOn(globalThis, 'setTimeout');
        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('Salvar'))
            .trigger('click');
        await flushPromises();

        const index = setSpy.mock.calls.findIndex((call) => call[1] === 3000);
        expect(index).toBeGreaterThan(-1); // o aviso "salvo" de 3 s
        const timerId = setSpy.mock.results[index].value;

        const clearSpy = vi.spyOn(globalThis, 'clearTimeout');
        wrapper.unmount();
        expect(clearSpy).toHaveBeenCalledWith(timerId);
    });

    it('agendamento: a prévia da série (debounce) não roda depois de fechar a tela', async () => {
        const wrapper = await mountScheduleForm();
        const state = wrapper.vm.$.setupState;
        state.form.date_time = '2030-01-07T09:00';
        state.form.use_recurrence = true;
        state.form.recurrence_type = 'weekly';
        state.form.recurrence_end = 'count';
        state.form.recurrence_count = 3;
        await flushPromises();

        wrapper.unmount();
        globalThis.fetch.mockClear();
        await vi.advanceTimersByTimeAsync(1000);

        expect(vi.getTimerCount()).toBe(0);
        expect(globalThis.fetch.mock.calls.some((c) => String(c[0]).includes('series.preview'))).toBe(false);
    });

    it('agendamento: prévia da série e busca de paciente com a rede fora não deixam erro solto', async () => {
        globalThis.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        const wrapper = await mountScheduleForm();
        const state = wrapper.vm.$.setupState;
        state.form.date_time = '2030-01-07T09:00';
        state.form.use_recurrence = true;
        state.form.recurrence_type = 'weekly';
        state.form.recurrence_end = 'count';
        state.form.recurrence_count = 3;
        await flushPromises();
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        state.patientSearch = 'jo';
        state.onPatientInput();
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        expect(unhandled).toEqual([]);
        expect(state.seriesLoading).toBe(false);
        expect(state.patientResults).toEqual([]);
        wrapper.unmount();
    });

    it('lista de espera: a busca de paciente (debounce) não roda depois de fechar; rede fora não vira erro', async () => {
        const wrapper = mount(WaitingListFormModal, {
            props: { open: true, t: {} },
            global: {
                stubs: { SearchSelect: SearchSelectStub, OffcanvasPanel: CenteredModalStub },
                directives: { mask: {} },
            },
        });
        const state = wrapper.vm.$.setupState;

        state.patientSearch = 'ma';
        state.onPatientInput();
        wrapper.unmount();
        globalThis.fetch.mockClear();
        await vi.advanceTimersByTimeAsync(1000);
        expect(globalThis.fetch).not.toHaveBeenCalled();

        globalThis.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        const other = mount(WaitingListFormModal, {
            props: { open: true, t: {} },
            global: {
                stubs: { SearchSelect: SearchSelectStub, OffcanvasPanel: CenteredModalStub },
                directives: { mask: {} },
            },
        });
        await other.vm.$.setupState.searchPatients();
        await flushPromises();
        expect(unhandled).toEqual([]);
        other.unmount();
    });

    it('detalhe do agendamento: área de transferência recusada não vira erro solto; o "copiado" morre com a tela', async () => {
        const writeText = vi.fn(() => Promise.reject(new DOMException('denied')));
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        globalThis.fetch = vi.fn(() =>
            jsonResponse({ data: { id: 's1', code: 'SDL-0000000001', resources: [], situation_logs: [] } }),
        );
        const wrapper = mount(ScheduleDetailDrawer, {
            props: { open: false, scheduleId: 's1', t: {} },
            global: { stubs: { teleport: true } },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        wrapper.vm.$.setupState.copyCode('schedule', 'SDL-0000000001');
        await flushPromises();
        expect(writeText).toHaveBeenCalled();
        expect(unhandled).toEqual([]);

        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn(() => Promise.resolve()) },
        });
        wrapper.vm.$.setupState.copyCode('schedule', 'SDL-0000000001');
        await flushPromises();
        expect(vi.getTimerCount()).toBe(1);
        wrapper.unmount();
        expect(vi.getTimerCount()).toBe(0);
    });
});
