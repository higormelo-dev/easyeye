import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Fase 1 — formulários: encaixe (agendar e remarcar), série de sessões
 * (prévia com conflitos, remover datas, tudo ou nada), "Cancelar as futuras"
 * no detalhe, teto de encaixes na escala do médico e "poucas vagas" nas
 * configurações.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, usePage: () => ({ props: { t_ui: { patient_form: {} }, locale: 'pt_BR' } }) };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const SearchSelectStub = {
    props: ['modelValue', 'options'],
    emits: ['update:modelValue'],
    template:
        '<select class="ss" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o.value ?? o.id" :value="o.value ?? o.id">{{ o.label ?? o.name }}</option></select>',
};
const SlotPickerStub = {
    props: ['modelValue', 'allowOccupied'],
    template: '<div class="slot-picker" :data-allow-occupied="String(allowOccupied)" />',
    methods: { setDate() {}, reset() {} },
};
const CenteredModalStub = { props: ['open'], template: '<div><slot /><slot name="footer" /></div>' };

const ScheduleFormModal = (await import('@/Pages/Panel/Schedules/ScheduleFormModal.vue')).default;
const ScheduleDetailDrawer = (await import('@/Pages/Panel/Schedules/ScheduleDetailDrawer.vue')).default;
const WorkSchedule = (await import('@/Pages/Panel/Doctors/WorkSchedule.vue')).default;
const ScheduleSettings = (await import('@/Pages/Panel/Settings/Schedule/Index.vue')).default;

const t = {
    form_save_link: 'Salvar',
    form_fit_in: 'Encaixe',
    form_recurrence: 'Repetir (série de sessões)',
    form_weekly: 'Semanal',
    form_monthly: 'Mensal',
    form_weekdays: 'Dias da semana',
    series_summary: ':count sessões',
    series_conflicts_count: ':count com conflito',
    series_remove_conflicts: 'Desmarque as datas com conflito para salvar.',
};

const previewResponse = {
    sessions: [
        { number: 1, date_time: '2030-01-07T09:00', errors: [] },
        { number: 2, date_time: '2030-01-09T09:00', errors: ['Horário ocupado'] },
        { number: 3, date_time: '2030-01-14T09:00', errors: [] },
    ],
    truncated: false,
    max: 60,
};

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

async function mountForm() {
    const wrapper = mount(ScheduleFormModal, {
        props: { open: false, doctors: [{ id: 'd1', name: 'DRA' }], storeUrl: '/store', t },
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

const saveButton = (wrapper) => wrapper.findAll('button').find((b) => b.text() === 'Salvar');

describe('ScheduleFormModal — encaixe', () => {
    beforeEach(() => {
        globalThis.fetch = vi.fn(() => jsonResponse({ message: 'ok' }, 201));
    });

    it('marcar "Encaixe" libera horário ocupado no seletor e vai no envio', async () => {
        const wrapper = await mountForm();
        expect(wrapper.find('.slot-picker').attributes('data-allow-occupied')).toBe('false');

        await wrapper.find('[data-test="fit-in-toggle"]').setValue(true);
        expect(wrapper.find('.slot-picker').attributes('data-allow-occupied')).toBe('true');

        wrapper.vm.$.setupState.form.date_time = '2030-01-07T09:00';
        wrapper.vm.$.setupState.form.full_name = 'MARIA';
        await saveButton(wrapper).trigger('click');
        await flushPromises();

        const body = JSON.parse(globalThis.fetch.mock.calls.find((c) => c[0] === '/store')[1].body);
        expect(body.is_fit_in).toBe(true);
        expect(body).not.toHaveProperty('series_dates');
    });
});

describe('ScheduleFormModal — série de sessões', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        globalThis.fetch = vi.fn((url) =>
            String(url).includes('series.preview')
                ? jsonResponse(previewResponse)
                : jsonResponse({ message: 'ok' }, 201),
        );
    });

    async function withPreview() {
        const wrapper = await mountForm();
        const state = wrapper.vm.$.setupState;
        state.form.date_time = '2030-01-07T09:00';
        state.form.full_name = 'MARIA';
        state.form.use_recurrence = true;
        state.form.recurrence_type = 'weekdays';
        state.form.recurrence_weekdays = [1, 3];
        state.form.recurrence_end = 'count';
        state.form.recurrence_count = 3;
        await flushPromises();
        vi.advanceTimersByTime(500);
        await flushPromises();
        return wrapper;
    }

    it('prévia pede as datas ao servidor com frequência, dias, fim e sala, e mostra os conflitos', async () => {
        const wrapper = await withPreview();

        const url = new URL(
            globalThis.fetch.mock.calls.find((c) => String(c[0]).includes('series.preview'))[0],
            'http://x',
        );
        expect(url.searchParams.get('frequency')).toBe('weekdays');
        expect(url.searchParams.getAll('weekdays[]')).toEqual(['1', '3']);
        expect(url.searchParams.get('count')).toBe('3');

        const preview = wrapper.find('[data-test="series-preview"]');
        expect(preview.findAll('[data-session]')).toHaveLength(3);
        expect(preview.text()).toContain('Horário ocupado');
        expect(preview.text()).toContain('1 com conflito');
    });

    it('com data em conflito marcada não salva; desmarcando, envia só as datas que ficaram (sem a 1ª)', async () => {
        const wrapper = await withPreview();

        await saveButton(wrapper).trigger('click');
        await flushPromises();
        expect(globalThis.fetch.mock.calls.some((c) => c[0] === '/store')).toBe(false);
        expect(wrapper.text()).toContain('Desmarque as datas com conflito para salvar.');

        // 1ª sessão não pode ser desmarcada.
        expect(wrapper.find('[data-session="1"] input').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-session="2"] input').trigger('change');
        expect(wrapper.find('[data-test="series-preview"]').text()).toContain('2 sessões');

        await saveButton(wrapper).trigger('click');
        await flushPromises();

        const body = JSON.parse(globalThis.fetch.mock.calls.find((c) => c[0] === '/store')[1].body);
        expect(body.series_dates).toEqual(['2030-01-14T09:00']);
        expect(body).not.toHaveProperty('recurrence_type');
    });
});

