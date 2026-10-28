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
                series_id: 'synthetic-series',
                series_future_count: 7,
                reschedule_series_url: '/series/s3/reschedule',
                reschedule_series_preview_url: '/series/s3/reschedule-preview',
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
            series_id: 'synthetic-series',
            series_future_count: 7,
            reschedule_series_url: '/series/s3/reschedule',
            reschedule_series_preview_url: '/series/s3/reschedule-preview',
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

describe('Remarcar — série', () => {
    const texts = {
        reschedule_save: 'Confirmar remarcação',
        reschedule_title: 'Reagendar',
        reschedule_cancel: 'Cancelar',
        reschedule_doctor: 'Médico',
        form_fit_in: 'Encaixe',
        series_ok: 'Livre',
        series_reschedule_scope: 'Remarcar',
        series_reschedule_one: 'Só esta sessão',
        series_reschedule_following: 'Esta e as seguintes (:count)',
        series_reschedule_hint: 'As sessões anteriores e encerradas ficam como estão.',
        series_reschedule_doctor_hint: 'O médico escolhido vale para todas as sessões afetadas.',
        series_reschedule_fit_in_hint: 'O encaixe escolhido vale para todas as sessões afetadas.',
        series_reschedule_preview: 'Sessões que serão remarcadas',
        series_reschedule_loading: 'Conferindo as sessões…',
        series_reschedule_conflicts: 'Há conflito em :count sessão(ões). Ajuste o horário para continuar.',
        series_reschedule_error: 'Não foi possível conferir a série. Tente de novo.',
        series_reschedule_blocked: 'Resolva os conflitos para confirmar.',
    };
    const anchor = {
        id: 'session-2',
        doctor_id: 'doctor-1',
        name: 'Paciente sintético',
        time: '09:00',
        is_fit_in: false,
        series_id: 'series-synthetic',
        series_future_count: 3,
        reschedule_url: '/session-2/reschedule',
        reschedule_series_url: '/session-2/series/reschedule',
        reschedule_series_preview_url: '/session-2/series/reschedule-preview',
    };
    const preview = {
        total: 3,
        delta_seconds: 604800,
        sessions: [2, 3, 4].map((number) => ({
            id: `session-${number}`,
            session_number: number,
            session_total: 4,
            doctor_id: 'doctor-1',
            is_fit_in: false,
            old_label: `0${number}/01/2030 09:00`,
            new_label: `0${number + 7}/01/2030 09:00`,
            errors: [],
        })),
    };
    let wrapper;
    let hide;
    let focusTrigger;
    const confirm = () => wrapper.get('[data-test="reschedule-confirm"]');
    const state = () => wrapper.vm.$.setupState;
    const posts = () => globalThis.fetch.mock.calls.filter((call) => call[1]?.method === 'POST');

    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        hide = vi.fn();
        globalThis.bootstrap = window.bootstrap = {
            Modal: class {
                show() {}
                hide() {
                    hide();
                }
            },
        };
        globalThis.fetch = vi.fn(() => jsonResponse(preview));
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        focusTrigger?.remove();
        vi.useRealTimers();
    });

    async function render(item = anchor, translation = texts) {
        const RescheduleModal = (await import('@/Pages/Panel/Schedules/RescheduleModal.vue')).default;
        wrapper = mount(RescheduleModal, {
            props: {
                item: null,
                doctors: [
                    { id: 'doctor-1', name: 'Médico sintético A' },
                    { id: 'doctor-2', name: 'Médico sintético B' },
                ],
                t: translation,
            },
            global: { stubs: { SlotPicker: SlotPickerStub, SearchSelect: SearchSelectStub, teleport: true } },
            attachTo: document.body,
        });
        await wrapper.setProps({ item: { ...item } });
        await flushPromises();
        return wrapper;
    }
    async function chooseFollowing() {
        await wrapper.get('#reschedule-scope-following').setValue();
        state().dateTime = '2030-01-09T09:00';
        await flushPromises();
    }
    async function settlePreview() {
        await vi.advanceTimersByTimeAsync(350);
        await flushPromises();
    }

    it.each([
        { series_id: null, series_future_count: 3 },
        { series_id: 'series-synthetic', series_future_count: 1 },
    ])('sem mais de uma sessão futura preserva o POST unitário: %j', async (fields) => {
        await render({ ...anchor, ...fields });
        expect(wrapper.find('[data-test="series-reschedule-scope"]').exists()).toBe(false);
        state().dateTime = '2030-01-09T09:00';
        await flushPromises();
        globalThis.fetch.mockResolvedValueOnce(await jsonResponse({ new_schedule: { id: 'new-one' } }, 201));
        await confirm().trigger('click');
        await flushPromises();
        expect(posts()).toHaveLength(1);
        expect(posts()[0][0]).toBe(anchor.reschedule_url);
        expect(JSON.parse(posts()[0][1].body)).toEqual({
            date_time: '2030-01-09T09:00',
            doctor_id: 'doctor-1',
            is_fit_in: false,
        });
    });

    it('rádios têm legend, labels e contagem, e respeitam o idioma recebido', async () => {
        await render(anchor, {
            ...texts,
            series_reschedule_scope: 'Reschedule scope',
            series_reschedule_following: 'This and the following (:count)',
        });
        const fieldset = wrapper.get('[data-test="series-reschedule-scope"]');
        expect(fieldset.element.tagName).toBe('FIELDSET');
        expect(fieldset.get('legend').text()).toBe('Reschedule scope');
        expect(fieldset.get('label[for="reschedule-scope-following"]').text()).toBe('This and the following (3)');
        expect(fieldset.get('label[for="reschedule-scope-one"]').exists()).toBe(true);
        expect(fieldset.get('#reschedule-scope-one').element.checked).toBe(true);
        expect(wrapper.get(`#${fieldset.attributes('aria-describedby')}`).text()).toBe(texts.series_reschedule_hint);
    });

    it('prévia usa GET após o debounce, preserva overrides omitidos e mostra datas do servidor', async () => {
        await render();
        await chooseFollowing();
        expect(confirm().element.disabled).toBe(true);
        expect(wrapper.text()).toContain(texts.series_reschedule_loading);
        expect(globalThis.fetch).not.toHaveBeenCalled();
        await settlePreview();
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        const [url, options] = globalThis.fetch.mock.calls[0];
        expect(url.split('?')[0]).toBe(anchor.reschedule_series_preview_url);
        const params = new URL(url, 'http://localhost').searchParams;
        expect([...params.entries()]).toEqual([['date_time', '2030-01-09T09:00']]);
        expect(options.headers.Accept).toBe('application/json');
        expect(options.signal).toBeInstanceOf(AbortSignal);
        const section = wrapper.get('[data-test="series-reschedule-preview"]');
        expect(section.attributes('aria-live')).toBe('polite');
        expect(section.text()).toContain('#2/4');
        expect(section.text()).toContain(preview.sessions[0].old_label);
        expect(section.text()).toContain(preview.sessions[0].new_label);
        expect(confirm().element.disabled).toBe(false);
    });

    it('um conflito bloqueia toda a série, mostra motivo acessível e não permite POST direto', async () => {
        globalThis.fetch.mockImplementation(() =>
            jsonResponse({ ...preview, sessions: [{ ...preview.sessions[0], errors: ['Horário ocupado'] }] }),
        );
        await render();
        await chooseFollowing();
        await settlePreview();
        expect(confirm().element.disabled).toBe(true);
        expect(wrapper.get('[role="alert"]').text()).toContain('1 sessão(ões)');
        expect(wrapper.text()).toContain('Horário ocupado');
        expect(wrapper.get(`#${confirm().attributes('aria-describedby')}`).text()).toBe(
            texts.series_reschedule_blocked,
        );
        expect(confirm().attributes('title')).toBe(texts.series_reschedule_blocked);
        await wrapper.vm.onConfirm();
        expect(posts()).toHaveLength(0);
    });

    it.each([422, 403, 404, 500])(
        'erro HTTP %s na prévia mantém confirmação bloqueada e exibe mensagem',
        async (status) => {
            globalThis.fetch.mockImplementation(() =>
                jsonResponse({ message: 'Prévia indisponível pelo servidor.' }, status),
            );
            await render();
            await chooseFollowing();
            await settlePreview();
            expect(wrapper.get('[role="alert"]').text()).toBe('Prévia indisponível pelo servidor.');
            expect(confirm().element.disabled).toBe(true);
            expect(wrapper.findAll('[data-test="series-reschedule-preview"] li')).toHaveLength(0);
        },
    );

    it('falha de rede usa tradução e bloqueia; uma nova prévia permite tentar novamente', async () => {
        globalThis.fetch.mockRejectedValueOnce(new Error('Network'));
        await render();
        await chooseFollowing();
        await settlePreview();
        expect(wrapper.get('[role="alert"]').text()).toBe(texts.series_reschedule_error);
        expect(confirm().element.disabled).toBe(true);
        state().dateTime = '2030-01-10T09:00';
        await settlePreview();
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(confirm().element.disabled).toBe(false);
    });

    it('confirma via POST só com data quando não há overrides e emite anchor_id no 201', async () => {
        await render();
        await chooseFollowing();
        await settlePreview();
        const result = { message: 'Série remarcada.', anchor_id: 'new-anchor', updated: 3, new_schedules: [] };
        globalThis.fetch.mockResolvedValueOnce(await jsonResponse(result, 201));
        const meta = document.querySelector('meta[name="csrf-token"]');
        const oldToken = meta.content;
        meta.content = 'synthetic-csrf';
        try {
            await confirm().trigger('click');
            await flushPromises();
            const [url, options] = posts()[0];
            expect(url).toBe(anchor.reschedule_series_url);
            expect(JSON.parse(options.body)).toEqual({ date_time: '2030-01-09T09:00' });
            expect(options.headers['X-CSRF-TOKEN']).toBe('synthetic-csrf');
            expect(hide).toHaveBeenCalled();
            expect(wrapper.emitted('rescheduled')[0]).toEqual([result]);
        } finally {
            meta.content = oldToken;
        }
    });

    it.each([true, false])('médico alterado e encaixe %s aparecem nos hints, GET e POST', async (fitIn) => {
        await render({ ...anchor, is_fit_in: !fitIn });
        await chooseFollowing();
        await wrapper.get('.ss').setValue('doctor-2');
        await wrapper.get('#reschedule-fit-in').setValue(fitIn);
        await settlePreview();
        expect(wrapper.get('[data-test="series-doctor-hint"]').text()).toBe(texts.series_reschedule_doctor_hint);
        expect(wrapper.get('[data-test="series-fit-in-hint"]').text()).toBe(texts.series_reschedule_fit_in_hint);
        const params = new URL(globalThis.fetch.mock.calls[0][0], 'http://localhost').searchParams;
        expect(params.get('doctor_id')).toBe('doctor-2');
        expect(params.get('is_fit_in')).toBe(fitIn ? '1' : '0');
        globalThis.fetch.mockResolvedValueOnce(await jsonResponse({ anchor_id: 'new-anchor' }, 201));
        await confirm().trigger('click');
        await flushPromises();
        expect(JSON.parse(posts()[0][1].body)).toEqual({
            date_time: '2030-01-09T09:00',
            doctor_id: 'doctor-2',
            is_fit_in: fitIn,
        });
    });

    it('409 limpa a prévia imediatamente, mostra a mensagem e refaz a conferência antes de tentar', async () => {
        await render();
        await chooseFollowing();
        await settlePreview();
        globalThis.fetch.mockResolvedValueOnce(await jsonResponse({ message: 'A série mudou. Tente de novo.' }, 409));
        await confirm().trigger('click');
        await flushPromises();
        expect(wrapper.emitted('rescheduled')).toBeUndefined();
        expect(wrapper.get('[role="alert"]').text()).toBe('A série mudou. Tente de novo.');
        expect(confirm().element.disabled).toBe(true);
        expect(wrapper.findAll('[data-test="series-reschedule-preview"] li')).toHaveLength(0);
        await settlePreview();
        expect(globalThis.fetch).toHaveBeenCalledTimes(3);
        expect(confirm().element.disabled).toBe(false);
    });

    it('422 na escrita mantém o modal aberto e mostra a mensagem, sem afirmar sucesso', async () => {
        await render();
        await chooseFollowing();
        await settlePreview();
        globalThis.fetch.mockResolvedValueOnce(await jsonResponse({ message: 'Conflito novo na sala.' }, 422));
        await confirm().trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe('Conflito novo na sala.');
        expect(wrapper.emitted('rescheduled')).toBeUndefined();
        expect(hide).not.toHaveBeenCalled();
    });

    it('duas alterações rápidas fazem só um GET e invalidam a prévia antes do debounce', async () => {
        await render();
        await chooseFollowing();
        await settlePreview();
        globalThis.fetch.mockClear();
        state().dateTime = '2030-01-10T09:00';
        await flushPromises();
        expect(confirm().element.disabled).toBe(true);
        await vi.advanceTimersByTimeAsync(200);
        state().dateTime = '2030-01-11T09:00';
        await flushPromises();
        await settlePreview();
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(new URL(globalThis.fetch.mock.calls[0][0], 'http://localhost').searchParams.get('date_time')).toBe(
            '2030-01-11T09:00',
        );
    });

    it('resposta antiga não restaura uma prévia nem libera a confirmação de outra data', async () => {
        let resolveOld;
        globalThis.fetch.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveOld = resolve;
                }),
        );
        await render();
        await chooseFollowing();
        await settlePreview();
        const firstSignal = globalThis.fetch.mock.calls[0][1].signal;
        state().dateTime = '2030-01-10T09:00';
        await flushPromises();
        expect(firstSignal.aborted).toBe(true);
        resolveOld(
            await jsonResponse({ ...preview, sessions: [{ ...preview.sessions[0], new_label: 'RESPOSTA ANTIGA' }] }),
        );
        await flushPromises();
        expect(wrapper.text()).not.toContain('RESPOSTA ANTIGA');
        expect(confirm().element.disabled).toBe(true);
        await settlePreview();
        expect(confirm().element.disabled).toBe(false);
        expect(wrapper.text()).not.toContain('RESPOSTA ANTIGA');
    });

    it('voltar para só esta limpa conflitos e não deixa o debounce fazer outra chamada', async () => {
        await render();
        await chooseFollowing();
        await wrapper.get('#reschedule-scope-one').setValue();
        await settlePreview();
        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="series-reschedule-preview"]').exists()).toBe(false);
        expect(confirm().element.disabled).toBe(false);
    });

    it('fechar pelo evento nativo cancela a prévia e reabrir em outra sessão começa em só esta', async () => {
        await render();
        await chooseFollowing();
        wrapper.get('#rescheduleModal').element.dispatchEvent(new Event('hidden.bs.modal'));
        await settlePreview();
        expect(wrapper.emitted('close')).toBeTruthy();
        expect(globalThis.fetch).not.toHaveBeenCalled();
        await wrapper.setProps({ item: { ...anchor, id: 'session-other' } });
        await flushPromises();
        expect(wrapper.get('#reschedule-scope-one').element.checked).toBe(true);
        expect(wrapper.find('[data-test="series-reschedule-preview"]').exists()).toBe(false);
        expect(confirm().element.disabled).toBe(true);
    });

    it('desmontar descarta o debounce pendente', async () => {
        await render();
        await chooseFollowing();
        wrapper.unmount();
        wrapper = null;
        await settlePreview();
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('URLs ausentes não provocam chamadas inventadas nem liberam confirmar', async () => {
        await render({ ...anchor, reschedule_series_preview_url: null, reschedule_series_url: null });
        await chooseFollowing();
        await settlePreview();
        expect(wrapper.get('[role="alert"]').text()).toBe(texts.series_reschedule_error);
        expect(confirm().element.disabled).toBe(true);
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('prévia vazia é tratada como falha e nunca libera o lote', async () => {
        globalThis.fetch.mockImplementation(() => jsonResponse({ total: 0, sessions: [] }));
        await render();
        await chooseFollowing();
        await settlePreview();
        expect(confirm().element.disabled).toBe(true);
        expect(wrapper.get('[role="alert"]').text()).toBe(texts.series_reschedule_error);
    });

    it('foco entra no diálogo ao abrir e retorna ao gatilho depois do dismiss', async () => {
        focusTrigger = document.createElement('button');
        document.body.append(focusTrigger);
        focusTrigger.focus();
        await render();
        wrapper.get('#rescheduleModal').element.dispatchEvent(new Event('shown.bs.modal'));
        await flushPromises();
        expect(wrapper.get('#rescheduleModal').element.contains(document.activeElement)).toBe(true);
        wrapper.get('#rescheduleModal').element.dispatchEvent(new Event('hidden.bs.modal'));
        await flushPromises();
        expect(document.activeElement).toBe(focusTrigger);
    });
});

describe('Agenda — âncora depois de reagendar', () => {
    it.each([
        { anchor_id: 'new-series-anchor', new_schedule: { id: 'ignored-unitary' } },
        { new_schedule: { id: 'new-unitary' } },
    ])('mantém o drawer aberto no novo agendamento e atualiza lista e contadores: %j', async (result) => {
        const { shallowMount } = await import('@vue/test-utils');
        const { router } = await import('@inertiajs/vue3');
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => {});
        const Index = (await import('@/Pages/Panel/Schedules/Index.vue')).default;
        const success = vi.fn();
        const oldToast = window.showSuccessToast;
        window.showSuccessToast = success;
        const wrapper = shallowMount(Index, {
            props: { t: {}, filters: {} },
            global: { mocks: { route: globalThis.route }, stubs: { AppLayout: { template: '<div><slot /></div>' } } },
        });
        try {
            const drawer = wrapper.findComponent(ScheduleDetailDrawer);
            const RescheduleModal = (await import('@/Pages/Panel/Schedules/RescheduleModal.vue')).default;
            wrapper.vm.onView({ id: 'old-anchor' });
            await flushPromises();
            expect(drawer.props('scheduleId')).toBe('old-anchor');
            wrapper.findComponent(RescheduleModal).vm.$emit('rescheduled', { ...result, message: 'Concluído' });
            await flushPromises();
            expect(drawer.props('open')).toBe(true);
            expect(drawer.props('scheduleId')).toBe(result.anchor_id ?? result.new_schedule.id);
            expect(router.reload).toHaveBeenCalledWith({ only: ['scheduleItems', 'counters'] });
            expect(success).toHaveBeenCalledWith('Concluído');
        } finally {
            wrapper.unmount();
            window.showSuccessToast = oldToast;
            reload.mockRestore();
        }
    });
});
