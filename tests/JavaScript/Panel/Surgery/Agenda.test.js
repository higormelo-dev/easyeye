import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __router } = await import('@inertiajs/vue3');
const Agenda = (await import('@/Pages/Panel/Surgery/Agenda.vue')).default;

const layout = { template: '<div><slot /></div>' };
const modal = {
    props: ['open'],
    template: '<div v-if="open"><slot name="header" /><slot /><slot name="footer" /></div>',
};

const t = {
    schedule: {
        agenda: {
            title: 'Agenda cirúrgica',
            subtitle: 'sub',
            map: 'Mapa do dia',
            cases: 'Lista',
            toolbar: 'Navegação',
            previous: 'Anterior',
            next: 'Próximo',
            today: 'Hoje',
            date: 'Data',
            view: 'Visão',
            day: 'Dia',
            week: 'Semana',
            facility: 'Local',
            all_facilities: 'Todos os locais',
            surgeon: 'Cirurgião',
            all_surgeons: 'Todos',
            no_facility: 'Sem local',
            configure: 'Cadastrar',
            grid: 'Agenda por sala',
            no_rooms: 'Sem salas',
            no_room: 'Sem sala',
            day_empty: 'Nenhuma cirurgia',
            queue: 'Autorizadas',
            queue_empty: 'Fila vazia',
            urgent: 'Urgência',
            duration: ':n min',
            valid_until: 'até :date',
            schedule: 'Agendar',
            summary: ':count cirurgia(s)',
            phase_short: ['Entrada', 'Pausa', 'Saída'],
            done: 'fechada',
            pending: 'pendente',
        },
        form: {
            title_schedule: 'Agendar cirurgia',
            date: 'Data',
            time: 'Hora',
            duration: 'Duração',
            minutes: 'min',
            facility: 'Local',
            room: 'Sala',
            choose: 'Escolha',
            confirm_schedule: 'Agendar',
            cancel: 'Cancelar',
        },
    },
};

const status = { value: 'scheduled', label: 'Agendada', tone: 'primary' };
const caseRow = (id, start, end, over = {}) => ({
    id,
    code: `CIR-${id}`,
    title: `Cirurgia ${id}`,
    status,
    patient: { name: `Paciente ${id}` },
    surgeon: { name: 'Dr. X' },
    room: 'Sala 1',
    room_id: 'r1',
    facility_id: 'f1',
    scheduled_start: start,
    scheduled_end: end,
    checklist: [true, false, false],
    ...over,
});

const base = (over = {}) => ({
    breadcrumbs: [],
    view: 'day',
    date: '2026-10-19',
    range: { from: '2026-10-19', to: '2026-10-19' },
    filters: { facility: null, surgeon: null },
    cases: [],
    queue: [],
    rooms: [
        { id: 'r1', name: 'Sala 1', facility_id: 'f1', facility: 'Hospital', active: true },
        { id: 'r2', name: 'Sala 2', facility_id: 'f1', facility: 'Hospital', active: true },
    ],
    hours: { start: 6, end: 22 },
    options: {
        facilities: [{ id: 'f1', name: 'Hospital', rooms: [] }],
        surgeons: [
            { id: 'd1', name: 'Dr. X' },
            { id: 'd2', name: 'Dra. Y' },
        ],
    },
    can: { manage: true },
    doctorMissing: false,
    doctorMissingText: '',
    urls: {
        agenda: '/agenda',
        map: '/map',
        index: '/idx',
        show: '/show/__ID__',
        schedule: '/sch/__ID__',
        schedule_check: '/chk/__ID__',
        facilities: '/fac',
    },
    t,
    ...over,
});

const mountAgenda = (props) =>
    mount(Agenda, { props: base(props), global: { stubs: { AppLayout: layout, CenteredModal: modal } } });

