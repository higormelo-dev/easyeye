import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import BiIndex from '@/Pages/Panel/Financial/Bi/Index.vue';

/**
 * Dashboard gerencial (fase 3): lê o contrato real do ClinicBiService
 * (kpis.income/expense…, trend[].period/month/income/expense), mostra os KPIs
 * em 3 faixas de KpiCard com a definição na dica, período pelo PeriodFilter
 * (URL from/to), atalhos com o período, "Atualizar", gráficos acessíveis com
 * "Ver dados" e erro visível.
 */

// Link com href renderizado (o mock global não repassa o href) e usePage com locale.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR', flash: {}, errors: {} } }),
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));

const charts = vi.hoisted(() => ({ instances: [] }));

vi.mock('chart.js', () => {
    class Chart {
        static register = vi.fn();

        constructor(canvas, config) {
            this.canvas  = canvas;
            this.config  = config;
            this.destroy = vi.fn();
            charts.instances.push(this);
        }
    }
    const part = {};

    return {
        Chart,
        BarController: part, BarElement: part, LineController: part, LineElement: part, PointElement: part,
        CategoryScale: part, LinearScale: part, Legend: part, Tooltip: part, DoughnutController: part, ArcElement: part,
    };
});

const t = {
    title: 'Management dashboard', refresh: 'Refresh', refresh_title: 'Recalculate now (:minutes min)',
    updated_at: 'Updated at :time', loading: 'Loading…', load_error: 'Could not refresh.',
    section_cash: 'Cash', section_cash_hint: 'Paid only.', section_billing: 'Insurers', section_billing_hint: 'Claims.',
    section_schedule: 'Schedule', section_schedule_hint: 'Appointments.',
    income: 'Revenue', income_sub: 'Received', income_hint: 'Sum of paid income entries.',
    expense: 'Expenses', expense_sub: 'Paid', expense_hint: 'Sum of paid expenses.',
    balance: 'Balance', balance_hint: 'Revenue minus expenses.', balance_positive: 'Positive', balance_negative: 'Negative', balance_zero: 'Break-even',
    billed: 'Billed', billed_sub: 'Claims', billed_hint: 'Claims excluding drafts.',
    received: 'Received', received_sub: 'Paid claims', received_hint: 'Paid amount.',
    glosa: 'Denied', glosa_sub: 'Denied by insurers', glosa_hint: 'Denied amount.',
    receipt_rate: 'Receipt rate', receipt_rate_sub: 'Received ÷ billed', receipt_rate_hint: 'Received divided by billed.',
    avg_ticket: 'Average ticket', avg_ticket_sub: 'Per paid claim', avg_ticket_hint: 'Received divided by paid claims.',
    open_billing: 'Open billing', see_glosas: 'View denials', see_report: 'Report by insurer', see_cash_flow: 'Cash flow report',
    attended: 'Attended', attended_sub: 'of :count appointment(s)', attended_hint: 'Status Attended.',
    attendance_rate: 'Attendance', attendance_rate_sub: ':count no-show(s)', attendance_rate_hint: 'Attended ÷ (attended + no-shows).',
    occupancy_rate: 'Occupancy', occupancy_rate_sub: ':count cancelled', occupancy_rate_hint: 'Attended ÷ non-cancelled.',
    new_patients: 'New patients', new_patients_sub: 'Registered', new_patients_hint: 'Registered in the period.',
    billing_by_covenant: 'Billing by insurer', billing_by_covenant_hint: 'Top 6.', no_claims: 'No claims.', no_covenant: 'No insurer',
    monthly_trend: 'Monthly trend', monthly_trend_hint: 'Last 6 months.', no_trend_data: 'No paid entries.',
    trend_chart_aria: 'Bars from :from to :to. Revenue :income, expenses :expense, balance :balance.',
    col_month: 'Month', col_income: 'Revenue', col_expense: 'Expenses', col_balance: 'Balance',
    see_data: 'View data', hide_data: 'Hide data',
    schedule_mix: 'Schedule mix', schedule_mix_hint: 'Status.', schedule_chart_aria: 'Donut of :total: :items.',
    schedule_total: 'appointment(s)', no_schedules: 'No appointments.',
    col_status: 'Status', col_quantity: 'Count', col_share: 'Share', col_total: 'Total',
    chart_attended: 'Attended', chart_no_show: 'No-show', chart_cancelled: 'Cancelled', chart_pending: 'Pending',
    shared: {
        period: {
            label: 'Period', from: 'From', to: 'To', invalid_range: 'Start must be before end.', invalid_date: 'Invalid date.',
            presets: { today: 'Today', yesterday: 'Yesterday', last7: 'Last 7 days', month: 'This month', last_month: 'Last month', year: 'This year', custom: 'Custom' },
        },
    },
};

