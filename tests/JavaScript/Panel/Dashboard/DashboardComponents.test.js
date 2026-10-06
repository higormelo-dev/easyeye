import { describe, it, expect, vi, afterEach } from 'vitest';
import { nextTick } from 'vue';
import { mount as baseMount } from '@vue/test-utils';
import KpiCards from '@/Pages/Panel/Dashboard/KpiCards.vue';
import KpiStatCard from '@/Pages/Panel/Dashboard/KpiStatCard.vue';
import ModuleShortcuts from '@/Pages/Panel/Dashboard/ModuleShortcuts.vue';
import DashboardHeader from '@/Pages/Panel/Dashboard/DashboardHeader.vue';
import ScheduleToday from '@/Pages/Panel/Dashboard/ScheduleToday.vue';
import DaySummary from '@/Pages/Panel/Dashboard/DaySummary.vue';
import RecentPatients from '@/Pages/Panel/Dashboard/RecentPatients.vue';
import StockAlerts from '@/Pages/Panel/Dashboard/StockAlerts.vue';
import NextPatient from '@/Pages/Panel/Dashboard/NextPatient.vue';
import PendingList from '@/Pages/Panel/Dashboard/PendingList.vue';
import DoctorPending from '@/Pages/Panel/Dashboard/DoctorPending.vue';
import { T, ALL, NONE, INSIGHTS_ADMIN, INSIGHTS_FINANCIAL } from './fixtures.js';

/**
 * Dashboard v2 — componentes: indicadores por perfil com variação ▲▼ e cor
 * semântica, links só com acesso (sem 403), estados vazios compactos,
 * andamento do dia corrigido, textos traduzidos (nada fixo) e âncoras do tour.
 */

// `route()` nos templates vem do plugin ZiggyVue no app real.
// Relógio fixo: as abas de turno da agenda seguem a hora atual.
function clockAt(hour, minute = 0) {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
    vi.setSystemTime(new Date(2026, 9, 6, hour, minute));
}
afterEach(() => vi.useRealTimers());

const mount = (component, options = {}) =>
    baseMount(component, { ...options, global: { mocks: { route: globalThis.route } } });

const STATS = {
    total_patients: 12,
    today_count: 14,
    total_doctors: 2,
    attended_today: 3,
    pending_today: 9,
    cancelled_today: 2,
    waiting_now: 2,
    exams_pending: 7,
};

const keysOf = (w) => w.findAll('[data-kpi]').map((c) => c.attributes('data-kpi'));
const kpi = (w, key) => w.find(`[data-kpi="${key}"]`);

