import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AiUsageTrendChart from '@/Pages/Panel/Manager/AiUsage/AiUsageTrendChart.vue';

/**
 * Gráfico de custo × execuções: resumo acessível no aria-label, tabela "Ver
 * dados" com os mesmos números e aviso quando não há uso no período.
 */
const charts = vi.hoisted(() => ({ instances: [] }));
vi.mock('chart.js', () => {
    class Chart {
        static register = vi.fn();

        constructor(canvas, config) {
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
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { locale: 'pt_BR' } }) }));

const t = new Proxy(
    { aria: ':from a :to: :cost em :runs execuções' },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const points = [
    { key: '2026-10-01', runs: 0, failed: 0, cost_brl: 0 },
    { key: '2026-10-02', runs: 1, failed: 0, cost_brl: 2 },
    { key: '2026-10-03', runs: 2, failed: 1, cost_brl: 0.25 },
];

describe('Manager → Uso de IA: gráfico', () => {
    it('resumo no aria-label, barras de custo e linha de execuções', () => {
        const wrapper = mount(AiUsageTrendChart, { props: { points, t } });
        const config = charts.instances.at(-1).config;

        expect(wrapper.find('canvas').attributes('aria-label').replace(/ /g, ' ')).toBe(
            '01/10 a 03/10: R$ 2,25 em 3 execuções',
        );
        expect(config.data.datasets.map((d) => d.key)).toEqual(['cost', 'runs']);
        expect(config.data.datasets[0].data).toEqual([0, 2, 0.25]);
        expect(config.data.datasets[1].yAxisID).toBe('y1');
    });

    it('"Ver dados" mostra a tabela com falhas por período', async () => {
        const wrapper = mount(AiUsageTrendChart, { props: { points, t } });

        await wrapper.find('[data-test="usage-toggle-data"]').trigger('click');
        const rows = wrapper.findAll('[data-test="usage-row"]');

        expect(rows).toHaveLength(3);
        expect(rows[2].text()).toContain('03/10');
        expect(rows[2].text()).toContain('1');
    });

    it('rótulo mensal no período longo e aviso sem uso', () => {
        const wrapper = mount(AiUsageTrendChart, {
            props: { points: [{ key: '2026-09', runs: 0, failed: 0, cost_brl: 0 }], granularity: 'month', t },
        });

        expect(charts.instances.at(-1).config.data.labels[0]).toMatch(/set/i);
        expect(wrapper.find('[data-test="usage-chart-empty"]').exists()).toBe(true);
    });
});