function kpis(overrides = {}) {
    return {
        income: 300, expense: 420.5, balance: -120.5,
        total_billed: 1000, total_paid: 800, total_glosa: 50, ticket_medio: 200, receipt_rate: 80,
        attended: 30, noshow: 5, cancelled: 2, total_schedules: 40, attendance_rate: 85.7, occupancy_rate: 78.9, new_patients: 4,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        breadcrumbs: [],
        entity: { id: 'e1', name: 'Clínica Olhos' },
        filters: { from: '2026-09-01', to: '2026-09-26' },
        today: '2026-09-26',
        summary: {
            kpis: kpis(),
            by_covenant_chart: [{ label: 'UNIMED', value: 600 }, { label: null, value: 300 }],
            schedule_chart: [],
            generated_at: '2026-09-26T14:05:00-03:00',
        },
        trend: [
            { period: '08/2026', month: '2026-08', income: 100, expense: 40 },
            { period: '09/2026', month: '2026-09', income: 300, expense: 420.5 },
        ],
        generated_at: '2026-09-26T14:05:00-03:00',
        cache_minutes: 30,
        routes: {
            index: '/bi',
            billing: '/billing?from=2026-09-01&to=2026-09-26',
            glosas: '/glosas?from=2026-09-01&to=2026-09-26',
            cash_flow: '/reports/cash-flow?from=2026-09-01&to=2026-09-26',
            covenants: '/reports/covenants?from=2026-09-01&to=2026-09-26',
        },
        t,
        ...overrides,
    };
}

let wrapper;

beforeEach(() => {
    vi.mocked(router.get).mockReset();
    charts.instances.length = 0;
});
afterEach(() => wrapper?.unmount());

function mountPage(overrides = {}) {
    wrapper = mount(BiIndex, { props: baseProps(overrides), attachTo: document.body });

    return wrapper;
}