describe('KpiCards — indicadores por perfil', () => {
    it('administração: mês × mesmo período do mês anterior, com o período explícito no título', () => {
        const w = mount(KpiCards, {
            props: { stats: STATS, profile: 'admin', insights: INSIGHTS_ADMIN, access: ALL, t: T },
        });

        expect(keysOf(w)).toEqual(['occupancy', 'attendance', 'noshow', 'new_patients', 'income', 'receivable']);
        expect(w.text()).toContain('Month indicators');
        expect(w.find('[data-test="kpi-period"]').text()).toMatch(/1.*6.*set/);

        // Ocupação caiu: ▼ vermelho (ruim). Taxa de falta caiu: ▼ verde (bom).
        const occupancy = kpi(w, 'occupancy').find('.db-delta');
        expect(occupancy.attributes('data-delta')).toBe('down');
        expect(occupancy.attributes('data-tone')).toBe('bad');
        expect(occupancy.text()).toContain('▼');
        expect(occupancy.text()).toContain('pp');

        const noshow = kpi(w, 'noshow').find('.db-delta');
        expect(noshow.attributes('data-delta')).toBe('down');
        expect(noshow.attributes('data-tone')).toBe('good');
        expect(noshow.classes()).toContain('db-delta--good');

        // Receita subiu: ▲ verde; leitor de tela recebe o texto completo.
        const income = kpi(w, 'income');
        expect(income.find('.db-delta').attributes('data-tone')).toBe('good');
        expect(income.find('.visually-hidden').text()).toContain('Up');
        expect(income.find('.db-metric__value').text()).toContain('5.040,00');

        // A receber: posição de hoje (sem variação), com o vencido em destaque.
        const receivable = kpi(w, 'receivable');
        expect(receivable.find('.db-delta').exists()).toBe(false);
        expect(receivable.text()).toContain('overdue');
        expect(receivable.classes()).toContain('db-metric--alert');
    });

    it('anterior zerado: sem seta e com "sem dados no período" no lugar do comparativo', () => {
        const w = mount(KpiCards, {
            props: { stats: STATS, profile: 'admin', insights: INSIGHTS_ADMIN, access: ALL, t: T },
        });
        const card = kpi(w, 'new_patients');

        expect(card.find('.db-delta').exists()).toBe(false);
        expect(card.find('.db-metric__caption').text()).toMatch(/^No data in/);
    });

    it('administração sem acesso ao financeiro: consultas realizadas e exames no lugar de receita/a receber', () => {
        const w = mount(KpiCards, {
            props: {
                stats: STATS,
                profile: 'admin',
                insights: { ...INSIGHTS_ADMIN, finance: false, receivables: null },
                access: { ...ALL, financial: false },
                t: T,
            },
        });

        expect(keysOf(w)).toEqual(['occupancy', 'attendance', 'noshow', 'new_patients', 'attended', 'exams']);
    });

    it('financeiro: só valores do mês com variação; glosa subir é ruim', () => {
        const w = mount(KpiCards, {
            props: { stats: {}, profile: 'financial', insights: INSIGHTS_FINANCIAL, access: ALL, t: T },
        });

        expect(keysOf(w)).toEqual(['income', 'billed', 'paid', 'glosa', 'ticket', 'receipt_rate']);
        expect(kpi(w, 'glosa').find('.db-delta').attributes('data-tone')).toBe('bad');
        expect(kpi(w, 'receipt_rate').find('.db-delta').text()).toContain('pp');
    });

    it('médico: só os números dele, com o mês × mês anterior; exames abrem filtrados por ele', () => {
        globalThis.route.mockClear();
        const doctorStats = {
            doctor_id: 'doc-1',
            today_count: 4,
            attended_today: 1,
            cancelled_today: 0,
            waiting_now: 2,
            exams_pending: 3,
        };
        const insights = {
            period: INSIGHTS_ADMIN.period,
            finance: false,
            month: {
                current: { attended: 20, noshow_rate: 4.8 },
                previous: { attended: 20, noshow_rate: 16.7 },
            },
        };
        const w = mount(KpiCards, {
            props: {
                stats: doctorStats,
                profile: 'doctor',
                insights,
                aiWaiting: { count: 5, items: [], list_url: '/ai?status=waiting_approval' },
                access: { ...ALL, doctors: false, financial: false },
                t: T,
            },
        });

        expect(keysOf(w)).toEqual(['my_today', 'my_waiting', 'my_month', 'my_noshow', 'my_exams', 'ai_waiting']);
        expect(kpi(w, 'my_month').find('.db-delta').attributes('data-delta')).toBe('flat');
        expect(kpi(w, 'my_noshow').find('.db-delta').attributes('data-tone')).toBe('good');
        expect(kpi(w, 'my_today').text()).toContain('1 of 4 attended');
        expect(kpi(w, 'my_waiting').classes()).toContain('db-metric--alert');
        expect(globalThis.route).toHaveBeenCalledWith('panel.eye-images.index', {
            status: 'pendente',
            period: '30',
            doctor_id: 'doc-1',
        });

        // Sem IA na clínica o card some; sem cadastro de médico, nada vira link para a Agenda.
        const noAi = mount(KpiCards, {
            props: { stats: { ...doctorStats, doctor_id: null }, profile: 'doctor', insights: null, access: ALL, t: T },
        });
        expect(kpi(noAi, 'ai_waiting').exists()).toBe(false);
        expect(kpi(noAi, 'my_today').element.tagName).toBe('DIV');
        expect(kpi(noAi, 'my_month').find('.db-metric__value').text()).toBe('—');
    });

    it('recepção: confirmadas hoje, amanhã sem confirmação e lista de espera', () => {
        const reception = {
            waiting_room: { count: 2, longest: 42, items: [] },
            days: {
                today: { total: 14, confirmed: 9, unconfirmed: 5 },
                tomorrow: { total: 12, confirmed: 5, unconfirmed: 7, url: '/agenda?date=amanha' },
            },
        };
        const w = mount(KpiCards, {
            props: { stats: STATS, profile: 'secretary', reception, waitlist: { count: 4 }, access: ALL, t: T },
        });

        expect(keysOf(w)).toEqual(['today', 'waiting', 'confirmed_today', 'tomorrow', 'waitlist', 'exams']);
        expect(kpi(w, 'confirmed_today').find('.db-metric__value').text()).toBe('64%');
        expect(kpi(w, 'confirmed_today').text()).toContain('9 of 14 appointments');
        expect(kpi(w, 'tomorrow').text()).toContain('7 not confirmed');
        expect(kpi(w, 'tomorrow').classes()).toContain('db-metric--alert');
        expect(kpi(w, 'waiting').text()).toContain('Longest wait: 42 min');
        expect(kpi(w, 'waitlist').find('.db-metric__value').text()).toBe('4');
    });

    it('usuário sem acesso: só números, nenhum link (sem 403)', () => {
        const w = mount(KpiCards, { props: { stats: STATS, profile: 'user', access: NONE, t: T } });

        expect(keysOf(w)).toEqual(['patients', 'today', 'exams']);
        expect(w.findAll('a')).toHaveLength(0);
        expect(w.find('.db-delta').exists()).toBe(false);

        const linked = mount(KpiCards, {
            props: { stats: STATS, profile: 'user', access: { ...NONE, patients: true }, t: T },
        });
        expect(linked.findAll('a')).toHaveLength(1);
        expect(linked.find('a').text()).toContain('Patients');
    });

    it('"Atualizar" recalculando: esqueleto só nos números de gestão; o polling não pisca os números', () => {
        const loading = mount(KpiCards, {
            props: {
                stats: STATS,
                profile: 'admin',
                insights: INSIGHTS_ADMIN,
                access: ALL,
                loadingInsights: true,
                t: T,
            },
        });
        expect(loading.findAll('.stat-skeleton').length).toBeGreaterThan(0);
        expect(kpi(loading, 'occupancy').find('.stat-skeleton').exists()).toBe(true);

        const polling = mount(KpiCards, {
            props: { stats: STATS, profile: 'secretary', access: ALL, isRefreshing: true, t: T },
        });
        expect(polling.find('.stat-skeleton').exists()).toBe(false);
        expect(kpi(polling, 'today').find('.db-metric__value').text()).toBe('14');
        expect(kpi(polling, 'today').classes()).toContain('db-metric--refreshing');
    });
});

