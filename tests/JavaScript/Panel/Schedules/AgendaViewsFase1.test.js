import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';

/**
 * Fase 1 — Agenda: calendário dia/semana/mês (FullCalendar com os mesmos
 * dados/filtros), chips e filtros na URL, impressão com os filtros, vagas do
 * mês no calendário lateral e o detalhe abrindo pelo clique no calendário.
 */
let lastCalendarOptions = null;
const setOption = vi.hoisted(() => vi.fn());
vi.mock('@fullcalendar/vue3', () => ({
    default: {
        props: ['options'],
        setup(props) {
            lastCalendarOptions = props.options;
            return {};
        },
        methods: { getApi: () => ({ gotoDate() {}, changeView() {}, setOption }) },
        template: '<div class="fc-stub" />',
    },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/composables/useDashboardPolling.js', () => ({ useDashboardPolling: () => {} }));

const CalendarView = (await import('@/Pages/Panel/Schedules/CalendarView.vue')).default;

const t = { cal_day: 'Dia', cal_week: 'Semana', cal_month: 'Mês', fit_in_badge: 'Encaixe', block_badge: 'Bloqueio' };

const items = [
    {
        type: 'schedule',
        id: 's1',
        sort_time: '2030-01-07T12:00:00.000Z',
        time: '09:00',
        name: 'MARIA',
        label: 'Confirmado',
        badge: 'bg-info text-dark',
        doctor_name: 'DRA. ANA',
        doctor_color: '#e91e63',
        is_fit_in: true,
        session_number: 3,
        session_total: 10,
    },
    {
        type: 'block',
        id: 'doctor-b1',
        sort_time: '2030-01-07T17:00:00.000Z',
        ends_at_iso: '2030-01-07T19:00:00.000Z',
        title: 'CONGRESSO',
    },
];

describe('CalendarView — dia, semana e mês', () => {
    beforeEach(() => {
        lastCalendarOptions = null;
    });

    it('abre na visão do período pedido e troca pelos botões', async () => {
        const wrapper = mount(CalendarView, { props: { scheduleItems: items, date: '2030-01-07', range: 'month', t } });

        expect(lastCalendarOptions.initialView).toBe('dayGridMonth');
        expect(lastCalendarOptions.firstDay).toBe(0);
        expect(wrapper.find('[data-range="month"]').attributes('aria-pressed')).toBe('true');

        await wrapper.find('[data-range="week"]').trigger('click');
        expect(wrapper.emitted('range-change').at(-1)).toEqual(['week']);
    });

    it('encaixe e bloqueio viram eventos marcados (classe + selo), com cor da situação', () => {
        mount(CalendarView, { props: { scheduleItems: items, date: '2030-01-07', range: 'week', t } });

        const [schedule, block] = lastCalendarOptions.events;
        expect(schedule.classNames).toContain('fc-ev-fit-in');
        expect(schedule.backgroundColor).toBe('#0e7490');
        expect(block.classNames).toContain('fc-ev-block');
        expect(block.end).toBe('2030-01-07T19:00:00.000Z');

        const content = lastCalendarOptions.eventContent({
            event: { extendedProps: schedule.extendedProps },
            timeText: '09:00',
            view: { type: 'timeGridWeek' },
        });
        const text = content.domNodes[0].textContent;
        expect(text).toContain('MARIA');
        expect(text).toContain('Encaixe');
        expect(text).toContain('3/10');
    });

    it('clique no agendamento abre o detalhe; número do dia abre o dia', () => {
        const wrapper = mount(CalendarView, { props: { scheduleItems: items, date: '2030-01-07', range: 'month', t } });

        lastCalendarOptions.eventClick({ event: { extendedProps: items[0] } });
        expect(wrapper.emitted('event-click')[0][0].id).toBe('s1');

        lastCalendarOptions.navLinkDayClick(new Date(2030, 0, 15));
        expect(wrapper.emitted('date-click').at(-1)).toEqual(['2030-01-15']);

        lastCalendarOptions.dateClick({ view: { type: 'dayGridMonth' }, dateStr: '2030-01-16' });
        expect(wrapper.emitted('date-click').at(-1)).toEqual(['2030-01-16']);
    });
});

describe('Agenda (Index) — filtros, impressão, vagas e detalhe', () => {
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
        'CashEntryModal',
    ]) {
        vi.doMock(`@/Pages/Panel/Schedules/${name}.vue`, () => ({
            default: { template: `<div class="stub-${name}" />` },
        }));
    }
    vi.doMock('@/Pages/Panel/Schedules/ScheduleDetailDrawer.vue', () => ({
        default: {
            props: ['open', 'scheduleId'],
            emits: ['reschedule', 'changed', 'close'],
            template: '<div class="stub-drawer" :data-open="String(open)" :data-id="scheduleId" />',
        },
    }));

    let Index;

    beforeEach(async () => {
        vi.clearAllMocks();
        window.localStorage.clear();
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ days: { '2030-01-07': { state: 'few', free: 1, capacity: 8 } } }),
            }),
        );
        Index = (await import('@/Pages/Panel/Schedules/Index.vue')).default;
    });

    const mountIndex = (props = {}) =>
        mount(Index, {
            props: {
                filters: {
                    date: '2030-01-07',
                    doctor: 'tudo',
                    bout: 1,
                    range: 'day',
                    from: '2030-01-07',
                    to: '2030-01-07',
                },
                counters: { total: 5, confirmed: 2, blocked: 1 },
                doctors: [{ id: 'd1', name: 'DRA. ANA', color: '#e91e63' }],
                specialties: [{ id: 3, name: 'Glaucoma' }],
                resourceOptions: [{ id: 'r1', name: 'SALA OCT' }],
                urls: { print: '/print', availability: '/availability' },
                t: {
                    counter_total: 'Agendados',
                    avail_few: 'Poucas vagas',
                    avail_free_of: ':free livres de :capacity',
                },
                ...props,
            },
            global: { mocks: { route: globalThis.route } },
        });

    it('chip de contador filtra pela URL (preserveState) e recarrega lista + contadores', async () => {
        const wrapper = mountIndex();

        await wrapper.find('[data-counter="confirmed"]').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.schedules.index',
            expect.objectContaining({ status: 'confirmed', date: '2030-01-07' }),
            expect.objectContaining({ preserveState: true, only: ['scheduleItems', 'filters', 'counters'] }),
        );
    });

    it('especialidade e sala também vão para a URL; "Limpar filtros" tira tudo', async () => {
        const wrapper = mountIndex({ filters: { date: '2030-01-07', doctor: 'tudo', bout: 1, status: 'waiting' } });

        await wrapper.find('[data-testid="filter-specialty"]').setValue('3');
        expect(router.get).toHaveBeenLastCalledWith(
            '/_routes/panel.schedules.index',
            expect.objectContaining({ specialty: 3, status: 'waiting' }),
            expect.anything(),
        );

        await wrapper.find('[data-testid="filter-resource"]').setValue('r1');
        expect(router.get.mock.calls.at(-1)[1]).toMatchObject({ resource: 'r1' });

        await wrapper.find('[data-testid="filter-clear"]').trigger('click');
        const params = router.get.mock.calls.at(-1)[1];
        expect(params).not.toHaveProperty('status');
        expect(params).not.toHaveProperty('specialty');
        expect(params).not.toHaveProperty('resource');
    });

    it('imprimir leva os mesmos filtros da tela (sem o período do calendário)', () => {
        const wrapper = mountIndex({
            filters: { date: '2030-01-07', doctor: 'd1', bout: 2, status: 'confirmed', specialty: 3, resource: 'r1' },
        });

        const href = wrapper.find('[data-testid="print-agenda"]').attributes('href');
        const params = new URLSearchParams(href.split('?')[1]);

        expect(href.startsWith('/print?')).toBe(true);
        expect(Object.fromEntries(params)).toEqual({
            date: '2030-01-07',
            doctor: 'd1',
            bout: '2',
            status: 'confirmed',
            specialty: '3',
            resource: 'r1',
        });
    });

    it('calendário lateral busca as vagas do mês (com o médico escolhido) e mostra o estado', async () => {
        const wrapper = mountIndex({ filters: { date: '2030-01-07', doctor: 'd1', bout: 1 } });
        await flushPromises();

        const url = globalThis.fetch.mock.calls.map((c) => c[0]).find((u) => u.startsWith('/availability'));
        expect(url).toBe('/availability?month=2030-01&doctor=d1');
        expect(wrapper.find('[data-date="2030-01-07"]').attributes('aria-label')).toContain(
            '1 livres de 8 (Poucas vagas)',
        );
    });

    it('calendário: clique num agendamento abre o detalhe (drawer); trocar o período vai para a URL', async () => {
        window.localStorage.setItem('schedules_view_mode', 'calendar');
        const wrapper = mountIndex({ filters: { date: '2030-01-07', doctor: 'tudo', bout: 1, range: 'week' } });
        await flushPromises();
        await flushPromises();

        lastCalendarOptions.eventClick({ event: { extendedProps: { type: 'schedule', id: 's9' } } });
        await flushPromises();
        expect(wrapper.find('.stub-drawer').attributes('data-open')).toBe('true');
        expect(wrapper.find('.stub-drawer').attributes('data-id')).toBe('s9');

        await wrapper.find('[data-range="month"]').trigger('click');
        expect(router.get.mock.calls.at(-1)[1]).toMatchObject({ range: 'month' });
    });

    it('médico sem cadastro: sem contadores, sem vagas', async () => {
        const wrapper = mountIndex({ doctorMissing: true, isDoctor: true });
        await flushPromises();

        expect(wrapper.find('[data-testid="agenda-filters"]').exists()).toBe(false);
        expect(globalThis.fetch.mock.calls.some((c) => String(c[0]).startsWith('/availability'))).toBe(false);
    });

    it('lista em modo lista com link de semana: volta para o dia', async () => {
        mountIndex({ filters: { date: '2030-01-07', doctor: 'tudo', bout: 1, range: 'week' } });
        await flushPromises();

        expect(router.get.mock.calls.at(-1)[1]).toMatchObject({ range: 'day' });
    });
});

