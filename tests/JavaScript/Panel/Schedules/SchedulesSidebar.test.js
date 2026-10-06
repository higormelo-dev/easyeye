import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
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
        props: ['modelValue', 'locale'],
        emits: ['update:modelValue'],
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

describe('Agenda — calendário lateral', () => {
    it('médico vê o calendário e o turno, sem a lista de médicos', () => {
        const wrapper = mountPage({ isDoctor: true, doctors: [] });

        expect(wrapper.find('.mini-cal').exists()).toBe(true);
        expect(wrapper.find('.mini-cal').attributes('data-date')).toBe('2026-09-29');
        expect(wrapper.text()).toContain('Horário');
        expect(wrapper.text()).not.toContain('Médicos');
        expect(wrapper.find('.col-md-9').exists()).toBe(true);
    });

    it('médico escolhe a data no calendário e a agenda recarrega para o dia escolhido', async () => {
        const wrapper = mountPage({ isDoctor: true, doctors: [] });

        await wrapper.find('.pick').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.schedules.index',
            expect.objectContaining({ date: '2026-12-15' }),
            expect.objectContaining({ only: ['scheduleItems', 'filters'] }),
        );
    });

    it('secretaria continua igual: calendário, lista de médicos e turno', () => {
        const wrapper = mountPage({ isDoctor: false });

        expect(wrapper.find('.mini-cal').exists()).toBe(true);
        expect(wrapper.text()).toContain('Médicos');
        expect(wrapper.text()).toContain('DRA. ANA LIMA');
        expect(wrapper.text()).toContain('Horário');
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