describe('KpiStatCard', () => {
    it('formata número, moeda e % no idioma; vira link com título traduzido só com url', () => {
        const number = mount(KpiStatCard, { props: { kpi: { key: 'a', label: 'Patients', value: 12345 }, t: T } });
        expect(number.find('.db-metric__value').text()).toBe('12.345');
        expect(number.element.tagName).toBe('DIV');

        const money = mount(KpiStatCard, {
            props: { kpi: { key: 'b', label: 'Revenue', value: 1234.5, format: 'money', url: '/bi' }, t: T },
        });
        expect(money.find('.db-metric__value').text()).toContain('1.234,50');
        expect(money.element.tagName).toBe('A');
        expect(money.attributes('title')).toBe('View Revenue list');

        const pct = mount(KpiStatCard, {
            props: { kpi: { key: 'c', label: 'Rate', value: 96.6, format: 'pct' }, t: T },
        });
        expect(pct.find('.db-metric__value').text()).toBe('96,6%');

        const empty = mount(KpiStatCard, { props: { kpi: { key: 'd', label: 'X', value: null }, t: T } });
        expect(empty.find('.db-metric__value').text()).toBe('—');
    });
});

describe('DashboardHeader — posto de trabalho e ações do perfil', () => {
    const header = (props) => mount(DashboardHeader, { props: { access: ALL, t: T, ...props } });
    const actions = (w) => w.findAll('[data-action]').map((a) => a.attributes('data-action'));

    it('papel, data de hoje e saudação traduzidos', () => {
        clockAt(9);
        const w = header({ profile: 'secretary' });

        expect(w.find('[data-test="dashboard-role"]').text()).toBe('Front desk');
        expect(w.text()).toContain('Good morning');
        expect(w.text()).toMatch(/2026/);
        expect(w.find('[data-tour="dashboard-welcome"]').exists()).toBe(true);
        expect(w.find('[data-tour="dashboard-live"]').exists()).toBe(true);
    });

    it('ações primárias por perfil, só com acesso à tela', () => {
        expect(actions(header({ profile: 'secretary' }))).toEqual(['new_schedule', 'new_patient']);
        expect(actions(header({ profile: 'admin' }))).toEqual(['new_schedule', 'new_patient', 'bi']);
        expect(actions(header({ profile: 'financial' }))).toEqual(['new_cash_entry', 'bi']);
        expect(actions(header({ profile: 'financial', access: { ...ALL, financial: false } }))).toEqual([]);
        expect(actions(header({ profile: 'secretary', access: NONE }))).toEqual([]);
        expect(actions(header({ profile: 'user', access: NONE }))).toEqual([]);

        // "Novo agendamento" abre o formulário na Agenda (?new=1); "Lançar no caixa", no caixa.
        expect(header({ profile: 'secretary' }).find('[data-action="new_schedule"]').attributes('href')).toBe(
            '/_routes/panel.schedules.index?new=1',
        );
        expect(header({ profile: 'financial' }).find('[data-action="new_cash_entry"]').attributes('href')).toBe(
            '/_routes/panel.financial.cash-flow.index?new=1',
        );
    });

    it('médico: "Iniciar atendimento" no cabeçalho só se o card do próximo paciente estiver oculto', () => {
        const nextPatient = { attend_url: '/schedules/s1/attend' };

        expect(actions(header({ profile: 'doctor', nextPatient, nextVisible: true }))).toEqual(['my_schedule']);
        expect(actions(header({ profile: 'doctor', nextPatient, nextVisible: false }))).toEqual([
            'attend',
            'my_schedule',
        ]);
        expect(actions(header({ profile: 'doctor', nextPatient, doctorMissing: true }))).toEqual(['attend']);
    });

    it('"Atualizar" emite refresh; durante a atualização fica desabilitado', async () => {
        const w = header({ profile: 'admin' });
        await w.find('[data-test="dashboard-refresh"]').trigger('click');
        expect(w.emitted('refresh')).toHaveLength(1);

        expect(
            header({ profile: 'admin', isRefreshing: true }).find('[data-test="dashboard-refresh"]').attributes(),
        ).toHaveProperty('disabled');
    });
});