// Intl usa espaço não separável em "R$ 1.234,50" — normaliza para comparar.
const clean = (value) => String(value).replace(/\u00a0|\u202f/g, ' ');
const text = (el) => clean(el.text());
const brl = (v) => clean(new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const signed = (v) => clean(new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', signDisplay: 'exceptZero' }).format(v));
const kpi = (w, key) => w.find(`[data-kpi="${key}"]`);
const kpiValue = (w, key) => text(w.find(`[data-test="kpi-${key}"]`));

describe('Financial/Bi/Index — KPIs', () => {
    it('organiza os KpiCards em 3 faixas com os dados que o serviço já calcula', () => {
        const w = mountPage();

        expect(w.findAll('[data-section]').map((s) => s.attributes('data-section'))).toEqual(['cash', 'billing', 'schedule']);
        expect(w.find('[data-section="cash"]').findAll('[data-kpi]').map((k) => k.attributes('data-kpi')))
            .toEqual(['income', 'expense', 'balance']);
        expect(w.find('[data-section="billing"]').findAll('[data-kpi]').map((k) => k.attributes('data-kpi')))
            .toEqual(['total_billed', 'total_paid', 'total_glosa', 'receipt_rate', 'ticket_medio']);
        expect(w.find('[data-section="schedule"]').findAll('[data-kpi]').map((k) => k.attributes('data-kpi')))
            .toEqual(['attended', 'attendance_rate', 'occupancy_rate', 'new_patients']);

        expect(kpiValue(w, 'income')).toBe(brl(300));
        expect(kpiValue(w, 'expense')).toBe(brl(420.5));
        expect(kpiValue(w, 'total_billed')).toBe(brl(1000));
        expect(kpiValue(w, 'total_paid')).toBe(brl(800));
        expect(kpiValue(w, 'receipt_rate')).toBe('80,0%');
        expect(kpiValue(w, 'ticket_medio')).toBe(brl(200));
        expect(kpiValue(w, 'occupancy_rate')).toBe('78,9%');
        expect(kpiValue(w, 'new_patients')).toBe('4');
    });

    it('saldo com sinal (signedMoney), texto e tom; valor sempre em text-body', () => {
        const w = mountPage();

        expect(kpiValue(w, 'balance')).toBe(signed(-120.5));
        expect(kpi(w, 'balance').text()).toContain('Negative');
        expect(kpi(w, 'balance').find('.kpi-card').classes()).toContain('border-danger');
        expect(w.find('[data-test="kpi-income"]').classes()).toContain('text-body');
        wrapper.unmount();

        const zero = mountPage({ summary: { ...baseProps().summary, kpis: kpis({ balance: 0 }) } });
        expect(kpi(zero, 'balance').text()).toContain('Break-even');
        expect(kpi(zero, 'balance').find('.kpi-card').classes()).toContain('border-secondary');
    });

    it('cada KPI explica a definição na dica (title + texto para leitor de tela)', () => {
        const w = mountPage();
        const card = kpi(w, 'attendance_rate').find('.kpi-card');

        expect(card.attributes('title')).toBe('Attended ÷ (attended + no-shows).');
        expect(card.find('.visually-hidden').text()).toBe('Attended ÷ (attended + no-shows).');
        expect(kpi(w, 'ticket_medio').find('.kpi-card').attributes('title')).toBe('Received divided by paid claims.');
        expect(kpi(w, 'income').find('.kpi-card').attributes('title')).toBe('Sum of paid income entries.');
    });

    it('agenda mostra atendidos de N agendamentos, faltas no comparecimento e cancelados na ocupação', () => {
        const w = mountPage();

        expect(kpi(w, 'attended').text()).toContain('of 40 appointment(s)');
        expect(kpi(w, 'attendance_rate').text()).toContain('5 no-show(s)');
        expect(kpi(w, 'occupancy_rate').text()).toContain('2 cancelled');
    });

    it('card de glosa fica vermelho só quando há valor glosado', () => {
        expect(kpi(mountPage(), 'total_glosa').find('.kpi-card').classes()).toContain('border-danger');
        wrapper.unmount();

        const zero = mountPage({ summary: { ...baseProps().summary, kpis: kpis({ total_glosa: 0 }) } });
        expect(kpi(zero, 'total_glosa').find('.kpi-card').classes()).toContain('border-secondary');
    });

    it('atalhos "Abrir faturamento" e "Ver glosas" levam o período (URL vinda do servidor)', () => {
        const w = mountPage();

        expect(w.find('[data-action="billing"]').attributes('href')).toBe('/billing?from=2026-09-01&to=2026-09-26');
        expect(w.find('[data-action="billing"]').text()).toBe('Open billing');
        expect(w.find('[data-action="glosas"]').attributes('href')).toBe('/glosas?from=2026-09-01&to=2026-09-26');
        expect(w.find('[data-action="covenants"]').attributes('href')).toBe('/reports/covenants?from=2026-09-01&to=2026-09-26');
        expect(w.find('[data-action="cash_flow"]').attributes('href')).toBe('/reports/cash-flow?from=2026-09-01&to=2026-09-26');
    });
});

describe('Financial/Bi/Index — período', () => {
    it('atalho do PeriodFilter aplica na URL (from/to) calculado a partir do "hoje" do servidor', async () => {
        const w = mountPage();

        expect(w.find('[data-test="period-preset"]').element.value).toBe('month');

        await w.find('[data-test="period-preset"]').setValue('last_month');

        expect(router.get).toHaveBeenCalledWith(
            '/bi',
            { from: '2026-08-01', to: '2026-08-31' },
            expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }),
        );
    });

    it('data digitada válida aplica; período invertido mostra o aviso e não recarrega', async () => {
        const w = mountPage();

        await w.find('[data-test="period-from"]').setValue('2026-08-15');
        expect(router.get).toHaveBeenCalledWith('/bi', { from: '2026-08-15', to: '2026-09-26' }, expect.any(Object));

        vi.mocked(router.get).mockClear();
        await w.find('[data-test="period-from"]').setValue('2026-10-01');

        expect(router.get).not.toHaveBeenCalled();
        expect(w.find('[data-test="period-error"]').text()).toContain('Start must be before end.');
        expect(w.find('[data-test="period-from"]').attributes('aria-invalid')).toBe('true');
    });

    it('rótulos do período vêm de t.shared.period (sem texto fixo)', () => {
        const w = mountPage();
        const options = w.findAll('[data-test="period-preset"] option').map((o) => o.text());

        expect(options).toContain('Last month');
        expect(w.find('.period-filter').attributes('aria-label')).toBe('Period');
    });

    it('mostra "Atualizado às" e o Atualizar pede refresh do período aplicado', async () => {
        const w = mountPage();
        const time = new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' }).format(new Date('2026-09-26T14:05:00-03:00'));

        expect(w.find('[data-test="updated-at"]').text()).toBe(`Updated at ${time}`);
        expect(w.find('[data-test="refresh"]').attributes('title')).toBe('Recalculate now (30 min)');

        await w.find('[data-test="refresh"]').trigger('click');

        expect(router.get).toHaveBeenCalledWith('/bi', { from: '2026-09-01', to: '2026-09-26', refresh: 1 }, expect.any(Object));
    });

    it('carregando: botões/filtro desabilitados e KPIs em espera; erro HTTP aparece e o filtro volta ao período exibido', async () => {
        let options;
        vi.mocked(router.get).mockImplementation((url, data, opts) => { options = opts; opts.onStart?.(); });

        const w = mountPage();
        await w.find('[data-test="period-preset"]').setValue('last_month');
        await nextTick();

        expect(w.find('[data-test="refresh"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="period-preset"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="loading-status"]').text()).toBe('Loading…');
        expect(w.find('[data-test="kpi-income"]').exists()).toBe(false); // placeholder do KpiCard
        expect(w.find('[aria-busy="true"]').exists()).toBe(true);

        expect(options.onHttpException({ status: 500 })).toBe(false); // sem modal de erro genérico
        options.onFinish();
        await nextTick();

        expect(w.find('[data-test="load-error"]').text()).toBe('Could not refresh.');
        expect(w.find('[data-test="period-from"]').element.value).toBe('2026-09-01');
        expect(w.find('[data-test="kpi-income"]').exists()).toBe(true);
    });
});

