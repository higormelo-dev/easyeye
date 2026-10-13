import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';

/**
 * Agenda — calendário lateral para escolher a data também na agenda do
 * MÉDICO (antes a barra lateral inteira era só para secretaria/admin). O
 * médico vê calendário + turno; a lista "Médicos" continua só para quem vê
 * vários (o servidor força o médico logado no filtro).
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/composables/useDashboardPolling.js', () => ({ useDashboardPolling: () => {} }));
vi.mock('@/Components/Panel/MiniCalendar.vue', () => ({
    default: {
        name: 'MiniCalendar',
        props: ['modelValue', 'locale', 'availability', 'loading'],
        emits: ['update:modelValue', 'month-change'],
        template:
            '<div class="mini-cal" :data-date="modelValue"><button class="pick" @click="$emit(\'update:modelValue\', \'2026-12-15\')" /></div>',
    },
}));
for (const name of [
    'ScheduleCard',
    'EventCard',
    'ScheduleFormModal',
    'RescheduleModal',
    'CancelModal',
    'BulkCancelModal',
    'BulkRescheduleModal',
    'NoticesPanel',
    'WaitingListPanel',
    'WaitingListFormModal',
    'ScheduleDetailDrawer',
    'CashEntryModal',
    'CalendarView',
]) {
    vi.doMock(`@/Pages/Panel/Schedules/${name}.vue`, () => ({ default: { template: `<div class="stub-${name}" />` } }));
}

const SchedulesIndex = (await import('@/Pages/Panel/Schedules/Index.vue')).default;

const t = {
    sidebar_doctors: 'Médicos',
    sidebar_time: 'Horário',
    sidebar_all: 'Tudo',
    sidebar_morning: 'Manhã',
    sidebar_afternoon: 'Tarde',
    sidebar_evening: 'Noite',
};
const doctors = [{ id: 'd1', name: 'DRA. ANA LIMA', record: '123456', color: '#e91e63' }];

function mountPage(props = {}) {
    return mount(SchedulesIndex, {
        props: { filters: { date: '2026-09-29', doctor: 'tudo', bout: 1 }, doctors, t, ...props },
        global: { mocks: { route: globalThis.route } },
    });
}

beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
});
afterEach(() => vi.unstubAllGlobals());

describe('Agenda — calendário lateral', () => {
    it('médico vê o calendário e o turno, sem a lista de médicos', () => {
        const wrapper = mountPage({ isDoctor: true, doctors: [] });

        expect(wrapper.find('.mini-cal').exists()).toBe(true);
        expect(wrapper.find('.mini-cal').attributes('data-date')).toBe('2026-09-29');
        expect(wrapper.text()).toContain('Horário');
        expect(wrapper.text()).not.toContain('Médicos');
        expect(wrapper.find('[data-testid="availability-scope"]').exists()).toBe(false);
        expect(wrapper.find('.col-md-9').exists()).toBe(true);
    });

    it('médico escolhe a data no calendário e a agenda recarrega para o dia escolhido', async () => {
        const wrapper = mountPage({ isDoctor: true, doctors: [] });

        await wrapper.find('.pick').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.schedules.index',
            expect.objectContaining({ date: '2026-12-15' }),
            expect.objectContaining({ only: ['scheduleItems', 'filters', 'counters'] }),
        );
    });

    it('secretaria continua igual: calendário, lista de médicos e turno', () => {
        const wrapper = mountPage({ isDoctor: false });

        expect(wrapper.find('.mini-cal').exists()).toBe(true);
        expect(wrapper.text()).toContain('Médicos');
        expect(wrapper.text()).toContain('DRA. ANA LIMA');
        expect(wrapper.text()).toContain('Horário');
    });

    it('destaca com FUNDO quem está selecionado na lista — "tudo" ou o médico (a borda 1↔2px sozinha não se via)', async () => {
        const wrapper = mountPage({ doctors: [...doctors, { id: 'd2', name: 'DR. BRUNO', color: '#0d6efd' }] });

        const tudoRow = wrapper.find('[data-testid="availability-clinic-total"]').element.closest('.d-flex');
        expect(tudoRow.style.backgroundColor).not.toBe('transparent');
        expect(wrapper.find('[data-doctor="d1"]').element.style.backgroundColor).toBe('transparent');

        await wrapper.find('[data-doctor="d1"]').trigger('click');

        expect(wrapper.find('[data-doctor="d1"]').element.style.backgroundColor).not.toBe('transparent');
        expect(tudoRow.style.backgroundColor).toBe('transparent');
    });
});

describe('Agenda — vagas na visão de vários médicos', () => {
    const anaDays = { '2026-09-29': { state: 'available', free: 3, capacity: 4 } };
    const brunoDays = { '2026-09-29': { state: 'full', free: 0, capacity: 2 } };
    const allDays = { '2026-09-29': { state: 'available', free: 3, capacity: 6 } };
    const multiDoctors = [...doctors, { id: 'd2', name: 'DR. BRUNO', color: '#0d6efd' }];

    it('identifica a soma e permite selecionar um médico pelo detalhe do dia', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ days: allDays, doctor_days: { d1: anaDays, d2: brunoDays } }),
            }),
        );
        const wrapper = mountPage({ doctors: multiDoctors });
        const calendar = wrapper.findComponent({ name: 'MiniCalendar' });
        calendar.vm.$emit('month-change', '2026-09');
        await flushPromises();

        expect(wrapper.find('[data-testid="availability-scope"]').text()).toContain('todos os médicos');
        expect(calendar.props('availability')).toEqual(allDays);
        expect(wrapper.find('[data-testid="availability-clinic-total"]').text()).toBe(
            'Total da clínica: 3 livres de 6',
        );
        expect(wrapper.find('[data-doctor="d1"]').text()).toContain('3 livres de 4');
        expect(wrapper.find('[data-doctor="d2"]').text()).toContain('0 livres de 2');

        await wrapper.find('[data-doctor="d2"]').trigger('click');
        expect(wrapper.find('[data-testid="availability-scope"]').text()).toBe('Vagas — DR. BRUNO');
        expect(fetch.mock.calls.at(-1)[0]).toContain('doctor=d2');
        expect(router.get).toHaveBeenLastCalledWith(
            '/_routes/panel.schedules.index',
            expect.objectContaining({ doctor: 'd2' }),
            expect.anything(),
        );
    });

    it('nome do médico aparece só uma vez — vaga vai junto da lista "Médicos", sem card duplicado (melhoria pedida pelo usuário)', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ days: allDays, doctor_days: { d1: anaDays, d2: brunoDays } }),
            }),
        );
        const wrapper = mountPage({ doctors: multiDoctors });
        const calendar = wrapper.findComponent({ name: 'MiniCalendar' });
        calendar.vm.$emit('month-change', '2026-09');
        await flushPromises();

        expect(wrapper.text()).not.toContain('Vagas por médico');
        expect(wrapper.text().match(/DRA\. ANA LIMA/g)).toHaveLength(1);
        expect(wrapper.text().match(/DR\. BRUNO/g)).toHaveLength(1);
        // CRM e vaga juntos no mesmo item da lista "Médicos".
        expect(wrapper.find('[data-doctor="d1"]').text()).toContain('123456');
        expect(wrapper.find('[data-doctor="d1"]').text()).toContain('3 livres de 4');
    });

    it('remove as cores anteriores ao trocar o médico e ignora JSON que termina atrasado', async () => {
        let finishAllJson;
        const allJson = new Promise((resolve) => {
            finishAllJson = resolve;
        });
        let finishDoctor;
        const doctorResponse = new Promise((resolve) => {
            finishDoctor = resolve;
        });
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce({
                    ok: true,
                    json: async () => ({ days: allDays, doctor_days: { d1: anaDays, d2: brunoDays } }),
                })
                .mockResolvedValueOnce({ ok: true, json: () => allJson })
                .mockReturnValueOnce(doctorResponse),
        );
        const wrapper = mountPage({ doctors: multiDoctors });
        const calendar = wrapper.findComponent({ name: 'MiniCalendar' });
        calendar.vm.$emit('month-change', '2026-09');
        await flushPromises();
        calendar.vm.$emit('month-change', '2026-09');
        await flushPromises();

        await wrapper
            .findAll('.col-md-3 .d-flex.align-items-center.mb-3.gap-2')
            .find((item) => item.text().includes('DR. BRUNO'))
            .trigger('click');
        expect(calendar.props('availability')).toBeNull();
        expect(calendar.props('loading')).toBe(true);
        finishDoctor({ ok: true, json: async () => ({ days: brunoDays, doctor_days: { d2: brunoDays } }) });
        await flushPromises();
        finishAllJson({ days: allDays, doctor_days: { d1: anaDays, d2: brunoDays } });
        await flushPromises();

        expect(calendar.props('availability')).toEqual(brunoDays);
        expect(calendar.props('loading')).toBe(false);
    });
});

describe('Agenda — "Novo agendamento" pelo Dashboard (?new=1)', () => {
    it('abre o formulário vazio e limpa a URL com uma visita leve (replace)', async () => {
        window.history.replaceState(null, '', '/panel/schedules?new=1');
        const wrapper = mountPage();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('.stub-ScheduleFormModal').attributes('open')).toBe('true');
        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.schedules.index',
            {},
            expect.objectContaining({ replace: true, preserveState: true }),
        );
        window.history.replaceState(null, '', '/');
    });

    it('sem ?new=1 o formulário continua fechado', () => {
        window.history.replaceState(null, '', '/panel/schedules');
        const wrapper = mountPage();

        expect(wrapper.find('.stub-ScheduleFormModal').attributes('open')).toBe('false');
    });
});
