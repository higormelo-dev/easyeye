import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TrendBarChart from '@/Pages/Panel/Financial/Bi/TrendBarChart.vue';
import DonutChart from '@/Pages/Panel/Financial/Bi/DonutChart.vue';

/**
 * Gráficos do BI no padrão do MrrTrendChart: cores lidas das variáveis CSS do
 * tema (nada fixo), refeitos ao trocar claro/escuro, sem animação com
 * "reduzir movimento" e chart.destroy() ao desmontar.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
}));

const charts = vi.hoisted(() => ({ instances: [] }));

vi.mock('chart.js', () => {
    class Chart {
        static register = vi.fn();

        constructor(canvas, config) {
            this.canvas = canvas;
            this.config = config;
            this.destroy = vi.fn();
            charts.instances.push(this);
        }
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
        DoughnutController: part,
        ArcElement: part,
    };
});

const t = {
    trend_chart_aria: ':from–:to :income :expense :balance',
    col_month: 'Month',
    col_income: 'Revenue',
    col_expense: 'Expenses',
    col_balance: 'Balance',
    monthly_trend: 'Trend',
    see_data: 'View data',
    hide_data: 'Hide data',
    schedule_chart_aria: ':total: :items',
    schedule_mix: 'Mix',
    col_status: 'Status',
    col_quantity: 'Count',
    col_share: 'Share',
    col_total: 'Total',
};

const rows = [
    { key: '2026-08', label: 'Aug', income: 100, expense: 40, balance: 60 },
    { key: '2026-09', label: 'Sep', income: 300, expense: 420.5, balance: -120.5 },
];

const slices = [
    { key: 'attended', label: 'Attended', value: 3, tone: 'success' },
    { key: 'no_show', label: 'No-show', value: 1, tone: 'danger' },
];

const THEME = {
    light: {
        '--bs-success': '#198754',
        '--bs-danger': '#dc3545',
        '--bs-primary': '#0d6efd',
        '--bs-secondary-color': '#6c757d',
        '--bs-card-bg': '#ffffff',
    },
    dark: {
        '--bs-success': '#75b798',
        '--bs-danger': '#ea868f',
        '--bs-primary': '#6ea8fe',
        '--bs-secondary-color': '#a7acb1',
        '--bs-card-bg': '#212529',
    },
};

let wrapper;
let theme = 'light';
let reducedMotion = false;
const realGetComputedStyle = window.getComputedStyle;

beforeEach(() => {
    charts.instances.length = 0;
    theme = 'light';
    reducedMotion = false;
    document.documentElement.removeAttribute('data-bs-theme');

    // Variáveis do tema atual (o happy-dom não resolve o CSS do Bootstrap).
    vi.spyOn(window, 'getComputedStyle').mockImplementation(() => ({
        getPropertyValue: (name) => THEME[theme][name] ?? '',
    }));
    window.matchMedia = vi.fn(() => ({
        matches: reducedMotion,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    window.getComputedStyle = realGetComputedStyle;
    vi.restoreAllMocks();
});

describe('TrendBarChart', () => {
    it('usa as cores do tema (--bs-success/--bs-danger/--bs-primary), não cores fixas', () => {
        wrapper = mount(TrendBarChart, { props: { rows, t }, attachTo: document.body });
        const [chart] = charts.instances;
        const [income, expense, balance] = chart.config.data.datasets;

        expect(income.backgroundColor).toBe('#198754');
        expect(expense.backgroundColor).toBe('#dc3545');
        expect(balance.borderColor).toBe('#0d6efd');
        expect(chart.config.options.scales.y.ticks.color).toBe('#6c757d');
        expect(chart.config.options.plugins.tooltip.backgroundColor).toBe('#ffffff');
        expect(chart.config.data.labels).toEqual(['Aug', 'Sep']);
    });

    it('troca de tema (data-bs-theme) refaz o gráfico com as cores novas e destrói o anterior', async () => {
        wrapper = mount(TrendBarChart, { props: { rows, t }, attachTo: document.body });
        const first = charts.instances[0];

        theme = 'dark';
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        await flushPromises();

        expect(first.destroy).toHaveBeenCalled();
        expect(charts.instances).toHaveLength(2);
        expect(charts.instances[1].config.data.datasets[0].backgroundColor).toBe('#75b798');
    });

    it('formata o tooltip no idioma do usuário (saldo com sinal) e respeita "reduzir movimento"', () => {
        reducedMotion = true;
        wrapper = mount(TrendBarChart, { props: { rows, t }, attachTo: document.body });
        const { options, data } = charts.instances[0].config;
        const label = (datasetIndex, y) =>
            options.plugins.tooltip.callbacks.label({ dataset: data.datasets[datasetIndex], parsed: { y } });

        expect(options.animation).toBe(false);
        expect(label(0, 300).replace(/\u00a0/g, ' ')).toBe(' Revenue: R$ 300,00');
        expect(label(2, -120.5).replace(/\u00a0/g, ' ')).toBe(' Balance: -R$ 120,50');
    });

    it('desmontar destrói o gráfico e para de observar o tema', async () => {
        wrapper = mount(TrendBarChart, { props: { rows, t }, attachTo: document.body });
        const [chart] = charts.instances;

        wrapper.unmount();
        wrapper = null;
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        await flushPromises();

        expect(chart.destroy).toHaveBeenCalled();
        expect(charts.instances).toHaveLength(1);
    });
});

describe('DonutChart', () => {
    it('cada fatia usa a cor do tom (--bs-<tone>) e a legenda HTML usa a mesma classe bg-<tone>', () => {
        wrapper = mount(DonutChart, { props: { slices, t, totalLabel: 'appointments' }, attachTo: document.body });
        const [chart] = charts.instances;

        expect(chart.config.data.datasets[0].backgroundColor).toEqual(['#198754', '#dc3545']);
        expect(chart.config.data.datasets[0].borderColor).toBe('#ffffff');
        expect(wrapper.find('[data-slice="attended"] .bi-swatch').classes()).toContain('bg-success');
        expect(wrapper.find('[data-slice="no_show"]').text()).toContain('1');
        expect(wrapper.find('.bi-donut__center').text()).toContain('4');
        expect(wrapper.find('.bi-donut__center').attributes('aria-hidden')).toBe('true');
    });

    it('aria-label resume todas as fatias e "Ver dados" mostra quantidade e participação', async () => {
        wrapper = mount(DonutChart, { props: { slices, t }, attachTo: document.body });

        expect(wrapper.find('[data-test="donut-chart"]').attributes('aria-label')).toBe(
            '4: Attended: 3 (75%); No-show: 1 (25%)',
        );

        const toggle = wrapper.find('[data-test="donut-toggle-data"]');
        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('[data-test="donut-data"]').isVisible()).toBe(true);
        expect(
            wrapper
                .findAll('[data-test="donut-row"]')[1]
                .findAll('th, td')
                .map((c) => c.text()),
        ).toEqual(['No-show', '1', '25%']);
        expect(wrapper.find('tfoot').text()).toContain('4');
    });

    it('troca de tema refaz a rosca e desmontar destrói', async () => {
        wrapper = mount(DonutChart, { props: { slices, t }, attachTo: document.body });
        const first = charts.instances[0];

        theme = 'dark';
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        await flushPromises();

        expect(first.destroy).toHaveBeenCalled();
        const second = charts.instances[1];
        expect(second.config.data.datasets[0].backgroundColor).toEqual(['#75b798', '#ea868f']);

        wrapper.unmount();
        wrapper = null;
        expect(second.destroy).toHaveBeenCalled();
    });
});
