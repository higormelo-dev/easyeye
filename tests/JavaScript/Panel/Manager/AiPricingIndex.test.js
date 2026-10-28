import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import AiPricingIndex from '@/Pages/Panel/Manager/AiPricing/Index.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        router: { reload: vi.fn() },
    };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }));

const WORKFLOWS = [
    'exam_assistant',
    'report_drafting',
    'consensus_review',
    'eye_image_analysis',
    'assistant_chat',
    'record_assist',
    'medicine_posology',
    'platform_finance_digest',
    'platform_finance_chat',
];
const INTERNAL = ['medicine_posology', 'platform_finance_digest', 'platform_finance_chat'];
const settings = {
    markup_multiplier: 5,
    markup_by_workflow: {},
    internal_workflows: INTERNAL,
    minimum_charge_cents_default: 10,
    minimum_charge_cents_by_workflow: {
        exam_assistant: 10,
        report_drafting: 10,
        consensus_review: 10,
        eye_image_analysis: 10,
    },
    custom_min_cents: 100000,
    custom_max_cents: 1000000,
    custom_step_cents: 100,
    limits: { markup_min: 1, markup_max: 20, minimum_min: 1, minimum_max: 10000, custom_hard_max_cents: 10000000 },
};
const rate = {
    rate: 5.5,
    source: 'manual',
    effective_at: '2026-10-23T12:00:00Z',
    fetched_at: '2026-10-23T12:00:00Z',
    recorded_by_name: 'Admin',
    note: null,
    is_fallback: false,
    fetched_today: false,
    is_automatic: false,
    is_manual_override: false,
    age_days: 0,
};
const autoRate = {
    ...rate,
    rate: 5.4321,
    source: 'frankfurter',
    effective_at: '2026-10-22T03:00:00Z',
    fetched_at: '2026-10-23T09:05:00Z',
    recorded_by_name: null,
    note: 'Frankfurter/BCE',
    fetched_today: true,
    is_automatic: true,
    age_days: 1,
};
const workflowLabels = Object.fromEntries(WORKFLOWS.map((w) => [w, `Ação ${w}`]));
workflowLabels.exam_assistant = 'Assistente de exames';
workflowLabels.report_drafting = 'Rascunho de laudo';
const workflowExamples = WORKFLOWS.map((workflow) => ({
    workflow,
    workflow_label: workflowLabels[workflow],
    is_internal: INTERNAL.includes(workflow),
    raw_cost_usd: 0.01,
    exchange_rate_brl_usd: 5.5,
    markup_multiplier: workflow === 'report_drafting' ? 8 : 5,
    minimum_charge_cents: 10,
    charge_cents: workflow === 'report_drafting' ? 44 : 28,
    charge_formatted: workflow === 'report_drafting' ? 'R$ 0,44' : 'R$ 0,28',
    minimum_applied: false,
}));
// Contract delivered by Forja: all nine keys, zero runs => null averages.
const noUsage = {
    runs: 0,
    avg_cost_usd: null,
    avg_price_cents: null,
    avg_price_formatted: null,
    minimum_applied: null,
};
const workflowUsage = {
    window_days: 30,
    rows: {
        ...Object.fromEntries(WORKFLOWS.map((key) => [key, { ...noUsage }])),
        report_drafting: {
            runs: 2,
            avg_cost_usd: 0.01,
            avg_price_cents: 44,
            avg_price_formatted: 'R$ 0,44',
            minimum_applied: false,
        },
    },
};
const t = {
    title: 'Precificação de IA',
    subtitle: 'Regras',
    breadcrumb_home: 'Dashboard',
    settings_title: 'Regras de cobrança',
    tab_rate: 'Cotação do dólar',
    tab_billing: 'Cobrança das clínicas',
    tab_internal: 'Ações internas do SaaS',
    billing_title: 'Cobrança das clínicas',
    billing_hint:
        'Regras globais, markup por ação e valor mínimo por operação cobrada da clínica. As ações internas ficam na aba "Ações internas do SaaS".',
    internal_title: 'Ações internas do SaaS',
    internal_hint: 'Não cobradas da clínica; markup para relatório interno.',
    column_action: 'Ação',
    column_markup: 'Markup',
    column_minimum: 'Mínimo por operação (R$)',
    column_usage_price: 'Preço médio por uso (últimos :days dias)',
    usage_runs: ':count usos',
    usage_none: 'sem uso em :days dias',
    minimum_note: 'Proteção separada da fórmula: se a conta der menos que isso, cobra-se este valor.',
    default_minimum: 'usa o padrão',
    fields: {
        markup_multiplier: 'Markup (vezes o custo)',
        minimum_charge_cents_default: 'Valor mínimo por operação',
        custom_min_cents: 'Mínimo do pacote',
        custom_max_cents: 'Máximo do pacote',
        rate_brl_per_usd: 'Nova cotação',
    },
    hints: {
        markup_multiplier: 'De 1 a 20. 5 = a clínica paga 5x o custo do provedor convertido para reais.',
        minimum_charge_cents_default:
            'Proteção separada da fórmula: se a conta der menos que isso, cobra-se este valor (entre :min e :max).',
        custom_min_cents: 'Até o valor máximo do pacote.',
        custom_max_cents: 'Deve ser igual ou superior ao mínimo.',
    },
    formula_title: 'Como a cobrança é calculada',
    formula_sentence: 'Preço = custo do provedor (US$) × câmbio × markup; nunca abaixo do valor mínimo.',
    formula_example: 'US$ :usd de custo vira :price',
    custom_package_title: 'Pacote personalizado',
    custom_package_hint: 'A clínica escolhe um valor entre :min e :max, em múltiplos de :step.',
    column_cost_usd: 'Custo (US$)',
    column_price_brl: 'Preço (R$)',
    workflows: workflowLabels,
    sources: { manual: 'Manual', fallback: 'Contingência', frankfurter: 'Frankfurter (BCE)' },
    fetched_at: 'Buscada em',
    age_days: 'há :count dias',
    age_one_day: 'há 1 dia',
    today: 'hoje',
    automatic: 'Automática',
    manual_override: 'Manual sobrepõe a automática de hoje',
    fetch_now: 'Buscar agora',
    fetch_confirm: 'Confirmar busca da cotação automática',
    fetch_hint: 'Busca a taxa do BCE.',
    fetch_unavailable: 'A automática de hoje já foi buscada; registre uma cotação manual.',
    markup_workflow_title: 'Markup por ação',
    markup_use_default: 'Usar padrão global',
    markup_global_default: 'Padrão global',
    markup_effective: 'Efetivo',
    fallback_warning: 'Defina a cotação.',
    rate_title: 'Registrar cotação',
    current_rate: 'Cotação atual',
    source: 'Origem',
    effective_at: 'Vigência',
    recorded_by: 'Registrado por',
    note: 'Observação',
    examples_title: 'A conta',
    examples_hint:
        'Exemplos da operação de assistente de exames com o markup efetivo dessa ação. O valor cobrado considera o valor mínimo por operação e o arredondamento para centavos.',
    provider_cost: 'Custo do provedor',
    exchange_rate: 'Câmbio',
    markup: 'Markup',
    minimum_applied: 'Mínimo aplicado',
    examples_refresh: 'Atualizando exemplos',
    packages_title: 'Pacotes disponíveis',
    featured: 'Destaque',
    history_title: 'Histórico',
    confirm_settings: 'Confirmar regras',
    confirm_rate: 'Confirmar cotação',
    reason: 'Motivo obrigatório',
    reason_invalid: 'Informe de 10 a 500 caracteres.',
    review_settings: 'Revisar configurações',
    review_rate: 'Revisar cotação',
    confirm_save: 'Confirmar e salvar',
    saving: 'Salvando',
    cancel: 'Cancelar',
    close: 'Fechar',
    save_failed: 'Falha ao salvar.',
};
const wrappers = [];
function render(overrides = {}) {
    const wrapper = mount(AiPricingIndex, {
        attachTo: document.body,
        props: {
            settings: structuredClone(settings),
            rate: { ...rate },
            t,
            packages: [
                { code: 'basic', name: 'Básico', value_cents: 10000, featured: true },
                { code: 'custom', name: 'Personalizado', custom: true },
            ],
            examples: [
                {
                    raw_cost_usd: 1,
                    exchange_rate_brl_usd: 5.5,
                    markup_multiplier: 5,
                    charge_cents: 2750,
                    charge_formatted: 'R$ 27,50',
                    minimum_applied: false,
                },
                {
                    raw_cost_usd: 0.001,
                    exchange_rate_brl_usd: 5.5,
                    markup_multiplier: 5,
                    charge_cents: 10,
                    charge_formatted: 'R$ 0,10',
                    minimum_applied: true,
                },
            ],
            workflow_examples: structuredClone(workflowExamples),
            workflow_usage: { window_days: 30, rows: {} },
            history: {
                data: [
                    {
                        id: 'old',
                        source: 'manual',
                        rate_brl_per_usd: 5.5,
                        effective_at: rate.effective_at,
                        recorded_by_name: 'Admin',
                        note: 'Anterior',
                    },
                ],
                last_page: 1,
                links: [],
            },
            urls: {
                settings: '/pricing/settings',
                exchange_rates: '/pricing/rates',
                exchange_rates_fetch: '/pricing/rates/fetch',
            },
            ...overrides,
        },
        global: { stubs: { teleport: true } },
    });
    wrappers.push(wrapper);
    return wrapper;
}
async function enterMoney(wrapper, selector, value) {
    const input = wrapper.get(selector);
    await input.trigger('focus');
    await input.setValue(value);
    await input.trigger('blur');
}