describe('ModuleShortcuts', () => {
    const labels = (w) => w.findAll('[data-module]').map((m) => m.attributes('data-module'));

    it('mostra só os módulos que o perfil pode abrir; financeiro vê os dele primeiro', () => {
        expect(labels(mount(ModuleShortcuts, { props: { access: ALL, t: T } }))).toEqual([
            'schedule',
            'patients',
            'eye-images',
            'financial',
            'tiss',
            'glosas',
            'bi',
            'surgery',
        ]);
        expect(
            labels(mount(ModuleShortcuts, { props: { access: ALL, profile: 'financial', showSoon: false, t: T } })),
        ).toEqual(['financial', 'tiss', 'glosas', 'bi', 'schedule', 'patients', 'eye-images']);
        expect(labels(mount(ModuleShortcuts, { props: { access: NONE, showSoon: false, t: T } }))).toEqual([
            'eye-images',
        ]);
    });

    it('"Em breve" sem link e textos traduzidos; âncoras do tour', () => {
        const w = mount(ModuleShortcuts, { props: { access: ALL, t: T } });
        const soon = w.find('[data-module="surgery"]');

        expect(soon.element.tagName).toBe('DIV');
        expect(soon.attributes('aria-disabled')).toBe('true');
        expect(soon.text()).toContain('Coming soon');
        expect(w.find('[data-tour="dashboard-shortcuts"]').text()).toContain('Claim denials');
        expect(w.find('[data-tour="dashboard-shortcuts-customize"]').exists()).toBe(true);
    });
});