describe('Financial/Bi/Index — gráficos', () => {
    it('tendência: barras de receita × despesa + linha de saldo, com aria-label e "Ver dados"', async () => {
        const w = mountPage();
        const canvas = w.find('[data-test="trend-chart"]');
        const trend = charts.instances.find((c) => c.canvas === canvas.element);

        expect(canvas.attributes('role')).toBe('img');
        expect(clean(canvas.attributes('aria-label'))).toContain(`Revenue ${brl(400)}, expenses ${brl(460.5)}, balance ${signed(-60.5)}`);
        expect(trend.config.data.datasets.map((d) => [d.type, d.key])).toEqual([['bar', 'income'], ['bar', 'expense'], ['line', 'balance']]);
        expect(trend.config.data.datasets[2].data).toEqual([60, -120.5]);

        const toggle = w.find('[data-test="trend-toggle-data"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.attributes('aria-controls')).toBe(w.find('[data-test="trend-data"]').attributes('id'));
        expect(w.find('[data-test="trend-data"]').isVisible()).toBe(false);

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(toggle.text()).toBe('Hide data');
        expect(w.find('[data-test="trend-data"]').isVisible()).toBe(true);

        const rows = w.findAll('[data-test="trend-row"]');
        const monthLabel = new Intl.DateTimeFormat('pt-BR', { month: 'short', year: 'numeric' }).format(new Date(2026, 8, 1));
        expect(rows).toHaveLength(2);
        expect(rows[1].findAll('th, td').map(text)).toEqual([clean(monthLabel), brl(300), brl(420.5), signed(-120.5)]);
        expect(text(rows[0].findAll('td')[2])).toBe(signed(60));
    });

    it('tendência sem lançamentos mostra estado vazio em vez de gráfico de zeros', () => {
        const w = mountPage({ trend: [{ period: '09/2026', month: '2026-09', income: 0, expense: 0 }] });

        expect(w.find('[data-test="trend-empty"]').text()).toBe('No paid entries.');
        expect(w.find('[data-test="trend-chart"]').exists()).toBe(false);
    });

    it('mix da agenda: fatias atendidos/faltas/cancelados/pendentes com os mesmos números dos KPIs', async () => {
        const w = mountPage();
        const canvas = w.find('[data-test="donut-chart"]');
        const donut = charts.instances.find((c) => c.canvas === canvas.element);

        // pendentes = 40 − 30 − 5 − 2
        expect(donut.config.data.datasets[0].data).toEqual([30, 5, 2, 3]);
        expect(donut.config.data.labels).toEqual(['Attended', 'No-show', 'Cancelled', 'Pending']);
        expect(canvas.attributes('role')).toBe('img');
        expect(canvas.attributes('aria-label')).toBe('Donut of 40: Attended: 30 (75%); No-show: 5 (12,5%); Cancelled: 2 (5%); Pending: 3 (7,5%).');

        await w.find('[data-test="donut-toggle-data"]').trigger('click');
        const rows = w.findAll('[data-test="donut-row"]').map((row) => row.findAll('th, td').map(text));
        expect(rows[0]).toEqual(['Attended', '30', '75%']);
        expect(rows[3]).toEqual(['Pending', '3', '7,5%']);
    });

    it('agenda sem agendamentos mostra estado vazio', () => {
        const w = mountPage({ summary: { ...baseProps().summary, kpis: kpis({ attended: 0, noshow: 0, cancelled: 0, total_schedules: 0 }) } });

        expect(w.find('[data-test="schedule-empty"]').text()).toBe('No appointments.');
        expect(w.find('[data-test="donut-chart"]').exists()).toBe(false);
    });

    it('faturamento por convênio traduz o "sem convênio"', () => {
        const rows = mountPage().findAll('[data-test="covenant-row"]');

        expect(text(rows[0])).toContain('UNIMED');
        expect(text(rows[0])).toContain(brl(600));
        expect(text(rows[1])).toContain('No insurer');
    });

    it('desmontar a página destrói os gráficos (sem vazamento do Chart.js)', () => {
        const w = mountPage();
        const created = [...charts.instances];

        expect(created).toHaveLength(2);
        w.unmount();
        wrapper = null;

        created.forEach((chart) => expect(chart.destroy).toHaveBeenCalled());
    });
});
