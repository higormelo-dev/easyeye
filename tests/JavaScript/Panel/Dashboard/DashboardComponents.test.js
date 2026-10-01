import { describe, it, expect } from 'vitest';
import { mount as baseMount } from '@vue/test-utils';
import KpiCards from '@/Pages/Panel/Dashboard/KpiCards.vue';
import ModuleShortcuts from '@/Pages/Panel/Dashboard/ModuleShortcuts.vue';
import WelcomeBanner from '@/Pages/Panel/Dashboard/WelcomeBanner.vue';
import ScheduleToday from '@/Pages/Panel/Dashboard/ScheduleToday.vue';
import RecentPatients from '@/Pages/Panel/Dashboard/RecentPatients.vue';
import StockAlerts from '@/Pages/Panel/Dashboard/StockAlerts.vue';

/**
 * Dashboard: atalhos só viram link quando o usuário pode abrir a tela
 * (`access`, mesmas regras das rotas — sem 403), âncoras do tour guiado nas
 * regiões com mais de uma raiz e textos traduzidos (nada fixo).
 */

const T = {
    kpi_patients: 'Patients',
    kpi_today: 'Appointments today',
    kpi_doctors: 'Active doctors',
    kpi_surgeries: 'Surgeries today',
    kpi_exams_pending: 'Pending exams',
    kpi_guides_waiting: 'Claims waiting',
    kpi_receivable: 'Receivable',
    kpi_satisfaction: 'Satisfaction',
    kpi_coming_soon: 'Coming soon',
    kpi_open_list: 'Open :label list',
    module_schedule: 'Schedule',
    module_eye_images: 'Eye Images',
    module_tiss: 'TISS claims',
    module_financial: 'Financial',
    module_surgery: 'Surgical center',
    coming_soon: 'Coming soon',
    shortcuts: 'Shortcuts',
    shortcuts_title: 'Choose favorite shortcuts',
    shortcuts_menu: 'Favorite shortcuts',
    operational_panel: 'Operational panel of :app',
    btn_patients: 'Patients',
    btn_new_patient: 'New patient',
    greeting_morning: 'Good morning',
    greeting_afternoon: 'Good afternoon',
    greeting_evening: 'Good evening',
    section_schedule_today: "Today's schedule",
    live_label: 'Live',
    btn_see_schedule: 'See full schedule',
    empty_schedules: 'No appointments',
    col_time: 'Time',
    col_name: 'Patient',
    col_doctor: 'Doctor',
    col_situation: 'Status',
    arrived: 'Arrived',
    section_recent_patients: 'Recent patients',
    btn_see_all: 'See all',
    btn_view: 'View',
    empty_patients: 'No patients',
    col_phone: 'Phone',
    col_code: 'Code',
    stock_title: 'Stock alerts',
    stock_see: 'View stock',
    stock_below_minimum_one: ':count product below the minimum',
    stock_below_minimum_other: ':count products below the minimum',
    stock_below_minimum_hint: 'Restock.',
    stock_expiring_one: ':count product with a lot expiring',
    stock_expiring_other: ':count products with lots expiring',
    stock_expiring_hint: 'Next 30 days.',
    schedule_showing: 'Showing :shown of :total appointments today.',
};

// `route()` nos templates vem do plugin ZiggyVue no app real.
const mount = (component, options = {}) =>
    baseMount(component, { ...options, global: { mocks: { route: globalThis.route } } });

const STATS = {
    total_patients: 12,
    today_count: 5,
    total_doctors: 2,
    attended_today: 1,
    pending_today: 3,
    cancelled_today: 1,
};
const ALL = { schedules: true, patients: true, doctors: true, financial: true };
const NONE = { schedules: false, patients: false, doctors: false, financial: false };

describe('KpiCards', () => {
    it('com acesso, pacientes, consultas e médicos viram links; sem acesso, só números (sem 403)', () => {
        const linked = mount(KpiCards, { props: { stats: STATS, access: ALL, t: T } });
        expect(linked.find('[data-tour="dashboard-kpis"]').findAll('a')).toHaveLength(3);

        const plain = mount(KpiCards, { props: { stats: STATS, access: { ...NONE, patients: true }, t: T } });
        const links = plain.find('[data-tour="dashboard-kpis"]').findAll('a');
        expect(links).toHaveLength(1);
        expect(links[0].text()).toContain('Patients');
        expect(plain.text()).toContain('5'); // consultas de hoje continuam visíveis, sem link
    });

    it('indicadores "em breve": só exames para o médico; guias, a receber e satisfação para os demais', () => {
        const doctor = mount(KpiCards, { props: { stats: STATS, isDoctor: true, access: ALL, t: T } });
        expect(doctor.find('[data-tour="dashboard-kpis-soon"]').text()).toContain('Pending exams');
        expect(doctor.find('[data-tour="dashboard-kpis-soon"]').text()).not.toContain('Receivable');

        const admin = mount(KpiCards, { props: { stats: STATS, access: ALL, t: T } });
        expect(admin.find('[data-tour="dashboard-kpis-soon"]').text()).toContain('Receivable');
    });
});

