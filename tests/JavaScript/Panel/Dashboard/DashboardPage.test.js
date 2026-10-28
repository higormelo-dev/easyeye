import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Dashboard from '@/Pages/Panel/Dashboard.vue';
import { T, ALL, NONE, INSIGHTS_ADMIN, INSIGHTS_FINANCIAL } from './fixtures.js';

/**
 * Dashboard v2 — "painel por função": a página monta o posto de trabalho de
 * cada perfil com as seções que o servidor liberou (na ordem padrão dele),
 * deixa ordenar/ocultar no "Personalizar", agrupa as seções compactas
 * vizinhas e separa o polling (operação de hoje) do "Atualizar" (gestão).
 */

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));
// Menu sempre aberto no teste: o conteúdo (lista de seções) fica no DOM.
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ColumnOrderMenu.vue', () => ({
    default: {
        props: ['columns', 'title', 'toggleable'],
        emits: ['move', 'toggle', 'reset'],
        template:
            '<ul class="order-menu"><li v-for="c in columns" :key="c.key" :data-key="c.key" :data-hidden="c.hidden ? 1 : 0" @click="$emit(\'toggle\', c.key)">{{ c.label }}</li></ul>',
    },
}));
vi.mock('chart.js', () => {
    class Chart {
        static register() {}
        destroy() {}
    }
    const part = {};

    return {
        Chart,
        BarController: part,
        BarElement: part,
        LineController: part,
        LineElement: part,
        PointElement: part,
        CategoryScale: part,
        LinearScale: part,
        Legend: part,
        Tooltip: part,
    };
});

const polling = vi.hoisted(() => ({ calls: [], refresh: null }));
vi.mock('@/composables/useDashboardPolling.js', async () => {
    const { ref: vueRef } = await import('vue');

    return {
        useDashboardPolling: (only, interval, options) => {
            polling.calls.push({ only, interval, options });
            polling.refresh = vi.fn();

            return {
                isRefreshing: vueRef(false),
                isFullRefresh: vueRef(false),
                lastUpdated: vueRef(new Date(2026, 9, 6, 10, 0)),
                refresh: polling.refresh,
            };
        },
    };
});

const prefs = vi.hoisted(() => ({ values: {}, saved: [] }));
vi.mock('@/composables/useUserPreferences.js', () => ({
    useUserPreferences: () => ({
        getPreference: (key) => prefs.values[key] ?? null,
        savePreference: (key, value) => prefs.saved.push([key, value]),
    }),
}));

beforeEach(() => {
    prefs.values = {};
    prefs.saved = [];
    polling.calls = [];
});

const SECTIONS = {
    doctor: ['next', 'kpis', 'agenda', 'pending', 'patients', 'shortcuts', 'stock'],
    secretary: ['kpis', 'agenda', 'confirmations', 'waitlist', 'birthdays', 'patients', 'shortcuts', 'stock'],
    admin: ['kpis', 'trends', 'agenda', 'shortcuts', 'patients', 'stock'],
    financial: ['finance', 'kpis', 'trends', 'shortcuts', 'stock'],
    user: ['kpis', 'shortcuts', 'stock'],
};

const RECEPTION = {
    waiting_room: { count: 0, in_care: 0, items: [] },
    days: {
        today: {
            date: '2026-10-06',
            total: 0,
            confirmed: 0,
            unconfirmed: 0,
            whatsapp: {},
            shifts: [],
            to_call: [],
            to_call_total: 0,
        },
        tomorrow: {
            date: '2026-10-07',
            total: 0,
            confirmed: 0,
            unconfirmed: 0,
            whatsapp: {},
            shifts: [],
            to_call: [],
            to_call_total: 0,
        },
    },
};

function mountDashboard(profile, props = {}) {
    return mount(Dashboard, {
        props: { profile, sections: SECTIONS[profile], stats: {}, access: ALL, t: T, ...props },
        global: { mocks: { route: globalThis.route } },
    });
}

const RECENT_PATIENT = {
    id: 'p1',
    name: 'Maria',
    initial: 'M',
    color: '#123456',
    phone: '—',
    code: 'PAC-1',
    url: '/p/1',
};

const sectionOrder = (w) => w.findAll('[data-section]').map((s) => s.attributes('data-section'));
const menuKeys = (w) =>
    w.findAll('[data-tour="dashboard-customize"] .order-menu li').map((li) => li.attributes('data-key'));