describe('ScheduleToday — abas por turno, colunas e linha de apoio', () => {
    const row = (id, hour, minute, extra = {}) => ({
        id,
        time: `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`,
        hour,
        hour_label: `${String(hour).padStart(2, '0')}:00`,
        shift: hour < 13 ? 'morning' : hour < 18 ? 'afternoon' : 'evening',
        name: `Paciente ${id}`,
        doctor: 'Dra. Ana',
        badge: 'bg-secondary',
        icon: 'fa-calendar',
        label: 'Scheduled',
        arrived: false,
        is_active: false,
        ...extra,
    });
    const ITEMS = [row('a', 8, 0), row('b', 8, 15), row('c', 9, 0), row('d', 14, 30), row('e', 15, 0)];
    const names = (w) => w.findAll('.schedule-name').map((n) => n.text());

    it('situação logo depois do horário (não flutua longe do nome); médico por último', () => {
        clockAt(9);
        const w = mount(ScheduleToday, { props: { items: ITEMS, t: T } });

        expect(w.findAll('thead th').map((th) => th.text())).toEqual(['Time', 'Situation', 'Patient', 'Doctor']);
        expect(w.findAll('colgroup col').map((c) => c.attributes('class'))).toEqual([
            'col-time',
            'col-situation',
            'col-patient',
            'col-doctor',
        ]);
    });

    it('linha de apoio: tipo de consulta · convênio · chegada', () => {
        clockAt(9);
        const items = [row('a', 9, 0, { visit: 'RETORNO', covenant: 'UNIMED', arrived: true, arrived_time: '08:52' })];
        const w = mount(ScheduleToday, { props: { items, t: T } });

        expect(w.find('.schedule-detail').text()).toBe('RETORNO · UNIMED · arrived at 08:52');
        expect(w.find('.ti-circle-check').attributes('aria-label')).toBe('Arrived');
    });

    it('agenda do médico: título "minha agenda", sem coluna de médico; vazio traduzido e compacto', () => {
        clockAt(9);
        const mine = mount(ScheduleToday, {
            props: { items: [row('a', 9, 0)], mine: true, canOpenSchedule: true, t: T },
        });
        expect(mine.text()).toContain('My schedule today');
        expect(mine.findAll('thead th')).toHaveLength(3);

        const empty = mount(ScheduleToday, { props: { items: [], mine: true, t: T } });
        expect(empty.find('.db-empty').text()).toContain('You have no appointments today.');
    });

    it('botão da agenda só com acesso, no turno da aba; aviso quando a lista vem cortada', () => {
        clockAt(9);
        const withAccess = mount(ScheduleToday, { props: { items: ITEMS, canOpenSchedule: true, t: T } });
        expect(withAccess.find('a').attributes('href')).toBe('/_routes/panel.schedules.index?bout=2');
        expect(
            mount(ScheduleToday, { props: { items: ITEMS, t: T } })
                .find('a')
                .exists(),
        ).toBe(false);

        const truncated = mount(ScheduleToday, { props: { items: ITEMS, total: 40, t: T } });
        expect(truncated.find('[role="note"]').text()).toBe('Showing 5 of 40 appointments today.');
    });

    it('ao lado da coluna lateral (fillHeight), a lista rola por dentro com altura vinda da célula', () => {
        clockAt(9);
        const capped = mount(ScheduleToday, { props: { items: ITEMS, fillHeight: true, t: T } });
        expect(capped.classes()).toContain('schedule-today--capped');
        // Altura vem do CSS da célula (.db-agenda-cell), não de estilo inline medido em JS.
        expect(capped.attributes('style')).toBeUndefined();

        expect(mount(ScheduleToday, { props: { items: ITEMS, t: T } }).classes()).not.toContain(
            'schedule-today--capped',
        );
    });

    it('abre no turno atual, com contagem por aba; noite só aparece se tiver consulta ou for o turno', () => {
        clockAt(14, 40);
        const w = mount(ScheduleToday, { props: { items: ITEMS, canOpenSchedule: true, t: T } });

        const tabs = w.findAll('[role="tab"]');
        expect(tabs.map((tab) => tab.text())).toEqual(['Morning 3', 'Afternoon 2Now']);
        expect(tabs[1].attributes('aria-selected')).toBe('true');
        expect(names(w)).toEqual(['Paciente d', 'Paciente e']);
    });

    it('clique muda de aba e vale até o turno virar; depois a aba segue o relógio sozinha', async () => {
        clockAt(12, 59);
        const w = mount(ScheduleToday, { props: { items: ITEMS, t: T } });
        expect(names(w)).toEqual(['Paciente a', 'Paciente b', 'Paciente c']);

        await w.findAll('[role="tab"]')[1].trigger('click');
        expect(names(w)).toEqual(['Paciente d', 'Paciente e']);

        await w.findAll('[role="tab"]')[0].trigger('click');
        vi.setSystemTime(new Date(2026, 9, 6, 13, 0));
        vi.advanceTimersByTime(60_000);
        await nextTick();
        expect(w.find('[role="tab"][aria-selected="true"]').text()).toContain('Afternoon');
    });

    it('agrupa por hora e destaca a hora atual; teclado troca de aba', async () => {
        clockAt(8, 20);
        const w = mount(ScheduleToday, { props: { items: ITEMS, t: T }, attachTo: document.body });

        const groups = w.findAll('.schedule-hour-row');
        expect(groups.map((g) => g.find('th').text().replace(/\s+/g, ' '))).toEqual(['08:00 · 2Now', '09:00 · 1']);
        expect(groups[0].classes()).toContain('schedule-hour-row--now');

        await w.findAll('[role="tab"]')[0].trigger('keydown', { key: 'ArrowRight' });
        expect(w.find('[role="tabpanel"]').attributes('aria-labelledby')).toBe('schedule-shift-tab-afternoon');
        w.unmount();
    });
});

