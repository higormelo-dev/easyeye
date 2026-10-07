import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
    Link: { name: 'InertiaLink', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const AgendaCounters = (await import('@/Pages/Panel/Schedules/AgendaCounters.vue')).default;
const ScheduleCard = (await import('@/Pages/Panel/Schedules/ScheduleCard.vue')).default;
const DaySummary = (await import('@/Pages/Panel/Dashboard/DaySummary.vue')).default;

/**
 * Fase 1 / B1 — a fila do pronto atendimento nos contadores da Agenda e no
 * resumo do Dashboard (mesmo número do servidor) e o selo do atendimento de
 * urgência na linha da Agenda.
 */
describe('Agenda — contador do pronto atendimento', () => {
    const t = {
        counter_total: 'Agendados',
        counter_urgent_care: 'Pronto atendimento',
        counter_urgent_care_hint: 'Na fila agora',
    };

    it('vira um atalho para a fila (não um filtro da lista) com o número do servidor', () => {
        const wrapper = mount(AgendaCounters, {
            props: { counters: { total: 4, urgent_care: 3 }, active: null, urgentCareUrl: '/panel/urgent-care', t },
        });

        const chip = wrapper.find('[data-counter="urgent_care"]');
        expect(chip.element.tagName).toBe('A');
        expect(chip.attributes('href')).toBe('/panel/urgent-care');
        expect(chip.text()).toContain('3');
        expect(chip.text()).toContain('Pronto atendimento');
        expect(chip.attributes('title')).toBe('Na fila agora');
        expect(chip.attributes('aria-pressed')).toBeUndefined();
    });

    it('fora de hoje (servidor manda null) ou sem a URL, o atalho some', () => {
        const otherDay = mount(AgendaCounters, {
            props: { counters: { total: 4, urgent_care: null }, active: null, urgentCareUrl: '/panel/urgent-care', t },
        });
        const noUrl = mount(AgendaCounters, { props: { counters: { total: 4, urgent_care: 2 }, active: null, t } });

        expect(otherDay.find('[data-counter="urgent_care"]').exists()).toBe(false);
        expect(noUrl.find('[data-counter="urgent_care"]').exists()).toBe(false);
    });
});

describe('Agenda — selo do atendimento de urgência', () => {
    it('mostra "Pronto atendimento" com texto na linha', () => {
        const wrapper = mount(ScheduleCard, {
            props: {
                item: {
                    id: 's1',
                    time: '10:07',
                    name: 'PACIENTE',
                    badge: 'bg-primary',
                    label: 'Em consulta',
                    situation: 6,
                    is_terminal: false,
                    is_urgent_care: true,
                },
                isStaff: true,
                t: { urgent_care_badge: 'Pronto atendimento', urgent_care_hint: 'Fora da grade' },
            },
            global: { stubs: { ActionDropdown: { template: '<div><slot /></div>' } } },
        });

        const badge = wrapper.find('[data-test="urgent-care-badge"]');
        expect(badge.text()).toContain('Pronto atendimento');
        expect(badge.attributes('title')).toBe('Fora da grade');
    });
});

describe('Dashboard — resumo do dia com a fila do pronto atendimento', () => {
    const t = {
        section_day_summary: 'Resumo do dia',
        summary_total: 'Agendadas',
        summary_attended: 'Atendidos',
        summary_pending: 'A atender',
        summary_cancelled: 'Faltas',
        summary_urgent_care: 'Pronto atendimento',
        summary_urgent_care_one: ':count na fila',
        summary_urgent_care_other: ':count na fila',
        summary_urgent_care_hint: 'Abre a fila',
    };

    it('mostra o número da fila e leva à tela', () => {
        const wrapper = mount(DaySummary, { props: { stats: { today_count: 0, urgent_queue: 2 }, t } });

        const line = wrapper.find('[data-summary="urgent-care"]');
        expect(line.text()).toContain('Pronto atendimento');
        expect(line.text()).toContain('2 na fila');
        expect(line.attributes('href')).toBe('/_routes/panel.urgent-care.index');
        expect(line.classes()).toContain('ds-urgent--active');
    });

    it('perfil sem a Agenda (sem a chave) não vê a linha', () => {
        const wrapper = mount(DaySummary, { props: { stats: { today_count: 3 }, t } });

        expect(wrapper.find('[data-summary="urgent-care"]').exists()).toBe(false);
    });
});