function respond(body, status = 200) {
    fetch.mockResolvedValue({ ok: status < 400, status, json: async () => body });
}
async function confirm(wrapper, reason = 'Revisão mensal dos valores') {
    await wrapper.get('#pricing-reason').setValue(reason);
    await wrapper.get('#pricing-confirm-form').trigger('submit');
    await flushPromises();
}

function panel(wrapper, name) {
    return wrapper.get(`[data-test="tab-panel-${name}"]`);
}

async function showTab(wrapper, name) {
    await wrapper.get(`[data-tab="${name}"]`).trigger('click');
    expect(panel(wrapper, name).isVisible()).toBe(true);
}

async function review(wrapper, name = 'billing') {
    await showTab(wrapper, name);
    await panel(wrapper, name).get('[data-test="settings-review"]').trigger('click');
    await wrapper.get('[data-test="settings-form"]').trigger('submit');
}

beforeEach(() => {
    vi.clearAllMocks();
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    // clearAllMocks só limpa o histórico: sem o reset, a Promise pendente do teste de envio duplicado vaza para o próximo.
    fetch.mockReset();
});

describe('Manager AI pricing', () => {
    it('abre em billing por padrão e expõe aria-selected/aria-controls', () => {
        const wrapper = render();
        const tab = wrapper.get('[data-tab="billing"]');
        expect(tab.attributes('role')).toBe('tab');
        expect(tab.attributes('aria-selected')).toBe('true');
        expect(tab.attributes('aria-controls')).toBe('pricing-panel-billing');
        const panel = wrapper.get('[data-test="tab-panel-billing"]');
        expect(panel.attributes('role')).toBe('tabpanel');
        expect(panel.attributes('aria-labelledby')).toBe(tab.attributes('id'));
        expect(panel.isVisible()).toBe(true);
    });

    describe('média real por uso em 30 dias', () => {
        it('N1 mostra média real, mínimo aplicado, conta no title e contagem juntos', () => {
            const wrapper = render({
                workflow_usage: {
                    ...workflowUsage,
                    rows: {
                        ...workflowUsage.rows,
                        report_drafting: {
                            runs: 1,
                            avg_cost_usd: 0.00001,
                            avg_price_cents: 10,
                            avg_price_formatted: 'R$ 0,10',
                            minimum_applied: true,
                        },
                    },
                },
            });
            const billing = panel(wrapper, 'billing');
            expect(billing.findAll('thead th').map((th) => th.text())).toContain(
                'Preço médio por uso (últimos 30 dias)',
            );
            expect(billing.get('th[title]').attributes('title')).toBe(t.minimum_note);
            expect(billing.get('#pricing-minimum_charge_cents_default-hint').text()).toContain(
                'Proteção separada da fórmula',
            );
            const cell = billing.get('[data-workflow="report_drafting"] [data-test="workflow-example"]');
            expect(cell.get('[data-test="usage-price"]').text()).toContain('R$ 0,10');
            expect(cell.get('.badge').text()).toBe(t.minimum_applied);
            expect(cell.get('[data-test="usage-price"]').attributes('title')).toContain(
                'US$ 0,00001 × Câmbio 5,5 × Markup 8 = R$ 0,10',
            );
            expect(cell.get('[data-test="usage-runs"]').text()).toBe('1 usos');
            expect(cell.text()).not.toContain('×');
        });

        it('N4 aplica a mesma coluna às ações internas, com média, title e contagem', async () => {
            const wrapper = render({
                workflow_usage: {
                    ...workflowUsage,
                    rows: {
                        ...workflowUsage.rows,
                        medicine_posology: {
                            runs: 3,
                            avg_cost_usd: 0.02,
                            avg_price_cents: 55,
                            avg_price_formatted: 'R$ 0,55',
                            minimum_applied: false,
                        },
                    },
                },
            });
            await showTab(wrapper, 'internal');
            const internal = panel(wrapper, 'internal');
            expect(internal.findAll('thead th').map((th) => th.text())).toContain(
                'Preço médio por uso (últimos 30 dias)',
            );
            const cell = internal.get('[data-workflow="medicine_posology"] [data-test="workflow-example"]');
            expect(cell.get('[data-test="usage-price"]').text()).toBe('R$ 0,55');
            expect(cell.get('[data-test="usage-price"]').attributes('title')).toContain(
                'US$ 0,02 × Câmbio 5,5 × Markup 5 = R$ 0,55',
            );
            expect(cell.get('[data-test="usage-runs"]').text()).toBe('3 usos');
            expect(wrapper.get('[data-test="formula"]').text()).toContain('US$ 0,02 de custo vira R$ 0,55');
            expect(cell.text()).not.toContain('×');
        });

        it('N3 fórmula usa a média da ação mais usada e atualiza quando a prop muda', async () => {
            const wrapper = render({
                workflow_usage: {
                    ...workflowUsage,
                    rows: {
                        ...workflowUsage.rows,
                        assistant_chat: {
                            runs: 5,
                            avg_cost_usd: 0.123456,
                            avg_price_cents: 340,
                            avg_price_formatted: 'R$ 3,40',
                            minimum_applied: false,
                        },
                    },
                },
            });
            const formula = wrapper.get('[data-test="formula"]');
            expect(formula.text()).toContain('US$ 0,123456 de custo vira R$ 3,40');
            await wrapper.setProps({ workflow_usage: workflowUsage });
            expect(formula.text()).toContain('US$ 0,01 de custo vira R$ 0,44');
        });

        it('N3 empate escolhe a primeira ação na ordem dos workflows, inclusive internas', () => {
            const wrapper = render({
                workflow_usage: {
                    ...workflowUsage,
                    rows: {
                        medicine_posology: {
                            runs: 5,
                            avg_cost_usd: 0.02,
                            avg_price_cents: 55,
                            avg_price_formatted: 'R$ 0,55',
                            minimum_applied: false,
                        },
                        report_drafting: { ...workflowUsage.rows.report_drafting, runs: 5 },
                    },
                },
            });
            expect(wrapper.get('[data-test="formula"]').text()).toContain('US$ 0,01 de custo vira R$ 0,44');
        });

        it('N2 sem uso mostra — e ausência de uso por ação, com fórmula no fallback didático', () => {
            const wrapper = render({
                workflow_usage: {
                    window_days: 30,
                    rows: Object.fromEntries(WORKFLOWS.map((key) => [key, { ...noUsage }])),
                },
            });
            for (const cell of wrapper.findAll('[data-test="workflow-example"]')) {
                expect(cell.get('[data-test="usage-price"]').text()).toBe('—');
                expect(cell.get('[data-test="usage-runs"]').text()).toBe('sem uso em 30 dias');
                expect(cell.find('.badge').exists()).toBe(false);
            }
            expect(wrapper.get('[data-test="formula"]').text()).toContain('US$ 0,01 de custo vira R$ 0,28');
        });
    });

    it('N5 recarrega workflow_usage e oculta médias e contagens antigas até a resposta', async () => {
        const wrapper = render({ workflow_usage: workflowUsage });
        respond({ message: 'Salvo.', settings: structuredClone(settings) });
        await review(wrapper);
        await confirm(wrapper);
        const reload = router.reload.mock.calls[0][0];
        expect(reload.only).toContain('workflow_usage');
        for (const cell of wrapper.findAll('[data-test="workflow-example"]')) {
            expect(cell.get('[role="status"]').text()).toBe(t.examples_refresh);
            expect(cell.find('[data-test="usage-price"]').exists()).toBe(false);
            expect(cell.find('[data-test="usage-runs"]').exists()).toBe(false);
        }
        await wrapper.setProps({
            workflow_usage: {
                ...workflowUsage,
                rows: {
                    ...workflowUsage.rows,
                    report_drafting: {
                        ...workflowUsage.rows.report_drafting,
                        runs: 4,
                        avg_price_formatted: 'R$ 0,88',
                        avg_price_cents: 88,
                    },
                },
            },
        });
        reload.onSuccess();
        await flushPromises();
        const cell = panel(wrapper, 'billing').get('[data-workflow="report_drafting"] [data-test="workflow-example"]');
        expect(cell.get('[data-test="usage-price"]').text()).toBe('R$ 0,88');
        expect(cell.get('[data-test="usage-runs"]').text()).toBe('4 usos');
    });

    describe('cobrança em reais', () => {
        it.each([
            ['0,10', 10],
            ['1.000,00', 100000],
        ])('converte %s reais em %i centavos só no payload', async (value, cents) => {
            const wrapper = render();
            await enterMoney(wrapper, '#pricing-custom_min_cents', value);
            await enterMoney(wrapper, '#pricing-minimum_charge_cents_default', '0,10');
            await enterMoney(wrapper, '#pricing-workflow-0', '0,29');
            await enterMoney(wrapper, '#pricing-custom_max_cents', '10.000,00');
            respond({ message: 'Salvo.', settings: structuredClone(settings) });
            await review(wrapper);
            await confirm(wrapper);
            expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({
                custom_min_cents: cents,
                custom_max_cents: 1000000,
                minimum_charge_cents_default: 10,
                minimum_charge_cents_by_workflow: { exam_assistant: 29 },
            });
        });

        it('mostra valores iniciais e limites em reais com labels e dicas associados', () => {
            const wrapper = render();
            for (const [key, value] of Object.entries({
                minimum_charge_cents_default: '0,10',
                custom_min_cents: '1.000,00',
                custom_max_cents: '10.000,00',
            })) {
                const input = wrapper.get(`#pricing-${key}`);
                expect(input.element.value).toBe(value);
                expect(input.attributes('inputmode')).toBe('decimal');
                expect(wrapper.get(`label[for="pricing-${key}"]`).text()).toBe(t.fields[key]);
                for (const id of input.attributes('aria-describedby').split(' '))
                    expect(wrapper.find(`#${id}`).exists()).toBe(true);
            }
            const hint = wrapper.get('#pricing-minimum_charge_cents_default-hint').text().replace(/\s/g, '');
            expect(hint).toContain('R$0,01');
            expect(hint).toContain('R$100,00');
            const packageText = wrapper.get('[data-test="custom-package"]').text().replace(/\s/g, '');
            expect(packageText).toContain('emmúltiplosdeR$1,00');
            expect(packageText.match(/R\$1,00/g)).toHaveLength(1);
            expect(packageText).not.toContain('Incremento:');
            expect(wrapper.findAll('[data-test="minimum-input"]')).toHaveLength(4);
        });

        it('reaplica resposta em centavos como reais e mantém payload ao salvar novamente', async () => {
            const wrapper = render();
            const saved = {
                ...settings,
                minimum_charge_cents_default: 29,
                custom_min_cents: 123456,
                minimum_charge_cents_by_workflow: { exam_assistant: 31 },
            };
            respond({ message: 'Salvo.', settings: saved });
            await review(wrapper);
            await confirm(wrapper);
            expect(wrapper.get('#pricing-minimum_charge_cents_default').element.value).toBe('0,29');
            expect(wrapper.get('#pricing-custom_min_cents').element.value).toBe('1.234,56');
            expect(wrapper.get('#pricing-workflow-0').element.value).toBe('0,31');
            await review(wrapper);
            await confirm(wrapper);
            expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({
                minimum_charge_cents_default: 29,
                custom_min_cents: 123456,
                minimum_charge_cents_by_workflow: { exam_assistant: 31 },
            });
        });

        it('422 do pacote abre billing e foca MoneyInput antes de checkbox inválido', async () => {
            const wrapper = render();
            await review(wrapper, 'internal');
            respond(
                { errors: { custom_min_cents: ['Mínimo inválido.'], markup_by_workflow: ['Markup inválido.'] } },
                422,
            );
            await confirm(wrapper);
            const input = wrapper.get('[data-test="custom-package"] #pricing-custom_min_cents');
            expect(panel(wrapper, 'billing').isVisible()).toBe(true);
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
            expect(input.attributes('aria-invalid')).toBe('true');
            expect(input.classes()).toContain('is-invalid');
            expect(document.activeElement).toBe(input.element);
        });

        it('modal mostra dinheiro em R$ e preserva rascunho ao cancelar', async () => {
            const wrapper = render();
            await review(wrapper);
            const text = wrapper.get('[role="dialog"]').text().replace(/\s/g, '');
            expect(text).toContain('R$1.000,00');
            expect(text).toContain('R$0,10');
            expect(text).not.toContain('centavos');
            const workflowLabel = wrapper
                .get('[role="dialog"]')
                .findAll('dt')
                .find((label) => label.text() === workflowLabels.exam_assistant);
            expect(workflowLabel.element.nextElementSibling.textContent.replace(/\s/g, '')).toBe('R$0,10');
            await wrapper.get('.btn-close').trigger('click');
            expect(wrapper.get('#pricing-custom_min_cents').element.value).toBe('1.000,00');
        });

        it('explica a fórmula no topo com exemplo atual e respeita atualização', async () => {
            const wrapper = render();
            const formula = wrapper.get('[data-test="formula"]');
            expect(formula.text()).toContain(t.formula_sentence);
            expect(formula.text()).toContain('US$ 0,01 de custo vira R$ 0,28');
            expect(
                formula.element.compareDocumentPosition(wrapper.get('[data-test="markup-fieldset"]').element) &
                    Node.DOCUMENT_POSITION_FOLLOWING,
            ).toBeTruthy();
            await wrapper.setProps({
                workflow_examples: workflowExamples.map((row) => ({ ...row, charge_formatted: 'R$ 0,55' })),
            });
            expect(formula.text()).toContain('R$ 0,55');
            respond({ message: 'Salvo.', settings: structuredClone(settings) });
            await review(wrapper);
            await confirm(wrapper);
            expect(formula.get('[role="status"]').text()).toBe(t.examples_refresh);
            expect(formula.text()).not.toContain('R$ 0,55');
        });

        it('preço da ação mostra média e contagem; conta completa fica no title', () => {
            const wrapper = render({ workflow_usage: workflowUsage });
            const cell = panel(wrapper, 'billing').get(
                '[data-workflow="report_drafting"] [data-test="workflow-example"]',
            );
            expect(cell.get('[data-test="usage-price"]').text()).toBe('R$ 0,44');
            expect(cell.get('[data-test="usage-runs"]').text()).toBe('2 usos');
            expect(cell.text()).not.toContain('×');
            expect(cell.get('[title]').attributes('title')).toContain('Markup 8');
        });

        it('separa pacote das regras e mantém só dois campos globais', () => {
            const wrapper = render();
            const custom = wrapper.get('[data-test="custom-package"]');
            expect(custom.findAll('input')).toHaveLength(2);
            expect(custom.find('input[id*="step"]').exists()).toBe(false);
            expect(wrapper.get('[data-test="markup-fieldset"]').find('[data-test="custom-package"]').exists()).toBe(
                false,
            );
            expect(
                wrapper.get('#pricing-markup_multiplier').element.closest('.row').querySelectorAll('input'),
            ).toHaveLength(2);
            expect(custom.element.parentElement.textContent).toContain(t.packages_title);
        });
    });

    describe('abas', () => {
        it('nomeia as quatro tabelas pelo título visível sem captions absolutas no scroll', () => {
            const wrapper = render();
            const tables = wrapper.findAll('.table-responsive table');
            expect(tables).toHaveLength(4);
            expect(wrapper.get('#pricing-examples-title').element.tagName).toBe('H3');
            for (const table of tables) {
                const titleId = table.attributes('aria-labelledby');
                expect(titleId).toBeTruthy();
                expect(wrapper.get(`#${titleId}`).text()).not.toBe('');
                expect(['H2', 'H3']).toContain(wrapper.get(`#${titleId}`).element.tagName);
                expect(table.find('caption.visually-hidden').exists()).toBe(false);
            }
        });

        it('identifica a observação da cotação atual como um termo do dl', async () => {
            const wrapper = render({ rate: { ...autoRate, note: 'Frankfurter/BCE' } });
            await showTab(wrapper, 'rate');
            const noteLabel = panel(wrapper, 'rate')
                .findAll('dl dt')
                .find((term) => term.text() === t.note);
            expect(noteLabel).toBeDefined();
            expect(noteLabel.element.nextElementSibling.tagName).toBe('DD');
            expect(noteLabel.element.nextElementSibling.textContent).toContain('Frankfurter/BCE');
        });

        it('abre em rate quando a cotação está em contingência', () => {
            const wrapper = render({ rate: { ...rate, is_fallback: true } });
            expect(panel(wrapper, 'rate').isVisible()).toBe(true);
            expect(panel(wrapper, 'billing').isVisible()).toBe(false);
        });

        it('troca de aba atualiza aria-selected e visibilidade sem desmontar os painéis', async () => {
            const wrapper = render();
            expect(wrapper.findAll('[role="tab"]').map((tab) => tab.attributes('data-tab'))).toEqual([
                'rate',
                'billing',
                'internal',
            ]);
            for (const name of ['rate', 'internal', 'billing']) {
                await showTab(wrapper, name);
                for (const other of ['rate', 'billing', 'internal']) {
                    expect(panel(wrapper, other).isVisible()).toBe(other === name);
                    expect(wrapper.get(`[data-tab="${other}"]`).attributes('aria-selected')).toBe(
                        String(other === name),
                    );
                }
            }
        });

        it('mantém o alerta de contingência visível em qualquer aba', async () => {
            const wrapper = render({ rate: { ...rate, is_fallback: true } });
            for (const name of ['rate', 'billing', 'internal']) {
                await showTab(wrapper, name);
                expect(wrapper.get('[data-test="fallback"]').isVisible()).toBe(true);
            }
        });

        it('mantém rascunho de markup e piso ao alternar entre abas', async () => {
            const wrapper = render();
            await showTab(wrapper, 'billing');
            await wrapper.get('#pricing-markup-default-1').setValue(false);
            await wrapper.get('#pricing-markup-1').setValue(9);
            await enterMoney(wrapper, '#pricing-workflow-1', '15,00');
            await showTab(wrapper, 'internal');
            await showTab(wrapper, 'rate');
            await showTab(wrapper, 'billing');
            expect(wrapper.get('#pricing-markup-1').element.value).toBe('9');
            expect(wrapper.get('#pricing-workflow-1').element.value).toBe('15,00');
        });

        it('lista 6 ações cobradas em billing e 3 internas em internal, sem ids duplicados nem forms aninhados', async () => {
            const wrapper = render();
            await showTab(wrapper, 'billing');
            expect(
                panel(wrapper, 'billing')
                    .findAll('[data-workflow]')
                    .map((row) => row.attributes('data-workflow')),
            ).toEqual(WORKFLOWS.slice(0, 6));
            await showTab(wrapper, 'internal');
            expect(
                panel(wrapper, 'internal')
                    .findAll('[data-workflow]')
                    .map((row) => row.attributes('data-workflow')),
            ).toEqual(INTERNAL);
            const inputs = wrapper.findAll('input[id^="pricing-markup-"]');
            expect(new Set(inputs.map((input) => input.attributes('id'))).size).toBe(inputs.length);
            expect(wrapper.get('[data-test="settings-form"]').find('[data-test="rate-form"]').exists()).toBe(false);
            expect(wrapper.findAll('[data-test="settings-form"]')).toHaveLength(1);
        });

        it('mostra mínimo editável só nas 4 ações com mínimo específico e usa o padrão nas demais', () => {
            const wrapper = render();
            const billing = panel(wrapper, 'billing');
            expect(billing.findAll('input[id^="pricing-workflow-"]')).toHaveLength(4);
            expect(billing.get('[data-workflow="assistant_chat"]').text()).toContain('usa o padrão');
            expect(billing.get('[data-workflow="record_assist"]').text()).toContain('usa o padrão');
            for (const index of [0, 1, 2, 3]) {
                expect(billing.get(`#pricing-workflow-${index}`).attributes('aria-label')).toContain(
                    workflowLabels[WORKFLOWS[index]],
                );
                const label = billing.get(`label[for="pricing-workflow-${index}"]`);
                expect(label.text()).toContain(workflowLabels[WORKFLOWS[index]]);
                expect(label.classes()).toContain('visually-hidden');
            }
        });

        it('erro markup_by_workflow.<interna> troca para internal antes de focar o input', async () => {
            const wrapper = render();
            await showTab(wrapper, 'internal');
            await wrapper.get('#pricing-markup-default-6').setValue(false);
            await review(wrapper, 'billing');
            respond(
                {
                    message: 'Markup inválido.',
                    errors: { 'markup_by_workflow.medicine_posology': ['Markup interno inválido.'] },
                },
                422,
            );
            await confirm(wrapper);
            expect(panel(wrapper, 'internal').isVisible()).toBe(true);
            expect(wrapper.get('#pricing-markup-6').attributes('aria-invalid')).toBe('true');
            expect(document.activeElement).toBe(wrapper.get('#pricing-markup-6').element);
            expect(
                wrapper
                    .get('#pricing-markup-6')
                    .attributes('aria-describedby')
                    .split(' ')
                    .map((id) => wrapper.get(`#${id}`).text())
                    .join(' '),
            ).toContain('Markup interno inválido.');
        });

        it.each([
            ['settings', 'markup_by_workflow', 'billing'],
            ['settings', 'minimum_charge_cents_by_workflow', 'billing'],
            ['rate', 'rate_brl_per_usd', 'rate'],
            ['fetch', '_form', 'rate'],
        ])('erro %s/%s troca para a aba %s', async (kind, field, destination) => {
            const wrapper = render();
            if (kind === 'settings') {
                await showTab(wrapper, 'internal');
                await wrapper.get('#pricing-markup-default-6').setValue(false);
                await review(wrapper, 'internal');
            } else {
                await showTab(wrapper, 'rate');
                if (kind === 'rate') await wrapper.get('[data-test="rate-form"]').trigger('submit');
                else await wrapper.get('[data-test="fetch-now"]').trigger('click');
                await showTab(wrapper, 'internal');
            }
            respond(
                field === '_form' ? { message: 'Falha na busca.' } : { message: 'Valor inválido.', field },
                field === '_form' ? 409 : 422,
            );
            await confirm(wrapper);
            expect(panel(wrapper, destination).isVisible()).toBe(true);
        });

        it('settings-review aparece nas duas abas e envia o mesmo payload sem trocar a aba após salvar', async () => {
            const wrapper = render();
            respond({ message: 'Salvo.', settings: structuredClone(settings) });
            for (const name of ['billing', 'internal']) {
                await review(wrapper, name);
                await confirm(wrapper);
                expect(panel(wrapper, name).isVisible()).toBe(true);
            }
            expect(fetch.mock.calls.map((call) => call[0])).toEqual(['/pricing/settings', '/pricing/settings']);
            expect(fetch.mock.calls[0][1].body).toBe(fetch.mock.calls[1][1].body);
        });

        it('override interno entra no payload e confirmação; required depende da aba ativa', async () => {
            const wrapper = render();
            await showTab(wrapper, 'internal');
            await wrapper.get('#pricing-markup-default-6').setValue(false);
            await wrapper.get('#pricing-markup-6').setValue('');
            expect(wrapper.get('#pricing-markup-6').attributes('required')).toBeDefined();
            await showTab(wrapper, 'billing');
            expect(wrapper.get('#pricing-markup-6').attributes('required')).toBeUndefined();
            expect(wrapper.get('#pricing-markup-6').element.willValidate).toBe(false);
            // Happy DOM reports a stepMismatch for the existing global 5 / step 0.01.
            // Check the hidden invalid control directly, without bypassing validation on it.
            expect(wrapper.get('#pricing-markup-6').element.checkValidity()).toBe(true);
            await showTab(wrapper, 'internal');
            await wrapper.get('#pricing-markup-6').setValue(8);
            respond({ message: 'Salvo.', settings: { ...settings, markup_by_workflow: { medicine_posology: 8 } } });
            await review(wrapper, 'internal');
            expect(wrapper.get('[role="dialog"]').text()).toContain(workflowLabels.medicine_posology);
            await confirm(wrapper);
            expect(JSON.parse(fetch.mock.calls[0][1].body).markup_by_workflow).toEqual({ medicine_posology: 8 });
        });

        it('média vem de workflow_usage e mostra ausência de uso nas duas abas', async () => {
            const wrapper = render({ workflow_usage: workflowUsage });
            const empty = panel(wrapper, 'billing').get(
                '[data-workflow="exam_assistant"] [data-test="workflow-example"]',
            );
            expect(empty.get('[data-test="usage-price"]').text()).toBe('—');
            expect(empty.get('[data-test="usage-runs"]').text()).toBe('sem uso em 30 dias');
            expect(
                panel(wrapper, 'billing')
                    .get('[data-workflow="report_drafting"] [data-test="workflow-example"]')
                    .text(),
            ).toContain('R$ 0,44');
            await showTab(wrapper, 'internal');
            const internal = panel(wrapper, 'internal').get(
                '[data-workflow="medicine_posology"] [data-test="workflow-example"]',
            );
            expect(internal.get('[data-test="usage-price"]').text()).toBe('—');
            expect(internal.get('[data-test="usage-runs"]').text()).toBe('sem uso em 30 dias');
        });
    });

    it('renderiza props, pacotes somente leitura e histórico sem ações de edição', async () => {
        const wrapper = render();
        await showTab(wrapper, 'billing');
        expect(wrapper.get('h4').text()).toBe('Precificação de IA');
        expect(wrapper.get('#pricing-markup_multiplier').element.value).toBe('5');
        expect(wrapper.get('#pricing-workflow-0').element.value).toBe('0,10');
        expect(wrapper.text()).toContain('Básico');
        expect(wrapper.text().replace(/\s/g, '')).toContain('R$100,00');
        await showTab(wrapper, 'rate');
        expect(wrapper.get('[data-test="history-row"]').text()).toContain('Anterior');
        expect(wrapper.get('[data-test="history-row"]').find('button,input').exists()).toBe(false);
        expect(wrapper.find('[data-test="fallback"]').exists()).toBe(false);
    });

    it('avisa sobre cotação de contingência', () => {
        const wrapper = render({ rate: { ...rate, source: 'fallback', is_fallback: true } });
        expect(wrapper.get('[data-test="fallback"]').attributes('role')).toBe('alert');
        expect(wrapper.get('[data-test="fallback"]').text()).toBe('Defina a cotação.');
    });

    it('só salva markup após confirmação com motivo e usa a resposta efetiva', async () => {
        const wrapper = render();
        respond({ message: 'Regras salvas.', settings: { ...settings, markup_multiplier: 6 } });
        await wrapper.get('#pricing-markup_multiplier').setValue(6);
        await review(wrapper, 'billing');
        expect(fetch).not.toHaveBeenCalled();
        expect(wrapper.get('[role="dialog"]').text()).toContain('Confirmar regras');
        await confirm(wrapper, 'curto');
        expect(fetch).not.toHaveBeenCalled();
        expect(wrapper.get('#pricing-reason').attributes('aria-invalid')).toBe('true');
        await confirm(wrapper);
        const [url, request] = fetch.mock.calls[0];
        expect(url).toBe('/pricing/settings');
        expect(request.method).toBe('PUT');
        expect(request.headers['X-CSRF-TOKEN']).toBe('test-csrf-token');
        expect(JSON.parse(request.body)).toEqual({
            ...settings,
            custom_step_cents: undefined,
            limits: undefined,
            internal_workflows: undefined,
            markup_multiplier: 6,
            reason: 'Revisão mensal dos valores',
        });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.get('[role="status"]').text()).toBe('Regras salvas.');
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['examples', 'workflow_examples', 'workflow_usage', 'packages', 'history', 'rate'],
            }),
        );
        expect(wrapper.find('[data-test="example"]').exists()).toBe(false);
    });

    it.each([
        ['markup_multiplier', '#pricing-markup_multiplier'],
        ['minimum_charge_cents_default', '#pricing-minimum_charge_cents_default'],
        ['minimum_charge_cents_by_workflow', '#pricing-workflow-0'],
        ['custom_min_cents', '#pricing-custom_min_cents'],
        ['custom_max_cents', '#pricing-custom_max_cents'],
        ['custom_limits', '#pricing-custom_min_cents'],
    ])('associa 422 de domínio %s ao campo', async (field, selector) => {
        const wrapper = render();
        respond({ message: 'Valor fora dos limites.', field }, 422);
        await review(wrapper, 'billing');
        await confirm(wrapper);
        expect(wrapper.get(selector).attributes('aria-invalid')).toBe('true');
        const ids = wrapper.get(selector).attributes('aria-describedby').split(' ');
        expect(ids.map((id) => wrapper.get(`#${id}`).text()).join(' ')).toContain('Valor fora dos limites.');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.get(selector).element);
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('mantém erros Laravel de motivo no diálogo e permite corrigir', async () => {
        const wrapper = render();
        respond({ message: 'Validação', errors: { reason: ['Motivo inválido no servidor.'] } }, 422);
        await review(wrapper, 'billing');
        await confirm(wrapper);
        expect(wrapper.get('[role="dialog"]').text()).toContain('Motivo inválido no servidor.');
        expect(wrapper.get('#pricing-reason').attributes('aria-describedby')).toContain('pricing-reason-error');
    });

    it('registra câmbio com vigência ISO, atualiza cotação e acrescenta histórico', async () => {
        const wrapper = render({ rate: { ...rate, is_fallback: true } });
        respond(
            {
                message: 'Cotação registrada.',
                rate: { ...rate, rate: 6 },
                history_first: {
                    id: 'new',
                    source: 'manual',
                    rate_brl_per_usd: 6,
                    effective_at: rate.effective_at,
                    recorded_by_name: 'Admin',
                    note: 'Cotação revisada',
                },
            },
            201,
        );
        await showTab(wrapper, 'rate');
        await wrapper.get('#pricing-rate').setValue(6);
        await showTab(wrapper, 'rate');
        await wrapper.get('#pricing-effective').setValue('2026-10-23T09:30');
        await showTab(wrapper, 'rate');
        await wrapper.get('#pricing-note').setValue('Cotação revisada');
        await showTab(wrapper, 'rate');
        await wrapper.get('[data-test="rate-form"]').trigger('submit');
        expect(fetch).not.toHaveBeenCalled();
        await confirm(wrapper);
        const [url, request] = fetch.mock.calls[0];
        expect(url).toBe('/pricing/rates');
        expect(request.method).toBe('POST');
        expect(JSON.parse(request.body)).toEqual({
            rate_brl_per_usd: 6,
            effective_at: new Date('2026-10-23T09:30').toISOString(),
            note: 'Cotação revisada',
            reason: 'Revisão mensal dos valores',
        });
        expect(wrapper.get('[data-test="current-rate"]').text()).toContain('6');
        expect(wrapper.find('[data-test="fallback"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(2);
        expect(wrapper.findAll('[data-test="history-row"]')[0].text()).toContain('Cotação revisada');
        expect(wrapper.findAll('[data-test="history-row"]')[1].text()).toContain('Anterior');
        expect(wrapper.get('#pricing-note').element.value).toBe('');
    });

    it('associa 422 da cotação ao input', async () => {
        const wrapper = render();
        respond({ message: 'Cotação inválida.', field: 'rate_brl_per_usd' }, 422);
        await showTab(wrapper, 'rate');
        await wrapper.get('[data-test="rate-form"]').trigger('submit');
        await confirm(wrapper);
        expect(wrapper.get('#pricing-rate').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#pricing-rate-error').text()).toBe('Cotação inválida.');
    });

    it('foca o formulário enviado mesmo quando o outro ainda tem erro', async () => {
        const wrapper = render();
        respond({ message: 'Markup inválido.', field: 'markup_multiplier' }, 422);
        await review(wrapper, 'billing');
        await confirm(wrapper);
        respond({ message: 'Cotação inválida.', field: 'rate_brl_per_usd' }, 422);
        await showTab(wrapper, 'rate');
        await wrapper.get('[data-test="rate-form"]').trigger('submit');
        await confirm(wrapper);
        expect(document.activeElement).toBe(wrapper.get('#pricing-rate').element);
    });

    it('renderiza a conta com custo USD, câmbio, markup, resultado e piso', () => {
        const wrapper = render();
        const examples = wrapper.findAll('[data-test="example"]');
        expect(examples[0].findAll('td')).toHaveLength(2);
        expect(examples[0].text()).toContain('US$ 1');
        expect(examples[0].get('td[title]').attributes('title')).toMatch(/Câmbio 5,5 × Markup 5 = R\$ 27,50/);
        expect(examples[1].text()).toContain('US$ 0,001');
        expect(examples[1].text()).toContain('R$ 0,10');
        expect(examples[1].text()).toContain('Mínimo aplicado');
    });

    it('cancela por Escape e devolve o foco sem enviar', async () => {
        const wrapper = render();
        panel(wrapper, 'billing').get('[data-test="settings-review"]').element.focus();
        await review(wrapper, 'billing');
        await flushPromises();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(document.activeElement).toBe(panel(wrapper, 'billing').get('[data-test="settings-review"]').element);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('impede envio duplicado e mantém a confirmação durante a requisição', async () => {
        const wrapper = render();
        fetch.mockReturnValue(new Promise(() => {}));
        await review(wrapper, 'billing');
        await wrapper.get('#pricing-reason').setValue('Revisão mensal dos valores');
        await wrapper.get('#pricing-confirm-form').trigger('submit');
        await wrapper.get('#pricing-confirm-form').trigger('submit');
        expect(fetch).toHaveBeenCalledTimes(1);
        await wrapper.get('.btn-close').trigger('click');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    });

    describe('lote 2: câmbio automático e markup por ação', () => {
        it('mostra fonte, idade, badge automática e a conta das 9 ações (internas não cobradas) sem buscar câmbio ao carregar', async () => {
            const wrapper = render({ rate: { ...autoRate }, workflow_usage: workflowUsage });
            await showTab(wrapper, 'rate');
            const card = wrapper.get('[data-test="rate-card"]').text();
            expect(card).toContain('Frankfurter (BCE)');
            expect(card).toContain('há 1 dia');
            expect(card).toContain('Buscada em');
            expect(wrapper.get('[data-test="badge-automatic"]').text()).toBe('Automática');
            expect(wrapper.find('[data-test="badge-manual-override"]').exists()).toBe(false);
            await showTab(wrapper, 'billing');
            const rows = wrapper.findAll('[data-test="workflow-example"]');
            expect(rows).toHaveLength(9);
            expect(panel(wrapper, 'billing').get('[data-workflow="report_drafting"]').text()).toContain(
                'Rascunho de laudo',
            );
            expect(rows[1].text()).toContain('R$ 0,44');
            await showTab(wrapper, 'internal');
            expect(panel(wrapper, 'internal').findAll('[data-test="markup-row"]')).toHaveLength(3);
            expect(panel(wrapper, 'internal').get('#internal-hint').text()).toBe(t.internal_hint);
            expect(panel(wrapper, 'internal').findAll('th[scope="row"] .badge')).toHaveLength(0);
            expect(fetch).not.toHaveBeenCalled();
        });

        it('marca a manual que sobrepõe a automática de hoje e mostra "hoje" na idade', async () => {
            const wrapper = render({ rate: { ...rate, fetched_today: true, is_manual_override: true } });
            await showTab(wrapper, 'rate');
            expect(wrapper.get('[data-test="badge-manual-override"]').text()).toBe(
                'Manual sobrepõe a automática de hoje',
            );
            expect(wrapper.get('[data-test="rate-card"]').text()).toContain('hoje');
        });

        it('"Buscar agora" fica desabilitado quando a automática de hoje já foi buscada e habilitado na contingência', async () => {
            const fetched = render({ rate: { ...autoRate } });
            await showTab(fetched, 'rate');
            expect(fetched.get('[data-test="fetch-now"]').attributes('disabled')).toBeDefined();
            expect(fetched.get('[data-test="fetch-now"]').attributes('type')).toBe('button');
            expect(fetched.text()).toContain('A automática de hoje já foi buscada');

            const fallback = render({ rate: { ...rate, source: 'fallback', is_fallback: true } });
            await showTab(fallback, 'rate');
            expect(fallback.get('[data-test="fetch-now"]').attributes('disabled')).toBeUndefined();
        });

        it('"Buscar agora" exige motivo e, no 201, atualiza a cotação, prepende o histórico e recarrega a conta', async () => {
            const wrapper = render();
            respond(
                {
                    message: 'Cotação automática registrada.',
                    rate: { ...autoRate },
                    history_first: {
                        id: 'auto',
                        source: 'frankfurter',
                        rate_brl_per_usd: 5.4321,
                        effective_at: autoRate.effective_at,
                        fetched_at: autoRate.fetched_at,
                        recorded_by_name: null,
                        note: 'Frankfurter/BCE',
                    },
                },
                201,
            );
            await showTab(wrapper, 'rate');
            await wrapper.get('[data-test="fetch-now"]').trigger('click');
            expect(fetch).not.toHaveBeenCalled();
            expect(wrapper.get('[role="dialog"]').text()).toContain('Confirmar busca da cotação automática');
            await confirm(wrapper, 'curto');
            expect(fetch).not.toHaveBeenCalled();
            await confirm(wrapper, 'Primeira cotação do dia');
            const [url, request] = fetch.mock.calls[0];
            expect(url).toBe('/pricing/rates/fetch');
            expect(request.method).toBe('POST');
            expect(JSON.parse(request.body)).toEqual({ reason: 'Primeira cotação do dia' });
            expect(wrapper.get('[data-test="current-rate"]').text().replace(/\s/g, '')).toContain('5,4321');
            const rows = wrapper.findAll('[data-test="history-row"]');
            expect(rows).toHaveLength(2);
            expect(rows[0].text()).toContain('Frankfurter (BCE)');
            expect(wrapper.get('[role="status"]').text()).toBe('Cotação automática registrada.');
            expect(wrapper.get('[data-test="fetch-now"]').attributes('disabled')).toBeDefined();
            expect(router.reload).toHaveBeenCalledWith(
                expect.objectContaining({
                    only: ['examples', 'workflow_examples', 'workflow_usage', 'packages', 'history', 'rate'],
                }),
            );
        });

        it('"Buscar agora" repetido (200 already_fetched) mostra o aviso e não duplica o histórico', async () => {
            const wrapper = render();
            respond(
                { message: 'A automática de hoje já foi buscada.', rate: { ...autoRate }, already_fetched: true },
                200,
            );
            await showTab(wrapper, 'rate');
            await wrapper.get('[data-test="fetch-now"]').trigger('click');
            await confirm(wrapper, 'Tentativa repetida no dia');
            expect(wrapper.get('[role="status"]').text()).toBe('A automática de hoje já foi buscada.');
            expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(1);
            expect(wrapper.get('[data-test="fetch-now"]').attributes('disabled')).toBeDefined();
        });

        it('"Buscar agora" com a API fora (502) mostra o erro e mantém a cotação', async () => {
            const wrapper = render();
            respond({ message: 'Não foi possível buscar a cotação agora.', rate: { ...rate } }, 502);
            await showTab(wrapper, 'rate');
            await wrapper.get('[data-test="fetch-now"]').trigger('click');
            await confirm(wrapper, 'Tentativa com API fora');
            expect(wrapper.get('[data-test="fetch-error"]').text()).toBe('Não foi possível buscar a cotação agora.');
            expect(wrapper.get('[data-test="fetch-error"]').attributes('role')).toBe('alert');
            expect(wrapper.get('[data-test="current-rate"]').text()).toContain('5,5');
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
            expect(router.reload).not.toHaveBeenCalled();
        });

        it.each([201, 200])('Buscar agora (%s) preserva vigência e nota do rascunho manual', async (status) => {
            const wrapper = render();
            const effectiveAt = '2026-10-30T09:30';
            const note = 'Cotação manual para a próxima semana';
            await showTab(wrapper, 'rate');
            await wrapper.get('#pricing-effective').setValue(effectiveAt);
            await showTab(wrapper, 'rate');
            await wrapper.get('#pricing-note').setValue(note);
            respond({ message: 'Cotação automática consultada.', rate: { ...autoRate } }, status);

            await showTab(wrapper, 'rate');
            await wrapper.get('[data-test="fetch-now"]').trigger('click');
            await confirm(wrapper, 'Consultar automática antes de revisar');

            expect(wrapper.get('#pricing-effective').element.value).toBe(effectiveAt);
            expect(wrapper.get('#pricing-note').element.value).toBe(note);
            await showTab(wrapper, 'rate');
            await wrapper.get('[data-test="rate-form"]').trigger('submit');
            await confirm(wrapper, 'Registrar a cotação da próxima semana');
            expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({
                effective_at: new Date(effectiveAt).toISOString(),
                note,
            });
        });

        it('cada checkbox de padrão global tem nome acessível com a ação, sem mudar o label visível', async () => {
            const wrapper = render();
            const names = [];
            for (const [index, workflow] of WORKFLOWS.entries()) {
                await showTab(wrapper, INTERNAL.includes(workflow) ? 'internal' : 'billing');
                const checkbox = wrapper.get(`#pricing-markup-default-${index}`);
                const labelledBy = checkbox.attributes('aria-labelledby');
                const name =
                    checkbox.attributes('aria-label') ??
                    (labelledBy
                        ? labelledBy
                              .split(/\s+/)
                              .map((id) => wrapper.get(`#${id}`).text())
                              .join(' ')
                        : Array.from(checkbox.element.labels)
                              .map((label) => label.textContent)
                              .join(' '));
                expect(name).toContain(workflowLabels[workflow]);
                expect(name).toContain(t.markup_use_default);
                expect(wrapper.get(`label[for="pricing-markup-default-${index}"]`).text()).toBe(t.markup_use_default);
                names.push(name);
            }
            expect(names[1]).toContain('Rascunho de laudo');
            expect(new Set(names).size).toBe(9);
        });

        it('Buscar agora com 409 mostra conflito, preserva cotação e histórico e fecha o modal', async () => {
            const wrapper = render();
            const currentRateText = wrapper.get('[data-test="current-rate"]').text();
            const historyText = wrapper.get('[data-test="history-row"]').text();
            respond({ message: 'Já existe uma busca em andamento.', rate: { ...rate } }, 409);

            await showTab(wrapper, 'rate');
            await wrapper.get('[data-test="fetch-now"]').trigger('click');
            await confirm(wrapper, 'Consultar a cotação atual');

            expect(wrapper.get('[data-test="fetch-error"]').text()).toBe('Já existe uma busca em andamento.');
            expect(wrapper.get('[data-test="fetch-error"]').attributes('role')).toBe('alert');
            expect(wrapper.get('[data-test="current-rate"]').text()).toBe(currentRateText);
            expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(1);
            expect(wrapper.get('[data-test="history-row"]').text()).toBe(historyText);
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
            expect(wrapper.get('[data-test="fetch-now"]').attributes('disabled')).toBeUndefined();
            expect(router.reload).not.toHaveBeenCalled();
        });

        it('Buscar agora com 422 de motivo mantém o erro no diálogo associado ao campo e permite corrigir', async () => {
            const wrapper = render();
            respond({ message: 'Validação', errors: { reason: ['Explique melhor o motivo da busca.'] } }, 422);

            await showTab(wrapper, 'rate');
            await wrapper.get('[data-test="fetch-now"]').trigger('click');
            await confirm(wrapper, 'Consultar a cotação atual');

            expect(fetch.mock.calls[0][0]).toBe('/pricing/rates/fetch');
            expect(wrapper.get('[role="dialog"]').text()).toContain('Explique melhor o motivo da busca.');
            expect(wrapper.get('#pricing-reason').attributes('aria-invalid')).toBe('true');
            const ids = wrapper.get('#pricing-reason').attributes('aria-describedby').split(' ');
            expect(ids.map((id) => wrapper.get(`#${id}`).text()).join(' ')).toContain(
                'Explique melhor o motivo da busca.',
            );
            expect(wrapper.find('[data-test="fetch-error"]').exists()).toBe(false);
            expect(router.reload).not.toHaveBeenCalled();

            respond({ message: 'Cotação registrada.', rate: { ...autoRate } }, 201);
            await confirm(wrapper, 'Motivo revisado para consultar a cotação');
            expect(fetch).toHaveBeenCalledTimes(2);
            expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({
                reason: 'Motivo revisado para consultar a cotação',
            });
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        });

        it('markup por ação: desmarcar o padrão cria override com o global, marcar remove, o payload envia só overrides e a confirmação lista', async () => {
            const wrapper = render();
            expect(wrapper.findAll('[data-test="markup-row"]')).toHaveLength(9);
            expect(wrapper.get('#pricing-markup-1').attributes('disabled')).toBeDefined();
            expect(wrapper.get('[data-test="markup-row"]:nth-of-type(2)').text()).toContain('Efetivo');

            await wrapper.get('#pricing-markup-default-1').setValue(false);
            expect(wrapper.get('#pricing-markup-1').attributes('disabled')).toBeUndefined();
            expect(wrapper.get('#pricing-markup-1').element.value).toBe('5');
            await wrapper.get('#pricing-markup-1').setValue(8);
            await showTab(wrapper, 'internal');
            await wrapper.get('#pricing-markup-default-6').setValue(false);
            await wrapper.get('#pricing-markup-default-6').setValue(true);

            respond({
                message: 'Regras salvas.',
                settings: { ...settings, markup_by_workflow: { report_drafting: 8 } },
            });
            await review(wrapper, 'billing');
            const dialog = wrapper.get('[role="dialog"]').text();
            expect(dialog).toContain('Rascunho de laudo');
            expect(dialog).toContain('8');
            expect(dialog).toContain('Padrão global');
            await confirm(wrapper);
            expect(JSON.parse(fetch.mock.calls[0][1].body).markup_by_workflow).toEqual({ report_drafting: 8 });
            expect(wrapper.get('#pricing-markup-1').element.value).toBe('8');

            await showTab(wrapper, 'billing');
            await wrapper.get('#pricing-markup-default-1').setValue(true);
            await review(wrapper, 'billing');
            await confirm(wrapper);
            expect(JSON.parse(fetch.mock.calls[1][1].body).markup_by_workflow).toEqual({});
        });

        it('422 de markup_by_workflow destaca o fieldset, associa a mensagem e foca o override', async () => {
            const wrapper = render();
            await wrapper.get('#pricing-markup-default-1').setValue(false);
            respond({ message: 'Markup por ação inválido.', field: 'markup_by_workflow' }, 422);
            await review(wrapper, 'billing');
            await confirm(wrapper);
            expect(wrapper.get('#pricing-markup-1').attributes('aria-invalid')).toBe('true');
            const ids = wrapper.get('#pricing-markup-1').attributes('aria-describedby').split(' ');
            expect(ids.map((id) => wrapper.get(`#${id}`).text()).join(' ')).toContain('Markup por ação inválido.');
            expect(document.activeElement).toBe(wrapper.get('#pricing-markup-1').element);
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        });

        it('o ladder usa o markup efetivo do exemplo, não o global', () => {
            const wrapper = render({
                examples: [
                    {
                        raw_cost_usd: 1,
                        exchange_rate_brl_usd: 5.5,
                        markup_multiplier: 8,
                        charge_cents: 4400,
                        charge_formatted: 'R$ 44,00',
                        minimum_applied: false,
                    },
                ],
            });
            expect(wrapper.get('[data-test="example"] td[title]').attributes('title')).toMatch(
                /Câmbio 5,5 × Markup 8 = R\$ 44,00/,
            );
        });
    });
});
