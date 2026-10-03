import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import AiUsageIndex from '@/Pages/Panel/Manager/AiUsage/Index.vue';

/**
 * Manager → Uso de IA: indicadores no formato do idioma (com variação
 * colorida por significado), filtros/período/ordenação no servidor,
 * drill-down pelos rankings, exportação com o recorte atual e detalhe.
 */
const pageProps = vi.hoisted(() => ({ value: { locale: 'pt_BR', errors: {} } }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps.value }),
    router: { get: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { props: ['data'], template: '<nav />' } }));
vi.mock('@/Pages/Panel/Manager/AiUsage/AiUsageTrendChart.vue', () => ({
    default: { props: ['points', 'granularity'], template: '<div class="chart-stub" :data-points="points.length" />' },
}));
vi.mock('@/Pages/Panel/Manager/AiUsage/AiUsageRunDrawer.vue', () => ({
    default: {
        props: ['open', 'runId'],
        template: '<div class="drawer-stub" :data-open="String(open)" :data-run="runId ?? \'\'" />',
    },
}));

// Chave sem tradução devolve a própria chave; textos com placeholders reais.
function proxy(values) {
    return new Proxy(values, { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) });
}
const t = proxy({
    title: 'Uso de IA',
    internal: 'Uso interno (plataforma)',
    kpi: proxy({
        cost_usd: ':value em US$',
        delta_up: 'Aumentou :value',
        delta_down: 'Diminuiu :value',
        delta_pp: ':value p.p.',
        failed_of: ':failed de :total execuções',
        margin_pct: ':pct% da receita',
    }),
    period: proxy({}),
    filters: proxy({ user: 'Usuário: :name' }),
    columns: proxy({}),
    breakdown: proxy({ drill_hint: 'Filtrar por :name', share: ':pct% do custo', showing_top: ':shown de :total' }),
    chart: proxy({}),
    rate_note: 'US$ 1 = :rate (:date)',
    rate_fallback: 'Estimada: US$ 1 = :rate',
    revenue_note: 'Crédito US$ :value.',
});

const kpi = {
    runs: 4,
    failed: 1,
    failure_rate: 25,
    calls: 6,
    failed_calls: 1,
    cost_usd: 0.47,
    cost_brl: 2.35,
    credits: 14,
    revenue_usd: 0.14,
    revenue_brl: 0.7,
    margin_brl: -1.65,
    margin_pct: -235.7,
    tokens_in: 6000,
    tokens_out: 1200,
    entities: 3,
    users: 3,
    avg_cost_brl: 0.5875,
};

