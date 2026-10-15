import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Indicators from '@/Pages/Panel/Surgery/Indicators.vue';

/** Fase 4 / M5 — página de indicadores: KPIs, tabelas equivalentes aos gráficos, amostra pequena e CSV. */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { template: '<div><slot name="actions" /></div>' } }));

const group = () => new Proxy({}, { get: (_, key) => String(key) });
const t = {
    indicators: {
        title: 'Indicadores',
        filters: group(),
        authorization: group(),
        times: group(),
        cancellations: group(),
        surgeries: group(),
        complications: group(),
        refractive: group(),
        small_sample: 'Amostra pequena (n < :n)',
        empty: 'Vazio',
        days: ':value dias',
        export: 'CSV',
        chart_alt: 'Gráfico :title',
        scope_clinic: 'Clínica',
        scope_doctor: 'Médico',
        only_aggregates: 'Agregado',
    },
};

const indicators = (over = {}) => ({
    period: { from: '2026-07-17', to: '2026-10-15' },
    small_sample: 10,
    authorization: {
        requested: 4,
        authorized: 2,
        partial: 1,
        waived: 1,
        denied: 1,
        responded: 5,
        rate: 80,
        denial_reasons: [
            { reason: 'fora da cobertura', other: false, count: 3 },
            { reason: null, other: true, count: 1 },
        ],
    },
    times: {
        request_to_authorization: { n: 2, mean: 5, median: 5 },
        authorization_to_surgery: { n: 0, mean: null, median: null },
        request_to_surgery: { n: 2, mean: 9.5, median: 9.5 },
    },
    cancellations: { cancelled: 1, suspended: 0 },
    surgeries: {
        total: 3,
        by_surgeon: [{ label: 'DR. A', count: 3 }],
        by_facility: [],
        by_month: [{ label: '2026-10', count: 3 }],
    },
    complications: {
        evaluations: 2,
        by_milestone: [{ milestone: 'd1', label: 'D1', evaluations: 2, with_findings: 1, severe: 0 }],
    },
    refractive: {
        overall: { n: 4, me: 0.19, mae: 0.69, within_050: 50, within_100: 75, small_sample: true },
        by_surgeon: [
            { surgeon: 'DR. A', n: 4, me: 0.19, mae: 0.69, within_050: 50, within_100: 75, small_sample: true },
        ],
    },
    ...over,
});

const mountPage = (over = {}, scope = 'clinic') =>
    mount(Indicators, {
        props: {
            indicators: indicators(over),
            filters: { from: '2026-07-17', to: '2026-10-15' },
            scope,
            urls: { index: '/ind', export: '/ind/export?from=a&to=b' },
            t,
        },
    });

describe('Indicators', () => {
    it('mostra os KPIs e o link de CSV com o período do filtro', () => {
        const w = mountPage();

        expect(w.find('[data-test="kpi-surgeries"]').text()).toBe('3');
        expect(w.find('[data-test="kpi-auth-rate"]').text()).toContain('80');
        expect(w.find('[data-testid="ind-export"]').attributes('href')).toBe(
            '/ind/export?from=2026-07-17&to=2026-10-15',
        );
    });

    it('sinaliza amostra pequena no erro refracional e mostra a tabela com os números', () => {
        const w = mountPage();
        const table = w.find('[data-testid="ind-refractive"]');

        expect(table.text()).toContain('Amostra pequena (n < 10)');
        expect(table.text()).toContain('DR. A');
        expect(table.text()).toContain('0,19'); // ME localizado
    });

    it('sem dados: texto de vazio em vez de tabela', () => {
        const w = mountPage({
            refractive: { overall: null, by_surgeon: [] },
            surgeries: { total: 0, by_surgeon: [], by_facility: [], by_month: [] },
        });

        expect(w.find('[data-testid="ind-refractive-empty"]').exists()).toBe(true);
        expect(w.find('[data-testid="ind-refractive"]').exists()).toBe(false);
    });

    it('o gráfico por mês tem texto alternativo e a tabela equivalente', () => {
        const w = mountPage();
        const chart = w.find('[role="img"]');

        expect(chart.attributes('aria-label')).toContain('Gráfico');
        expect(w.text()).toContain('2026-10');
    });

    it('o escopo do médico é anunciado', () => {
        expect(mountPage({}, 'doctor').text()).toContain('Médico');
    });
});