describe('Dashboard por perfil (posto de trabalho)', () => {
    it('médico: próximo paciente, indicadores, agenda com "Meu dia" e pendências ao lado, pacientes, atalhos', () => {
        const w = mountDashboard('doctor', {
            stats: { doctor_id: 'd1' },
            insights: { period: INSIGHTS_ADMIN.period, finance: false, month: { current: {}, previous: {} } },
            unsignedRecords: { count: 0, items: [], days: 30 },
            access: { ...ALL, doctors: false, financial: false },
        });

        expect(sectionOrder(w)).toEqual(['next', 'kpis', 'agenda', 'pending', 'patients', 'shortcuts']);
        // Pendências logo depois da agenda: na coluna lateral, junto de "Meu dia".
        expect(w.find('.db-side [data-section="pending"]').exists()).toBe(true);
        expect(menuKeys(w)).toEqual(['next', 'kpis', 'agenda', 'pending', 'patients', 'shortcuts']);
        expect(w.find('[data-test="dashboard-role"]').text()).toBe('My practice');
        expect(w.text()).toContain('My schedule today');
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('médico que moveu as pendências: elas saem da coluna lateral e seguem a ordem escolhida', () => {
        prefs.values.dashboard_widget_order = ['next', 'kpis', 'agenda', 'patients', 'pending', 'shortcuts', 'stock'];
        const w = mountDashboard('doctor', {
            stats: { doctor_id: 'd1' },
            unsignedRecords: { count: 0, items: [], days: 30 },
            recentPatients: [RECENT_PATIENT],
        });

        expect(w.find('.db-side [data-section="pending"]').exists()).toBe(false);
        expect(sectionOrder(w)).toEqual(['next', 'kpis', 'agenda', 'patients', 'pending', 'shortcuts']);
        // Pacientes e pendências (compactas vizinhas) dividem a mesma linha.
        expect(w.find('.db-compact-row[data-count="2"]').exists()).toBe(true);
    });

    it('recepção: sala de espera ao lado da agenda, confirmações, lista de espera e aniversariantes lado a lado', () => {
        const w = mountDashboard('secretary', {
            reception: RECEPTION,
            waitlist: { count: 0, items: [] },
            birthdays: { count: 0, items: [] },
            access: { ...ALL, financial: false },
        });

        expect(sectionOrder(w)).toEqual([
            'kpis',
            'agenda',
            'confirmations',
            'waitlist',
            'birthdays',
            'patients',
            'shortcuts',
        ]);
        expect(w.find('[data-tour="dashboard-waiting-room"]').exists()).toBe(true);
        expect(w.find('[data-tour="dashboard-next-patient"]').exists()).toBe(false);
        // Lista de espera, aniversariantes e pacientes vazios: uma faixa só, sem cards na grade.
        expect(w.findAll('.db-empty-strip [data-section]').map((n) => n.attributes('data-section'))).toEqual([
            'waitlist',
            'birthdays',
            'patients',
        ]);
        expect(w.find('.db-compact-row').exists()).toBe(false);
        expect(w.find('[data-test="dashboard-role"]').text()).toBe('Front desk');
    });

    it('compactas: as vazias viram faixa e as com conteúdo dividem a linha (mesma altura)', () => {
        const withData = mountDashboard('secretary', {
            reception: RECEPTION,
            waitlist: { count: 1, items: [{ id: 'w1', name: 'Maria', doctor: 'Dra. Ana', days: 2 }] },
            birthdays: { count: 1, items: [{ id: 'b1', name: 'João', age: 40 }] },
            recentPatients: [RECENT_PATIENT],
            access: { ...ALL, financial: false },
        });
        expect(withData.find('.db-empty-strip').exists()).toBe(false);
        expect(withData.find('.db-compact-row[data-count="3"]').exists()).toBe(true);

        const mixed = mountDashboard('secretary', {
            reception: RECEPTION,
            waitlist: { count: 0, items: [], url: '/_routes/panel.schedules.index' },
            birthdays: { count: 0, items: [] },
            recentPatients: [RECENT_PATIENT],
            access: { ...ALL, financial: false },
        });
        const strip = mixed.find('.db-empty-strip');
        expect(strip.attributes('aria-label')).toBe('Sections with nothing right now');
        expect(strip.text()).toContain('The waiting list is empty.');
        expect(strip.text()).toContain('No patient has a birthday today.');
        // Atalho da lista de espera continua na faixa (com acesso à agenda).
        expect(strip.find('[data-section="waitlist"] a').exists()).toBe(true);
        // A âncora do tour segue existindo na faixa.
        expect(strip.find('[data-tour="dashboard-waitlist"]').exists()).toBe(true);
        expect(mixed.find('.db-compact-row[data-count="1"] [data-section="patients"]').exists()).toBe(true);
    });

    it('administração: indicadores do mês, tendências, agenda com atendimentos por médico', () => {
        const w = mountDashboard('admin', {
            insights: { ...INSIGHTS_ADMIN, daily: { days: [] }, trend: { series: [] } },
            doctorsToday: { items: [], others: 0 },
        });

        expect(sectionOrder(w)).toEqual(['kpis', 'trends', 'agenda', 'shortcuts', 'patients']);
        expect(w.find('[data-tour="dashboard-doctors-today"]').exists()).toBe(true);
        expect(w.find('[data-tour="dashboard-waiting-room"]').exists()).toBe(false);
        expect(w.find('[data-test="dashboard-role"]').text()).toBe('Management');
    });

    it('financeiro: caixa/a receber/glosas, mês e tendências — sem agenda nem pacientes', () => {
        const w = mountDashboard('financial', {
            cashToday: { received: 0, paid: 0, realized_balance: 0 },
            insights: INSIGHTS_FINANCIAL,
        });

        expect(sectionOrder(w)).toEqual(['finance', 'kpis', 'trends', 'shortcuts']);
        expect(w.find('[data-tour="dashboard-schedule-today"]').exists()).toBe(false);
        expect(w.find('[data-tour="dashboard-recent-patients"]').exists()).toBe(false);
        expect(w.find('[data-test="dashboard-role"]').text()).toBe('Finance');
    });

    it('usuário: só indicadores e atalhos', () => {
        const w = mountDashboard('user', { access: { ...NONE, eye_images: true } });

        expect(sectionOrder(w)).toEqual(['kpis', 'shortcuts']);
        expect(menuKeys(w)).toEqual(['kpis', 'shortcuts']);
        expect(w.findAll('[data-action]')).toHaveLength(0);
    });

    it('médico sem cadastro de médico: aviso traduzido (role=alert), sem próximo paciente nem link para a Agenda', () => {
        globalThis.route.mockClear();
        const w = mountDashboard('doctor', { doctorMissing: true, unsignedRecords: { count: 0, items: [], days: 30 } });

        const alert = w.find('[role="alert"]');
        expect(alert.text()).toContain('Incomplete doctor registration');
        expect(w.find('[data-tour="dashboard-next-patient"]').exists()).toBe(false);
        expect(globalThis.route).not.toHaveBeenCalledWith('panel.schedules.index');
    });
});

describe('Personalizar: ordem e mostrar/ocultar por perfil', () => {
    it('seção oculta não aparece, mas segue no menu marcada; o olho mostra de novo e grava a preferência', async () => {
        prefs.values.dashboard_hidden_sections = ['patients', 'finance'];
        const w = mountDashboard('admin', { insights: INSIGHTS_ADMIN });

        expect(sectionOrder(w)).not.toContain('patients');
        const item = w.find('.order-menu li[data-key="patients"]');
        expect(item.attributes('data-hidden')).toBe('1');

        await item.trigger('click');
        expect(sectionOrder(w)).toContain('patients');
        expect(prefs.saved.at(-1)).toEqual(['dashboard_hidden_sections', []]);
    });

    it('alertas de estoque só aparecem (e entram no menu) quando há alerta', () => {
        const without = mountDashboard('admin', { insights: INSIGHTS_ADMIN });
        expect(menuKeys(without)).not.toContain('stock');

        const withAlerts = mountDashboard('admin', {
            insights: INSIGHTS_ADMIN,
            stockAlerts: {
                below_minimum_count: 1,
                expiring_lots_count: 0,
                list_url: '/s',
                products_url: '/s?low=1',
                expiring_url: '/s?e=1',
            },
        });
        expect(menuKeys(withAlerts)).toContain('stock');
        expect(withAlerts.find('[data-tour="dashboard-stock-alerts"]').exists()).toBe(true);
    });
});

describe('Atualização', () => {
    it('polling só da operação de hoje; "Atualizar" pede também os números de gestão (sem cache)', async () => {
        const w = mountDashboard('admin', { insights: INSIGHTS_ADMIN });
        const call = polling.calls.at(-1);

        expect(call.interval).toBe(30_000);
        expect(call.only).toEqual(
            expect.arrayContaining([
                'stats',
                'scheduleToday',
                'reception',
                'waitlist',
                'birthdays',
                'doctorsToday',
                'cashToday',
            ]),
        );
        expect(call.only).not.toContain('insights');
        expect(call.options).toEqual({ manual: ['insights'], headers: { 'X-Dashboard-Refresh': '1' } });

        await w.find('[data-test="dashboard-refresh"]').trigger('click');
        expect(polling.refresh).toHaveBeenCalledWith({ full: true });
    });
});