describe('ModuleShortcuts', () => {
    it('mostra só os módulos que o perfil pode abrir; textos traduzidos e âncoras do tour', () => {
        const admin = mount(ModuleShortcuts, { props: { access: ALL, t: T } });
        const labels = admin.find('[data-tour="dashboard-shortcuts"]').text();
        expect(labels).toContain('Schedule');
        expect(labels).toContain('TISS claims');
        expect(labels).toContain('Financial');
        expect(admin.find('[data-tour="dashboard-shortcuts-customize"]').text()).toContain('Shortcuts');

        const user = mount(ModuleShortcuts, { props: { access: NONE, t: T } });
        const userLabels = user.find('[data-tour="dashboard-shortcuts"]').text();
        expect(userLabels).not.toContain('Schedule');
        expect(userLabels).not.toContain('Financial');
        expect(userLabels).toContain('Eye Images'); // sem restrição na rota
        expect(userLabels).toContain('Surgical center'); // "em breve", sem link
    });
});

describe('WelcomeBanner, ScheduleToday e RecentPatients', () => {
    it('boas-vindas: atalhos de pacientes só com acesso; "Novo paciente" abre o cadastro (?new=1)', () => {
        const links = mount(WelcomeBanner, { props: { access: ALL, t: T } }).findAll('a');
        expect(links).toHaveLength(2);
        expect(links[0].attributes('href')).toBe('/_routes/panel.patients.index');
        expect(links[1].attributes('href')).toBe('/_routes/panel.patients.index?new=1');

        expect(mount(WelcomeBanner, { props: { access: NONE, t: T } }).findAll('a')).toHaveLength(0);
    });

    it('agenda de hoje: botão da agenda só com acesso; "chegou" traduzido e acessível', () => {
        const items = [
            {
                id: 's1',
                time: '09:00',
                name: 'Maria',
                doctor: 'Dra. Ana',
                badge: 'bg-info text-dark',
                icon: 'fa-check',
                label: 'Confirmed',
                arrived: true,
                is_active: true,
            },
        ];

        const withAccess = mount(ScheduleToday, { props: { items, canOpenSchedule: true, t: T } });
        expect(withAccess.find('a').attributes('href')).toBe('/_routes/panel.schedules.index');

        const arrived = withAccess.find('.ti-circle-check');
        expect(arrived.attributes('aria-label')).toBe('Arrived');
        expect(arrived.attributes('title')).toBe('Arrived');

        expect(
            mount(ScheduleToday, { props: { items, canOpenSchedule: false, t: T } })
                .find('a')
                .exists(),
        ).toBe(false);
    });

    it('agenda de hoje: avisa quando a lista não mostra todas as consultas do dia', () => {
        const items = [
            {
                id: 's1',
                time: '09:00',
                name: 'Maria',
                doctor: 'Dra. Ana',
                badge: 'bg-info text-dark',
                icon: 'fa-check',
                label: 'Confirmed',
                arrived: false,
                is_active: false,
            },
        ];

        const truncated = mount(ScheduleToday, { props: { items, total: 40, t: T } });
        expect(truncated.find('[role="note"]').text()).toBe('Showing 1 of 40 appointments today.');

        expect(
            mount(ScheduleToday, { props: { items, total: 1, t: T } })
                .find('[role="note"]')
                .exists(),
        ).toBe(false);
    });

    it('pacientes recentes: "ver todos" e "ver" só com acesso', () => {
        const patients = [
            { id: 'p1', name: 'Maria', phone: '—', code: 'P1', initial: 'M', color: '#000', url: '/patients/p1' },
        ];

        expect(mount(RecentPatients, { props: { patients, canOpenPatients: true, t: T } }).findAll('a')).toHaveLength(
            2,
        );
        expect(mount(RecentPatients, { props: { patients, canOpenPatients: false, t: T } }).findAll('a')).toHaveLength(
            0,
        );
    });
});

describe('StockAlerts', () => {
    it('"Ver estoque" abre a lista completa; cada alerta abre a lista filtrada', () => {
        const alerts = {
            below_minimum_count: 0,
            expiring_lots_count: 2,
            list_url: '/stock',
            products_url: '/stock?low=1',
            expiring_url: '/stock?exp=1',
        };
        const hrefs = mount(StockAlerts, { props: { alerts, t: T } })
            .findAll('a')
            .map((a) => a.attributes('href'));

        // Alerta só de validade: o cabeçalho não pode cair no filtro "abaixo do mínimo" (lista vazia).
        expect(hrefs).toEqual(['/stock', '/stock?exp=1']);
    });

    it('textos traduzidos com singular e plural', () => {
        const alerts = {
            below_minimum_count: 1,
            expiring_lots_count: 3,
            list_url: '/stock',
            products_url: '/stock?low=1',
            expiring_url: '/stock?exp=1',
        };
        const w = mount(StockAlerts, { props: { alerts, t: T } });

        expect(w.text()).toContain('Stock alerts');
        expect(w.text()).toContain('View stock');
        expect(w.text()).toContain('1 product below the minimum');
        expect(w.text()).toContain('3 products with lots expiring');
        expect(w.text()).not.toContain('produto');
    });
});
