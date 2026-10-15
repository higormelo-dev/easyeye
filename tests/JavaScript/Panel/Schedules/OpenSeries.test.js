import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

import { sessionLabel } from '@/Support/sessionLabel.js';

/**
 * Fase 6 / S12 — série "a cada N semanas" no formulário e série ABERTA (sem total) no detalhe: rótulo "n" em vez de
 * "n/total" e o botão "Acrescentar sessão", que só aparece quando o servidor manda `add_session_url`.
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
    template: '<div class="slot-picker" />',
    methods: { setDate() {}, reset() {} },
};
const CenteredModalStub = { props: ['open'], template: '<div><slot /><slot name="footer" /></div>' };

const ScheduleFormModal = (await import('@/Pages/Panel/Schedules/ScheduleFormModal.vue')).default;
const ScheduleDetailDrawer = (await import('@/Pages/Panel/Schedules/ScheduleDetailDrawer.vue')).default;

const jsonResponse = (body, status = 200) =>
    Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

describe('sessionLabel', () => {
    it('série fechada mostra n/total; aberta mostra só n; fora de série, nada', () => {
        expect(sessionLabel(3, 10)).toBe('3/10');
        expect(sessionLabel(3, null)).toBe('3');
        expect(sessionLabel(3, undefined)).toBe('3');
        expect(sessionLabel(null, null)).toBeNull();
        expect(sessionLabel(0, 5)).toBeNull();
    });
});

describe('ScheduleFormModal — a cada N semanas', () => {
    const t = {
        form_save_link: 'Salvar',
        form_recurrence: 'Repetir (série de sessões)',
        form_weekly: 'Semanal',
        form_monthly: 'Mensal',
        form_weekdays: 'Dias da semana',
        form_every_weeks: 'A cada N semanas',
        form_rec_interval: 'Intervalo (semanas)',
        form_rec_interval_hint: 'De 2 a 16 semanas entre as sessões.',
        series_summary: ':count sessões',
    };

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

    afterEach(() => {
        vi.useRealTimers();
    });

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        globalThis.fetch = vi.fn(() =>
            jsonResponse({
                sessions: [
                    { number: 1, date_time: '2030-01-07T09:00', errors: [] },
                    { number: 2, date_time: '2030-02-18T09:00', errors: [] },
                    { number: 3, date_time: '2030-03-31T09:00', errors: [] },
                ],
                truncated: false,
                max: 60,
            }),
        );
    });

    async function withEveryWeeks(interval) {
        const wrapper = await mountForm();
        const state = wrapper.vm.$.setupState;
        state.form.date_time = '2030-01-07T09:00';
        state.form.full_name = 'MARIA';
        state.form.use_recurrence = true;
        state.form.recurrence_type = 'every_weeks';
        state.form.recurrence_interval_weeks = interval;
        state.form.recurrence_end = 'count';
        state.form.recurrence_count = 3;
        await flushPromises();
        vi.advanceTimersByTime(500);
        await flushPromises();

        return wrapper;
    }

    it('oferece "A cada N semanas" e o campo do intervalo', async () => {
        const wrapper = await withEveryWeeks(6);

        expect(wrapper.html()).toContain('A cada N semanas');
        expect(wrapper.find('input[type="number"][max="16"]').exists()).toBe(true);
    });

    it('a prévia pede as datas com frequency=every_weeks e interval_weeks', async () => {
        await withEveryWeeks(6);

        const url = new URL(
            globalThis.fetch.mock.calls.find((c) => String(c[0]).includes('series.preview'))[0],
            'http://x',
        );

        expect(url.searchParams.get('frequency')).toBe('every_weeks');
        expect(url.searchParams.get('interval_weeks')).toBe('6');
    });

    it('intervalo fora de 2–16 semanas não pede prévia ao servidor', async () => {
        await withEveryWeeks(17);

        expect(globalThis.fetch.mock.calls.some((c) => String(c[0]).includes('series.preview'))).toBe(false);
    });
});

describe('ScheduleDetailDrawer — série aberta', () => {
    const detail = (overrides = {}) => ({
        id: 's2',
        code: 'SDL-0000000002',
        patient_name: 'MARIA',
        situation_label: 'Agendado',
        situation_badge: 'bg-secondary',
        resources: [],
        situation_logs: [],
        session_number: 2,
        session_total: null,
        series_cancellable: 1,
        cancel_series_url: '/series/cancel',
        add_session_url: '/series/add',
        series: [
            {
                id: 's1',
                number: 1,
                total: null,
                date_label: 'seg, 05/10/2026 09:00',
                label: 'Atendido',
                badge: 'bg-success',
                is_terminal: true,
                is_past: true,
            },
            {
                id: 's2',
                number: 2,
                total: null,
                date_label: 'seg, 02/11/2026 09:00',
                label: 'Agendado',
                badge: 'bg-secondary',
                is_terminal: false,
                is_past: false,
                is_current: true,
                time: '09:00',
                name: 'MARIA',
                doctor_id: 'd1',
                reschedule_url: '/r/s2',
            },
        ],
        ...overrides,
    });

    async function mountDrawer(payload, post = () => jsonResponse({ message: 'Sessão acrescentada à série.' }, 201)) {
        globalThis.fetch = vi.fn((url, options) =>
            options?.method === 'POST' ? post(url, options) : jsonResponse({ data: payload }),
        );
        const wrapper = mount(ScheduleDetailDrawer, {
            props: {
                open: false,
                scheduleId: 's2',
                t: {
                    session_label: 'Sessão :session',
                    series_add: 'Acrescentar sessão',
                    series_add_date: 'Data e hora da nova sessão',
                    series_add_confirm: 'Acrescentar',
                },
            },
            global: { stubs: { teleport: true } },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        return wrapper;
    }

    it('série aberta mostra "Sessão 2" (sem total) e o botão de acrescentar', async () => {
        const wrapper = await mountDrawer(detail());

        expect(wrapper.text()).toContain('Sessão 2');
        expect(wrapper.text()).not.toMatch(/Sessão 2\/\d/);
        expect(wrapper.find('[data-test="series-add-open"]').exists()).toBe(true);
    });

    it('série fechada (sem add_session_url) não oferece acrescentar', async () => {
        const wrapper = await mountDrawer(
            detail({
                add_session_url: null,
                session_total: 3,
                series: [
                    {
                        id: 's2',
                        number: 2,
                        total: 3,
                        date_label: 'x',
                        label: 'Agendado',
                        badge: 'bg-secondary',
                        is_current: true,
                    },
                ],
            }),
        );

        expect(wrapper.text()).toContain('Sessão 2/3');
        expect(wrapper.find('[data-test="series-add-open"]').exists()).toBe(false);
    });

    it('acrescentar pede a data, envia date_time à URL do servidor e avisa o pai', async () => {
        const post = vi.fn(() => jsonResponse({ message: 'Sessão acrescentada à série.' }, 201));
        const wrapper = await mountDrawer(detail(), post);

        await wrapper.find('[data-test="series-add-open"]').trigger('click');

        const confirm = wrapper.find('[data-test="series-add-confirm"]');
        expect(confirm.attributes('disabled')).toBeDefined();

        await wrapper.find('#series-add-date').setValue('2026-11-16T09:00');
        await confirm.trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/series/add');
        expect(JSON.parse(post.mock.calls[0][1].body)).toEqual({ date_time: '2026-11-16T09:00' });
        expect(wrapper.emitted('changed')).toHaveLength(1);
        expect(wrapper.find('[data-test="series-add-box"]').exists()).toBe(false);
    });

    it('horário recusado (422) aparece no campo e a caixa fica aberta', async () => {
        const post = vi.fn(() =>
            jsonResponse({ message: 'x', errors: { date_time: ['Médico ocupado neste horário.'] } }, 422),
        );
        const wrapper = await mountDrawer(detail(), post);

        await wrapper.find('[data-test="series-add-open"]').trigger('click');
        await wrapper.find('#series-add-date').setValue('2026-11-16T09:00');
        await wrapper.find('[data-test="series-add-confirm"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="series-add-box"]').text()).toContain('Médico ocupado neste horário.');
        expect(wrapper.emitted('changed')).toBeUndefined();
    });
});