describe('CalendarView — duração e faixa fiel aos eventos', () => {
    it.each([30, 45])('duração explícita %s determina ocupação e rótulo acessível', (minutes) => {
        const wrapper = mount(CalendarView, {
            props: { date: '2030-01-07', t, scheduleItems: [{ ...items[0], duration_minutes: minutes }] },
        });
        const event = lastCalendarOptions.events[0];
        expect(new Date(event.end) - new Date(event.start)).toBe(minutes * 60000);
        expect(event.extendedProps.duration_estimated).toBe(false);
        const element = document.createElement('div');
        lastCalendarOptions.eventDidMount({ event: { extendedProps: event.extendedProps }, el: element });
        expect(element.getAttribute('aria-label')).toContain(`${minutes} min`);
        expect(element.getAttribute('title')).not.toContain('estimada');
        wrapper.unmount();
    });
    it('ends_at válido prevalece e ausência de duração mostra fallback estimado sem poluir mês', async () => {
        const wrapper = mount(CalendarView, {
            props: {
                date: '2030-01-07',
                t,
                scheduleItems: [{ ...items[0], ends_at: '2030-01-07T13:00:00Z', duration_minutes: 30 }],
            },
        });
        expect(lastCalendarOptions.events[0].extendedProps.duration_minutes).toBe(60);
        await wrapper.setProps({ scheduleItems: [items[0]] });
        const event = lastCalendarOptions.events[0];
        expect(event.extendedProps.duration_estimated).toBe(true);
        expect(new Date(event.end) - new Date(event.start)).toBe(20 * 60000);
        const render = (type) =>
            lastCalendarOptions.eventContent({
                event: { extendedProps: event.extendedProps },
                view: { type },
                timeText: '09:00',
            }).domNodes[0].textContent;
        expect(render('timeGridWeek')).toContain('~20 min');
        expect(render('dayGridMonth')).not.toContain('~20 min');
        const el = document.createElement('div');
        lastCalendarOptions.eventDidMount({ event: { extendedProps: event.extendedProps }, el });
        expect(el.getAttribute('aria-label')).toContain('duração estimada');
        wrapper.unmount();
    });
    it('faixa inclui eventos cedo/noite e é recalculada na API; sem eventos volta ao fallback', async () => {
        const wrapper = mount(CalendarView, { props: { date: '2030-01-07', t, scheduleItems: [] } });
        expect(lastCalendarOptions.slotMinTime).toBe('07:00:00');
        expect(lastCalendarOptions.slotMaxTime).toBe('20:30:00');
        await wrapper.setProps({
            scheduleItems: [
                { ...items[0], sort_time: '2030-01-07T06:30:00', duration_minutes: 20 },
                { ...items[0], id: 'night', sort_time: '2030-01-07T21:00:00', duration_minutes: 20 },
            ],
        });
        expect(lastCalendarOptions.slotMinTime).toBe('06:30:00');
        expect(lastCalendarOptions.slotMaxTime).toBe('21:30:00');
        expect(setOption).toHaveBeenCalledWith('slotMaxTime', '21:30:00');
        await wrapper.setProps({ scheduleItems: [], calendarBounds: { start: '06:00', end: '22:00' } });
        expect(lastCalendarOptions.slotMinTime).toBe('06:00:00');
        expect(lastCalendarOptions.slotMaxTime).toBe('22:00:00');
        await wrapper.setProps({ calendarBounds: null });
        expect(lastCalendarOptions.slotMaxTime).toBe('20:30:00');
        wrapper.unmount();
    });
    it('eventos que cruzam meia-noite e bounds inválidos não cortam a grade', () => {
        const wrapper = mount(CalendarView, {
            props: {
                date: '2030-01-07',
                t,
                calendarBounds: { start: '30:00', end: '35:00' },
                scheduleItems: [{ ...items[0], sort_time: '2030-01-07T23:50:00', duration_minutes: 30 }],
            },
        });
        expect(lastCalendarOptions.slotMaxTime).toBe('24:00:00');
        wrapper.unmount();
    });
});

it('EventCard mantém horário theme-aware e badge com o par acessível sem opacidade', async () => {
    const EventCard = (await vi.importActual('@/Pages/Panel/Schedules/EventCard.vue')).default;
    const wrapper = mount(EventCard, {
        props: {
            item: { type: 'event', title: 'Sintético', color: '#0aa2c0', starts_at_fmt: '09:00', ends_at_fmt: '10:00' },
            t: { event_badge: 'Evento' },
        },
    });
    expect(wrapper.get('.fw-bold').attributes('style')).toContain('var(--bs-body-color)');
    const badge = wrapper.get('.badge.rounded-pill');
    expect(badge.element.style.background).toBe('#0e7490');
    expect(badge.element.style.color).toBe('#ffffff');
    expect(badge.element.style.opacity).toBe('');
    wrapper.unmount();
});