describe('DaySummary — andamento sobre o que ainda conta', () => {
    const item = (id, hour, group) => ({ id, hour, shift: hour < 13 ? 'morning' : 'afternoon', group });

    it('"0 de 14 atendidos" com cancelados/faltas à parte — nada de "30% concluído" com 0 atendidos', () => {
        clockAt(10);
        const w = mount(DaySummary, {
            props: {
                stats: { today_count: 20, attended_today: 0, pending_today: 14, cancelled_today: 6, waiting_now: 0 },
                t: T,
            },
        });

        expect(w.find('[data-summary="progress"]').text()).toContain('0 of 14 attended');
        expect(w.find('[data-summary="progress"]').text()).toContain('0%');
        expect(w.find('[data-summary="missed-note"]').text()).toBe('6 no-shows/cancellations not counted.');
        expect(w.find('.ds-progress--attended').attributes('style')).toContain('width: 0%');
    });

    it('barra: atendidos, na clínica e restantes sobre o previsto; grade com os 4 números', () => {
        clockAt(10);
        const w = mount(DaySummary, {
            props: {
                stats: { today_count: 12, attended_today: 3, pending_today: 7, cancelled_today: 2, waiting_now: 2 },
                t: T,
            },
        });

        expect(w.findAll('.ds-grid [data-summary]').map((n) => n.attributes('data-summary'))).toEqual([
            'total',
            'attended',
            'pending',
            'cancelled',
        ]);
        expect(w.find('[data-summary="progress"]').text()).toContain('3 of 10 attended');
        expect(w.findAll('.progress-bar').map((b) => b.attributes('style'))).toEqual([
            'width: 30%;',
            'width: 20%;',
            'width: 50%;',
        ]);
        expect(w.find('.progress').attributes('aria-label')).toBe('Day progress: 3 of 10 attended (30%)');
        expect(w.find('.card').classes()).not.toContain('h-100');
    });

    it('todas canceladas: aviso no lugar da barra; dia vazio: só os zeros; médico vê "Meu dia"', () => {
        clockAt(10);
        const allMissed = mount(DaySummary, {
            props: { stats: { today_count: 3, attended_today: 0, pending_today: 0, cancelled_today: 3 }, t: T },
        });
        expect(allMissed.find('.progress').exists()).toBe(false);
        expect(allMissed.find('[data-summary="all-missed"]').exists()).toBe(true);

        const empty = mount(DaySummary, {
            props: {
                stats: { today_count: 0, attended_today: 0, pending_today: 0, cancelled_today: 0 },
                mine: true,
                t: T,
            },
        });
        expect(empty.text()).toContain('My day');
        expect(empty.find('.progress').exists()).toBe(false);
    });

    it('quebra por turno com o turno atual destacado; lista cortada esconde a quebra', () => {
        clockAt(14);
        const items = [item('a', 8, 'attended'), item('b', 9, 'pending'), item('c', 14, 'pending')];
        const stats = { today_count: 3, attended_today: 1, pending_today: 2, cancelled_today: 0 };
        const w = mount(DaySummary, { props: { stats, items, t: T } });

        const rows = w.findAll('.ds-shifts tbody tr');
        expect(rows.map((r) => r.attributes('data-shift'))).toEqual(['morning', 'afternoon']);
        expect(rows[1].classes()).toContain('ds-shift--now');

        const cut = mount(DaySummary, { props: { stats: { ...stats, today_count: 300 }, items, t: T } });
        expect(cut.find('.ds-shifts').exists()).toBe(false);
    });
});

