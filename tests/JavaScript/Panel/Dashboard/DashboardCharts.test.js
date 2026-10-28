import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import DailyScheduleChart from '@/Pages/Panel/Dashboard/DailyScheduleChart.vue';
import TrendsSection from '@/Pages/Panel/Dashboard/TrendsSection.vue';
import { T, INSIGHTS_ADMIN, INSIGHTS_FINANCIAL } from './fixtures.js';

/**
 * Tendências do Dashboard (gestão): gráficos montam com Chart.js (mock, como
 * nos testes do BI), têm resumo textual (aria-label + "Ver dados") e cada
 * perfil vê os dele — administração: consultas × faltas + receita × despesa
 * (com financeiro); financeiro: receita × despesa + faturado × recebido.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
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
    };
});

beforeEach(() => {
    charts.instances.length = 0;
});

const DAYS = [
    { date: '2026-10-04', attended: 6, noshow: 1, cancelled: 0, total: 7 },
    { date: '2026-10-05', attended: 8, noshow: 1, cancelled: 2, total: 11 },
    { date: '2026-10-06', attended: 2, noshow: 0, cancelled: 0, total: 9 },
];

describe('DailyScheduleChart', () => {
    it('barras empilhadas atendidas × faltas, com resumo para leitor de tela e tabela "Ver dados"', async () => {
        const w = mount(DailyScheduleChart, { props: { days: DAYS, t: T }, attachTo: document.body });

        expect(charts.instances).toHaveLength(1);
        const { config } = charts.instances[0];
        expect(config.type).toBe('bar');
        expect(config.options.scales.x.stacked).toBe(true);
        expect(config.data.datasets.map((d) => [d.key, d.label, d.data])).toEqual([
            ['attended', 'Attended', [6, 8, 2]],
            ['noshow', 'No-shows', [1, 1, 0]],
        ]);

        const canvas = w.find('[data-test="daily-chart"]');
        expect(canvas.attributes('role')).toBe('img');
        expect(canvas.attributes('aria-label')).toBe(
            'Last 3 days: 16 appointments attended and 2 no-shows (no-show rate 11.1%).',
        );
        expect(w.text()).toContain('No-show rate: 11.1%');

        const table = w.find('[data-test="daily-data"]');
        expect(table.isVisible()).toBe(false);
        await w.find('[data-test="daily-toggle-data"]').trigger('click');
        expect(table.isVisible()).toBe(true);
        expect(table.findAll('tbody tr')).toHaveLength(3);

        w.unmount();
        expect(charts.instances[0].destroy).toHaveBeenCalled();
    });
});

describe('TrendsSection', () => {
    it('administração com financeiro: consultas × faltas (30 dias) e receita × despesa (6 meses, o gráfico do BI)', () => {
        const insights = { ...INSIGHTS_ADMIN, daily: { days: DAYS }, trend: INSIGHTS_FINANCIAL.trend };
        const w = mount(TrendsSection, {
            props: { profile: 'admin', insights, canOpenBi: true, t: T },
            global: { mocks: { route: globalThis.route } },
        });

        expect(charts.instances).toHaveLength(2);
        expect(w.find('[data-test="daily-chart"]').exists()).toBe(true);
        expect(w.find('[data-test="trend-chart"]').exists()).toBe(true);
        expect(w.text()).toContain('Appointments × no-shows');
        expect(w.text()).toContain('Revenue × expenses · 6 months');
        // Rótulos dos meses no idioma do usuário; saldo calculado (receita − despesa).
        const trend = charts.instances[1].config;
        expect(trend.data.labels).toEqual(['Sep 26', 'Oct 26']);
        expect(trend.data.datasets.find((d) => d.key === 'balance').data).toEqual([10500, 3740]);
        expect(w.find('a.db-link').attributes('href')).toBe('/_routes/panel.financial.bi.index');
        expect(w.attributes('data-tour')).toBe('dashboard-trends');
    });

    it('administração sem financeiro: só a série de consultas, na largura toda; sem link do BI', () => {
        const insights = { ...INSIGHTS_ADMIN, finance: false, daily: { days: DAYS }, trend: null };
        const w = mount(TrendsSection, { props: { profile: 'admin', insights, canOpenBi: false, t: T } });

        expect(charts.instances).toHaveLength(1);
        expect(w.find('.db-split--single').exists()).toBe(true);
        expect(w.find('a.db-link').exists()).toBe(false);
    });

    it('financeiro: receita × despesa e faturado × recebido por convênio; recarregando, esqueleto', () => {
        const w = mount(TrendsSection, {
            props: { profile: 'financial', insights: INSIGHTS_FINANCIAL, canOpenBi: true, t: T },
            global: { mocks: { route: globalThis.route } },
        });

        expect(w.find('[data-test="daily-chart"]').exists()).toBe(false);
        expect(w.find('[data-test="trend-chart"]').exists()).toBe(true);
        expect(w.findAll('.db-covenants__item').map((i) => i.find('.db-covenants__name').text())).toEqual([
            'UNIMED',
            'BRADESCO SAÚDE',
        ]);

        const loading = mount(TrendsSection, {
            props: { profile: 'financial', insights: INSIGHTS_FINANCIAL, loading: true, t: T },
        });
        expect(loading.findAll('.db-chart-skeleton')).toHaveLength(2);
    });
});