function baseProps(overrides = {}) {
    return {
        filters: {
            preset: 'this_month',
            from: '2026-10-01',
            to: '2026-10-15',
            entity_id: null,
            user_id: null,
            user_name: null,
            workflow: null,
            provider: null,
            status: null,
            sort: 'created_at',
            direction: 'desc',
        },
        presets: ['7d', '30d', 'this_month', 'last_month', '3m', '12m', 'custom'],
        kpis: {
            current: kpi,
            previous: { ...kpi, runs: 1 },
            delta: {
                runs: 300,
                cost_brl: 370,
                credits: null,
                revenue_brl: null,
                margin_brl: -50,
                avg_cost_brl: 10,
                failure_rate_pp: 25,
            },
        },
        series: { granularity: 'day', points: [{ key: '2026-10-01', runs: 0, failed: 0, cost_brl: 0 }] },
        byWorkflow: [
            {
                workflow: 'eye_image_analysis',
                label: 'Análise de imagem ocular',
                runs: 1,
                failure_rate: 0,
                cost_brl: 2,
                avg_cost_brl: 2,
                credits: 10,
            },
        ],
        byEntity: {
            rows: [
                {
                    entity_id: 'ent-a',
                    name: 'CLÍNICA ALFA',
                    is_internal: false,
                    runs: 2,
                    failure_rate: 50,
                    cost_brl: 2,
                    revenue_brl: 0.5,
                    margin_brl: -1.5,
                },
            ],
            total: 20,
        },
        byUser: {
            rows: [
                {
                    user_id: 'usr-a',
                    name: 'DRA. ANA',
                    entity_id: 'ent-a',
                    entity_name: 'CLÍNICA ALFA',
                    runs: 2,
                    failure_rate: 50,
                    cost_brl: 2,
                },
            ],
            total: 1,
        },
        byProvider: [
            {
                provider: 'openai',
                provider_label: 'ChatGPT',
                model: 'gpt-4.1',
                calls: 3,
                call_failure_rate: 33.3,
                skipped_calls: 0,
                avg_latency_ms: 1000,
                tokens_in: 3000,
                tokens_out: 600,
                cost_brl: 1.6,
            },
        ],
        runs: {
            data: [
                {
                    id: 'run-1',
                    created_at: '2026-10-02T10:00:00-03:00',
                    entity_name: 'CLÍNICA ALFA',
                    is_internal: false,
                    user_name: 'DRA. ANA',
                    workflow_label: 'Análise de imagem ocular',
                    status: 'approved',
                    status_label: 'Aprovada',
                    providers: ['Gemini', 'ChatGPT'],
                    credits: 10,
                    calls: 2,
                    failed_calls: 0,
                    cost_usd: 0.4,
                    cost_brl: 2,
                },
            ],
            links: [],
        },
        options: {
            entities: [{ value: 'ent-a', label: 'CLÍNICA ALFA', is_internal: false }],
            workflows: [{ value: 'eye_image_analysis', label: 'Análise de imagem ocular' }],
            providers: [{ value: 'openai', label: 'ChatGPT' }],
            statuses: [{ value: 'failed', label: 'Falhou' }],
        },
        rate: { rate: 5, topped_up_at: '2026-09-01', is_fallback: false, usd_per_credit: 0.01 },
        topLimit: 15,
        t,
        ...overrides,
    };
}

const mountPage = (overrides = {}) => mount(AiUsageIndex, { props: baseProps(overrides) });
const lastVisit = () => router.get.mock.calls.at(-1);
// Intl separa moeda e valor com espaço não separável (U+00A0).
const text = (wrapper) => wrapper.text().replace(/\u00a0/g, ' ');

