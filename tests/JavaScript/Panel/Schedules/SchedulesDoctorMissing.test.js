import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * Agenda do perfil médico SEM cadastro de médico: o servidor não manda nada da
 * clínica (antes caía na visão de todos os médicos). A tela mostra o aviso
 * traduzido e esconde criar/importar; o médico COM cadastro recebe só ele
 * mesmo em `doctors` (o formulário pré-seleciona) e continua sem a lista
 * lateral de médicos.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/composables/useDashboardPolling.js', () => ({ useDashboardPolling: () => {} }));
vi.mock('@/Components/Panel/MiniCalendar.vue', () => ({ default: { template: '<div class="mini-cal" />' } }));
for (const name of [
    'ScheduleCard',
    'EventCard',
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
vi.doMock('@/Pages/Panel/Schedules/ScheduleFormModal.vue', () => ({
    default: {
        props: ['open', 'doctors'],
        template: '<div class="stub-form" :data-open="String(open)" :data-doctors="doctors.length" />',
    },
}));

const SchedulesIndex = (await import('@/Pages/Panel/Schedules/Index.vue')).default;

const t = {
    btn_new: 'Novo',
    empty: 'Nenhum agendamento.',
    sidebar_doctors: 'Médicos',
    doctor_missing_title: 'Cadastro de médico incompleto',
    doctor_missing: 'Seu usuário não está vinculado a um cadastro de médico — peça ao administrador.',
};

function mountPage(props = {}) {
    return mount(SchedulesIndex, {
        props: { filters: { date: '2026-10-06', doctor: 'tudo', bout: 1 }, t, ...props },
        global: { mocks: { route: globalThis.route } },
    });
}

beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/panel/schedules');
});

describe('Agenda — médico sem cadastro de médico', () => {
    it('mostra o aviso traduzido e nenhuma ação de criar/importar', () => {
        const wrapper = mountPage({ isDoctor: true, doctorMissing: true, doctors: [], scheduleItems: [] });

        const alert = wrapper.find('[data-testid="doctor-missing-alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain(t.doctor_missing_title);
        expect(alert.text()).toContain(t.doctor_missing);

        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).not.toContain('Novo');
        expect(wrapper.text()).not.toContain('Importar');
    });

    it('?new=1 (atalho do Dashboard) não abre o formulário', async () => {
        window.history.replaceState({}, '', '/panel/schedules?new=1');
        const wrapper = mountPage({ isDoctor: true, doctorMissing: true, doctors: [] });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('.stub-form').attributes('data-open')).toBe('false');
    });

    it('médico com cadastro: sem aviso, "Novo" disponível e o formulário recebe só ele', async () => {
        const self = { id: 'd1', name: 'DRA. ANA', record: '1', color: '#e91e63' };
        const wrapper = mountPage({ isDoctor: true, doctorMissing: false, doctors: [self] });

        expect(wrapper.find('[data-testid="doctor-missing-alert"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Médicos');

        const novo = wrapper.findAll('button').find((b) => b.text() === 'Novo');
        expect(novo).toBeDefined();
        await novo.trigger('click');

        const form = wrapper.find('.stub-form');
        expect(form.attributes('data-open')).toBe('true');
        expect(form.attributes('data-doctors')).toBe('1');
    });
});