describe('Listas e estados vazios compactos', () => {
    it('pendência zerada: uma linha discreta (role=status), sem card grande nem links', () => {
        const w = mount(PendingList, {
            props: { title: 'Unsigned', icon: 'ti ti-signature', count: 0, items: [], emptyText: 'All signed', t: T },
        });

        expect(w.classes()).toContain('pending-ok');
        expect(w.attributes('role')).toBe('status');
        expect(w.text()).toBe('All signed');
        expect(w.findAll('a')).toHaveLength(0);
    });

    it('pendência com itens: contagem, links e aviso quando não mostra todos', () => {
        const items = [
            { id: 'r1', title: 'Maria', subtitle: 'Eye image analysis', url: '/eye?exam=1' },
            { id: 'r2', title: 'João', subtitle: 'Case analysis', url: '/ai' },
        ];
        const w = mount(PendingList, {
            props: {
                title: 'AI reports',
                icon: 'ti ti-sparkles',
                count: 7,
                items,
                actionLabel: 'Review',
                seeAllUrl: '/ai?status=waiting_approval',
                t: T,
            },
        });

        expect(w.find('.pending-list-count').attributes('aria-label')).toBe('Pending: 7');
        expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual([
            '/ai?status=waiting_approval',
            '/eye?exam=1',
            '/ai',
        ]);
        expect(w.find('a[aria-label="Review: Maria"]').exists()).toBe(true);
        expect(w.find('[role="note"]').text()).toBe('Showing 2 of 7.');
    });

    it('"Minhas pendências": sem IA na clínica, só prontuários; total no título', () => {
        const w = mount(DoctorPending, {
            props: { aiWaiting: null, unsignedRecords: { count: 0, items: [], days: 30 }, t: T },
        });

        expect(w.find('[data-tour="dashboard-ai-waiting"]').exists()).toBe(false);
        expect(w.find('[data-tour="dashboard-unsigned-records"]').text()).toBe(
            'None of your records from the last 30 days are unsigned.',
        );
        expect(w.find('.db-count').text()).toBe('0');
    });

    it('pacientes recentes: "ver todos"/"ver" só com acesso; do médico, última consulta no lugar do telefone', () => {
        const patients = [
            {
                id: 'p1',
                name: 'Maria',
                phone: '(11) 9',
                last_visit: '10/05/2026',
                code: 'P1',
                initial: 'M',
                color: '#000',
                url: '/p1',
            },
        ];

        expect(mount(RecentPatients, { props: { patients, canOpenPatients: true, t: T } }).findAll('a')).toHaveLength(
            2,
        );
        expect(mount(RecentPatients, { props: { patients, canOpenPatients: false, t: T } }).findAll('a')).toHaveLength(
            0,
        );

        const mine = mount(RecentPatients, { props: { patients, mine: true, canOpenPatients: true, t: T } });
        expect(mine.text()).toContain('My recent patients');
        expect(mine.text()).toContain('Last visit: 10/05/2026');
        expect(mine.text()).not.toContain('Phone');
        expect(mine.find('a[aria-label="View: Maria"]').exists()).toBe(true);
        expect(
            mount(RecentPatients, { props: { patients: [], mine: true, t: T } })
                .find('.db-empty')
                .text(),
        ).toContain('You have not seen any patients yet.');
    });

    it('alertas de estoque: "ver estoque" abre a lista completa; plural traduzido', () => {
        const alerts = {
            below_minimum_count: 1,
            expiring_lots_count: 3,
            list_url: '/stock',
            products_url: '/stock?low=1',
            expiring_url: '/stock?exp=1',
        };
        const w = mount(StockAlerts, { props: { alerts, t: T } });

        expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual(['/stock', '/stock?low=1', '/stock?exp=1']);
        expect(w.text()).toContain('1 product below the minimum');
        expect(w.text()).toContain('3 products with lots expiring');
    });
});

describe('NextPatient', () => {
    const waiting = {
        id: 's1',
        name: 'Maria Souza',
        state: 'waiting',
        time: '10:00',
        arrived_time: '11:30',
        waiting_minutes: 30,
        label: 'Waiting',
        badge: 'bg-warning text-dark',
        icon: 'fa-clock',
        attend_url: '/schedules/s1/attend',
        patient_url: '/patients?open=p1',
        schedule_url: '/schedules',
    };

    it('quem chegou: chegada, tempo de espera e "Iniciar atendimento"; ninguém: estado vazio', () => {
        const w = mount(NextPatient, { props: { patient: waiting, t: T } });

        expect(w.text()).toContain('Arrived at 11:30 · waiting for 30 min');
        expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual(['/schedules/s1/attend', '/patients?open=p1']);
        expect(w.find('a.btn-primary').text()).toContain('Start appointment');

        const empty = mount(NextPatient, { props: { patient: null, t: T } });
        expect(empty.text()).toContain('Nobody is waiting');
        expect(empty.findAll('a')).toHaveLength(0);
    });
});