describe('Manager → Uso de IA', () => {
    beforeEach(() => vi.clearAllMocks());

    it('indicadores no formato do idioma, com US$ e margem negativa', () => {
        const wrapper = mountPage();

        expect(text(wrapper.find('[data-test="kpi-cost"]'))).toContain('R$ 2,35');
        expect(text(wrapper.find('[data-test="kpi-cost"]'))).toContain('US$ 0,47 em US$');
        expect(text(wrapper.find('[data-test="kpi-runs"]'))).toContain('4');
        expect(text(wrapper.find('[data-test="kpi-failure"]'))).toContain('25,0%');
        expect(text(wrapper.find('[data-test="kpi-failure"]'))).toContain('1 de 4 execuções');
        expect(text(wrapper.find('[data-test="kpi-margin"]'))).toMatch(/-R\$\s1,65/);
    });

    it('variação com cor pelo significado e texto pra leitor de tela', () => {
        const wrapper = mountPage();

        // custo subiu = ruim; execuções subiram = bom; falha subiu (p.p.) = ruim
        expect(wrapper.find('[data-test="kpi-cost"] .au-delta').classes()).toContain('au-delta--bad');
        expect(wrapper.find('[data-test="kpi-cost"] .visually-hidden').text()).toBe('Aumentou 370,0%');
        expect(wrapper.find('[data-test="kpi-runs"] .au-delta').classes()).toContain('au-delta--good');
        expect(wrapper.find('[data-test="kpi-failure"] .visually-hidden').text()).toBe('Aumentou 25,0 p.p.');
        expect(wrapper.find('[data-test="kpi-margin"] .au-delta').classes()).toContain('au-delta--bad');
    });

    it('nota da cotação: recarga real ou estimada', () => {
        expect(text(mountPage().find('[data-test="rate-note"]'))).toContain('US$ 1 = R$ 5,00 (01/09/2026)');

        const fallback = mountPage({
            rate: { rate: 5.5, topped_up_at: null, is_fallback: true, usd_per_credit: 0.01 },
        });
        expect(text(fallback.find('[data-test="rate-note"]'))).toContain('Estimada: US$ 1 = R$ 5,50');
    });

    it('período: atalho e personalizado consultam o servidor (padrões fora da URL)', async () => {
        const wrapper = mountPage();
        const button = (label) => wrapper.findAll('button').find((b) => b.text() === label);

        await button('7d').trigger('click');
        expect(lastVisit()[1]).toEqual({ preset: '7d' });

        await button('custom').trigger('click');
        await wrapper.find('#au-from').setValue('2026-09-01');
        await wrapper.find('#au-to').setValue('2026-09-30');
        await wrapper.find('form').trigger('submit');
        expect(lastVisit()[1]).toEqual({ preset: 'custom', from: '2026-09-01', to: '2026-09-30' });
    });

    it('filtros e "limpar filtros"', async () => {
        const wrapper = mountPage({ filters: { ...baseProps().filters, workflow: 'eye_image_analysis' } });

        await wrapper.find('#au-entity').setValue('ent-a');
        expect(lastVisit()[1]).toEqual({ entity_id: 'ent-a', workflow: 'eye_image_analysis' });

        await wrapper.find('[data-test="clear-filters"]').trigger('click');
        expect(lastVisit()[1]).toEqual({});
    });

    it('clicar no ranking filtra a tela (ação, clínica, usuário com a clínica)', async () => {
        const wrapper = mountPage();
        const drill = (name) =>
            wrapper.findAll('button').find((b) => b.attributes('aria-label') === `Filtrar por ${name}`);

        await drill('Análise de imagem ocular').trigger('click');
        expect(lastVisit()[1]).toEqual({ workflow: 'eye_image_analysis' });

        await drill('DRA. ANA').trigger('click');
        expect(lastVisit()[1]).toEqual({ user_id: 'usr-a', entity_id: 'ent-a' });
    });

    it('ranking cortado no topo avisa quantos existem', () => {
        expect(mountPage().text()).toContain('1 de 20');
    });

    it('filtro de usuário aparece com o nome e pode ser removido', async () => {
        const wrapper = mountPage({ filters: { ...baseProps().filters, user_id: 'usr-a', user_name: 'DRA. ANA' } });

        expect(wrapper.text()).toContain('Usuário: DRA. ANA');
        await wrapper.find('.btn-close').trigger('click');
        expect(lastVisit()[1]).toEqual({});
    });

    it('exportação leva o recorte atual (sem a ordenação da tabela)', () => {
        const wrapper = mountPage({
            filters: { ...baseProps().filters, preset: '30d', provider: 'openai', sort: 'cost', direction: 'asc' },
        });

        const href = wrapper.find('[data-test="export-csv"]').attributes('href');
        expect(href).toContain('manager.ai-usage.export');
        expect(href).toContain('preset=30d');
        expect(href).toContain('provider=openai');
        expect(href).not.toContain('sort=');
    });

    it('ordenar por custo e abrir o detalhe da execução', async () => {
        const wrapper = mountPage();
        const costHeader = wrapper
            .findAll('th button.sortable-th-btn')
            .find((button) => button.text().includes('cost'));

        await costHeader.trigger('click');
        expect(lastVisit()[1]).toEqual({ sort: 'cost', direction: 'asc' });

        await wrapper.find('[data-test="run-row"] button[title="view_run"]').trigger('click');
        expect(wrapper.find('.drawer-stub').attributes('data-open')).toBe('true');
        expect(wrapper.find('.drawer-stub').attributes('data-run')).toBe('run-1');
    });

    it('período recusado pelo servidor mostra a mensagem junto das datas', () => {
        pageProps.value = { locale: 'pt_BR', errors: { to: 'O período personalizado pode ter no máximo 731 dias.' } };
        const wrapper = mountPage({ filters: { ...baseProps().filters, preset: 'custom' } });

        expect(wrapper.find('[data-test="period-error"]').text()).toBe(
            'O período personalizado pode ter no máximo 731 dias.',
        );
        pageProps.value = { locale: 'pt_BR', errors: {} };
    });

    it('sem execuções mostra o estado vazio', () => {
        expect(mountPage({ runs: { data: [], links: [] } }).text()).toContain('runs_empty');
    });
});