describe('ScheduleDetailDrawer — série e encaixe', () => {
    const detail = {
        id: 's3',
        code: 'SDL-0000000003',
        patient_name: 'MARIA',
        situation_label: 'Agendado',
        situation_badge: 'bg-secondary',
        resources: [],
        situation_logs: [],
        is_fit_in: true,
        session_number: 3,
        session_total: 10,
        series_cancellable: 7,
        cancel_series_url: '/series/cancel',
        patient_sheet_url: '/sheet',
        series: [
            {
                id: 's1',
                number: 1,
                total: 10,
                date_label: 'seg, 05/01/2030 09:00',
                label: 'Atendido',
                badge: 'bg-success',
                is_terminal: true,
                is_past: true,
            },
            {
                id: 's3',
                number: 3,
                total: 10,
                date_label: 'seg, 12/01/2030 09:00',
                label: 'Agendado',
                badge: 'bg-secondary',
                is_terminal: false,
                is_past: false,
                is_current: true,
                time: '09:00',
                name: 'MARIA',
                doctor_id: 'd1',
                reschedule_url: '/reschedule/s3',
            },
        ],
    };

    async function mountDrawer() {
        globalThis.fetch = vi.fn((url, options) =>
            options?.method === 'POST'
                ? jsonResponse({ message: '7 sessões futuras canceladas.', cancelled: 7 })
                : jsonResponse({ data: detail }),
        );
        const wrapper = mount(ScheduleDetailDrawer, {
            props: { open: false, scheduleId: 's3', t: { fit_in_badge: 'Encaixe', session_label: 'Sessão :session' } },
            global: { stubs: { teleport: true } },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        return wrapper;
    }

    it('mostra selos, a ficha e a série; remarcar uma sessão avisa a agenda', async () => {
        const wrapper = await mountDrawer();

        expect(wrapper.find('[data-test="drawer-fit-in"]').text()).toContain('Encaixe');
        expect(wrapper.find('[data-test="drawer-session"]').text()).toContain('Sessão 3/10');
        expect(wrapper.find('[data-test="drawer-patient-sheet"]').attributes('href')).toBe('/sheet');

        const rows = wrapper.findAll('.series-item');
        expect(rows).toHaveLength(2);
        // Sessão passada/atendida: sem remarcar.
        expect(rows[0].find('button').exists()).toBe(false);

        await rows[1].find('button').trigger('click');
        expect(wrapper.emitted('reschedule')[0][0]).toMatchObject({
            id: 's3',
            reschedule_url: '/reschedule/s3',
            doctor_id: 'd1',
        });
    });

    it('cancelar as futuras exige o motivo e envia ao servidor', async () => {
        const wrapper = await mountDrawer();

        await wrapper.find('[data-test="series-cancel-open"]').trigger('click');
        const confirm = wrapper.find('[data-test="series-cancel-confirm"]');
        expect(confirm.attributes('disabled')).toBeDefined();

        await wrapper.find('#series-cancel-reason').setValue('Tratamento encerrado');
        await wrapper.find('[data-test="series-cancel-confirm"]').trigger('click');
        await flushPromises();

        const post = globalThis.fetch.mock.calls.find((c) => c[1]?.method === 'POST');
        expect(post[0]).toBe('/series/cancel');
        expect(JSON.parse(post[1].body)).toEqual({ reason: 'Tratamento encerrado' });
        expect(wrapper.emitted('changed')).toBeTruthy();
    });
});

describe('Remarcar — encaixe', () => {
    it('começa como o agendamento é e envia a escolha', async () => {
        window.bootstrap = {
            Modal: class {
                show() {}
                hide() {}
            },
        };
        globalThis.bootstrap = window.bootstrap;
        globalThis.fetch = vi.fn(() => jsonResponse({ message: 'ok' }, 201));
        const RescheduleModal = (await import('@/Pages/Panel/Schedules/RescheduleModal.vue')).default;

        const wrapper = mount(RescheduleModal, {
            props: { item: null, doctors: [], t: { reschedule_save: 'Reagendar' } },
            global: { stubs: { SlotPicker: SlotPickerStub, SearchSelect: SearchSelectStub, teleport: true } },
            attachTo: document.body,
        });
        await wrapper.setProps({
            item: { id: 's1', doctor_id: 'd1', is_fit_in: true, reschedule_url: '/reschedule/s1' },
        });
        await flushPromises();

        expect(wrapper.find('#reschedule-fit-in').element.checked).toBe(true);
        expect(wrapper.find('.slot-picker').attributes('data-allow-occupied')).toBe('true');

        await wrapper.find('#reschedule-fit-in').setValue(false);
        wrapper.vm.$.setupState.dateTime = '2030-01-08T10:00';
        await flushPromises();
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Reagendar')
            .trigger('click');
        await flushPromises();

        expect(JSON.parse(globalThis.fetch.mock.calls.at(-1)[1].body)).toMatchObject({
            is_fit_in: false,
            date_time: '2030-01-08T10:00',
        });
        wrapper.unmount();
    });
});

describe('Escala do médico — encaixes por turno', () => {
    it('mostra o teto atual e envia os três turnos ao salvar', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse({ message: 'Salvo' }));
        const wrapper = mount(WorkSchedule, {
            props: {
                doctor: { id: 'd1', name: 'DRA', color: '#000', photo_url: '/x.png', code: 'DOC-1' },
                days: [{ day: 1, name: 'Segunda', active: true, ranges: [{ starts_at: '08:00', ends_at: '12:00' }] }],
                fitInLimits: { morning: 2, afternoon: 0, evening: 1 },
                urls: { sync: '/sync', store_block: '/b', destroy_block: '/b', back: '/back' },
                t: {},
            },
            global: { stubs: { SearchSelect: true } },
        });

        expect(wrapper.find('#fit-in-afternoon').element.value).toBe('0');

        await wrapper.find('#fit-in-morning').setValue('3');
        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('Salvar'))
            .trigger('click');
        await flushPromises();

        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body).fit_in_limits).toEqual({
            morning: 3,
            afternoon: 0,
            evening: 1,
        });
    });
});

describe('Configurações › Agenda', () => {
    it('salva o percentual de "poucas vagas"', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse({ message: 'Salvo', few_slots_percent: 25 }));
        const wrapper = mount(ScheduleSettings, {
            props: { fewSlotsPercent: 20, t: { settings_save: 'Salvar' } },
            global: { stubs: { PageHeader: true } },
        });

        await wrapper.find('#few-slots-percent').setValue('25');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const [url, options] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/_routes/panel.setting.schedule.update');
        expect(options.method).toBe('PATCH');
        expect(JSON.parse(options.body)).toEqual({ few_slots_percent: 25 });
        expect(wrapper.text()).toContain('Salvo');
    });
});