describe('Agenda cirúrgica', () => {
    beforeEach(() => Object.values(__router).forEach((fn) => fn.mockClear?.()));

    it('uma coluna por sala; o bloco cai na sala certa com horário e link para o caso', () => {
        const wrapper = mountAgenda({ cases: [caseRow('a', '2026-10-19T09:00:00', '2026-10-19T10:00:00')] });

        expect(wrapper.find('[data-testid="col-r1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="col-r2"]').exists()).toBe(true);

        const block = wrapper.find('[data-testid="col-r1"] [data-testid="agenda-block"]');
        expect(block.exists()).toBe(true);
        expect(block.attributes('href')).toBe('/show/a');
        expect(block.attributes('aria-label')).toContain('Cirurgia a');
        expect(block.attributes('aria-label')).toContain('Paciente a');
        expect(wrapper.find('[data-testid="col-r2"] [data-testid="agenda-block"]').exists()).toBe(false);
    });

    it('posiciona pelo horário: 09:00–10:00 fica 3 h abaixo do início da grade (06:00) com 1 h de altura', () => {
        const wrapper = mountAgenda({ cases: [caseRow('a', '2026-10-19T09:00:00', '2026-10-19T10:00:00')] });
        const wrap = wrapper.find('[data-testid="col-r1"] .sa-block-wrap');

        expect(wrap.attributes('style')).toContain('top: 192px');
        expect(wrap.attributes('style')).toContain('height: 64px');
    });

    it('casos sem sala caem na coluna "Sem sala" e dividem a largura quando se sobrepõem', () => {
        const wrapper = mountAgenda({
            cases: [
                caseRow('a', '2026-10-19T09:00:00', '2026-10-19T10:00:00', { room_id: null, room: null }),
                caseRow('b', '2026-10-19T09:30:00', '2026-10-19T10:30:00', { room_id: null, room: null }),
            ],
        });

        const blocks = wrapper.findAll('[data-testid="col-__none__"] .sa-block-wrap');
        expect(blocks).toHaveLength(2);
        expect(blocks[0].attributes('style')).toContain('width: 50%');
        expect(blocks[1].attributes('style')).toContain('left: 50%');
    });

    it('mostra os três pontos do checklist com o que está fechado', () => {
        const wrapper = mountAgenda({ cases: [caseRow('a', '2026-10-19T09:00:00', '2026-10-19T10:00:00')] });

        expect(wrapper.findAll('.sa-dot.is-done')).toHaveLength(1);
        expect(wrapper.find('[data-testid="agenda-block"]').attributes('title')).toContain('Entrada: fechada');
    });

    it('navega: dia seguinte, hoje, semana e filtros viram query do GET (sem polling)', async () => {
        const wrapper = mountAgenda();

        await wrapper.find('[data-testid="agenda-next"]').trigger('click');
        expect(__router.get).toHaveBeenLastCalledWith(
            '/agenda',
            expect.objectContaining({ view: 'day', date: '2026-10-20' }),
            expect.any(Object),
        );

        await wrapper.find('[data-testid="agenda-prev"]').trigger('click');
        expect(__router.get).toHaveBeenLastCalledWith(
            '/agenda',
            expect.objectContaining({ date: '2026-10-18' }),
            expect.any(Object),
        );

        await wrapper.find('[data-testid="view-week"]').trigger('click');
        expect(__router.get).toHaveBeenLastCalledWith(
            '/agenda',
            expect.objectContaining({ view: 'week', date: '2026-10-19' }),
            expect.any(Object),
        );

        await wrapper.find('#agenda-facility').setValue('f1');
        expect(__router.get).toHaveBeenLastCalledWith(
            '/agenda',
            expect.objectContaining({ facility: 'f1' }),
            expect.any(Object),
        );

        await wrapper.find('#agenda-surgeon').setValue('d2');
        expect(__router.get).toHaveBeenLastCalledWith(
            '/agenda',
            expect.objectContaining({ surgeon: 'd2' }),
            expect.any(Object),
        );
    });

    it('na semana, anda 7 dias e lista os casos por dia', async () => {
        const wrapper = mountAgenda({
            view: 'week',
            date: '2026-10-21',
            range: { from: '2026-10-19', to: '2026-10-25' },
            cases: [
                caseRow('a', '2026-10-19T09:00:00', '2026-10-19T10:00:00'),
                caseRow('b', '2026-10-23T14:00:00', '2026-10-23T15:00:00'),
            ],
        });

        expect(wrapper.findAll('[data-testid="week-case"]')).toHaveLength(2);

        await wrapper.find('[data-testid="agenda-next"]').trigger('click');
        expect(__router.get).toHaveBeenLastCalledWith(
            '/agenda',
            expect.objectContaining({ date: '2026-10-28' }),
            expect.any(Object),
        );
    });

    it('o botão do mapa leva a data e o local do filtro', () => {
        const wrapper = mountAgenda({ filters: { facility: 'f1', surgeon: null } });

        expect(wrapper.find('[data-testid="agenda-map"]').attributes('href')).toBe('/map?date=2026-10-19&facility=f1');
    });

    it('a fila lista os autorizados e abre o formulário de agendar com o caso certo', async () => {
        const wrapper = mountAgenda({
            queue: [
                {
                    ...caseRow('q1', null, null),
                    status: { value: 'authorized', label: 'Autorizada', tone: 'info' },
                    urgent: true,
                    duration: 45,
                    valid_until: '2026-12-01',
                    revision: 5,
                },
            ],
        });

        expect(wrapper.find('[data-testid="queue-item"]').text()).toContain('Urgência');
        expect(wrapper.find('[data-testid="queue-item"]').text()).toContain('45 min');

        await wrapper.find('[data-testid="queue-schedule"]').trigger('click');

        expect(wrapper.find('[data-testid="schedule-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="schedule-form"]').text()).toContain('Cirurgia q1');
    });

    it('sem permissão de gerenciar, a fila não oferece Agendar', () => {
        const wrapper = mountAgenda({
            can: { manage: false },
            queue: [{ ...caseRow('q1', null, null), urgent: false, duration: 60, valid_until: null }],
        });

        expect(wrapper.find('[data-testid="queue-schedule"]').exists()).toBe(false);
    });

    it('estados vazios: fila vazia, dia sem cirurgia e sem local cadastrado', () => {
        const wrapper = mountAgenda({ options: { facilities: [], surgeons: [] } });

        expect(wrapper.find('[data-testid="queue-empty"]').text()).toBe('Fila vazia');
        expect(wrapper.find('[data-testid="agenda-day-empty"]').text()).toBe('Nenhuma cirurgia');
        expect(wrapper.find('[data-testid="agenda-no-facility"]').text()).toContain('Sem local');
    });

    it('médico sem cadastro vê o aviso e nenhuma grade', () => {
        const wrapper = mountAgenda({ doctorMissing: true, doctorMissingText: 'Sem cadastro de médico' });

        expect(wrapper.text()).toContain('Sem cadastro de médico');
        expect(wrapper.find('[data-testid="agenda-title"]').exists()).toBe(false);
    });
});
