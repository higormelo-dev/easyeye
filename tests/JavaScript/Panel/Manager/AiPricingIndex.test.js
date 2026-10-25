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
    minimum_charge_cents_by_workflow: { exam_assistant: 10 },
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
const t = {
    title: 'Precificação de IA',
    subtitle: 'Regras',
    breadcrumb_home: 'Dashboard',
    settings_title: 'Regras de cobrança',
    fields: {
        markup_multiplier: 'Multiplicador',
        minimum_charge_cents_default: 'Piso padrão',
        custom_min_cents: 'Mínimo',
        custom_max_cents: 'Máximo',
        rate_brl_per_usd: 'Nova cotação',
    },
    hints: {},
    workflows: workflowLabels,
    sources: { manual: 'Manual', fallback: 'Contingência', frankfurter: 'Frankfurter (BCE)' },
    fetched_at: 'Buscada em',
    age: 'Idade',
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
    markup_workflow_hint: 'Cada ação usa o global salvo override.',
    markup_use_default: 'Usar padrão global',
    markup_global_default: 'Padrão global',
    markup_effective: 'Efetivo',
    workflow_examples_title: 'A conta por ação',
    workflow_examples_hint: 'US$ 0,01 em cada ação.',
    internal_not_charged: 'Interno — não cobrado',
    action: 'Ação',
    price: 'Preço',
    minimum: 'Piso',
    fallback_warning: 'Defina a cotação.',
    rate_title: 'Registrar cotação',
    current_rate: 'Cotação atual',
    source: 'Origem',
    effective_at: 'Vigência',
    recorded_by: 'Registrado por',
    note: 'Observação',
    examples_title: 'A conta',
    provider_cost: 'Custo do provedor',
    exchange_rate: 'Câmbio',
    markup: 'Markup',
    minimum_applied: 'Piso aplicado',
    examples_refresh: 'Atualizando exemplos',
    packages_title: 'Pacotes',
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
function respond(body, status = 200) {
    fetch.mockResolvedValue({ ok: status < 400, status, json: async () => body });
}
async function confirm(wrapper, reason = 'Revisão mensal dos valores') {
    await wrapper.get('#pricing-reason').setValue(reason);
    await wrapper.get('#pricing-confirm-form').trigger('submit');
    await flushPromises();
}

beforeEach(() => {
    vi.clearAllMocks();
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('Manager AI pricing', () => {
    it('renderiza props, pacotes somente leitura e histórico sem ações de edição', () => {
        const wrapper = render();
        expect(wrapper.get('h4').text()).toBe('Precificação de IA');
        expect(wrapper.get('#pricing-markup_multiplier').element.value).toBe('5');
        expect(wrapper.get('#pricing-workflow-0').element.value).toBe('10');
        expect(wrapper.text()).toContain('Básico');
        expect(wrapper.text().replace(/\s/g, '')).toContain('R$100,00');
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
        await wrapper.get('[data-test="settings-form"]').trigger('submit');
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
            expect.objectContaining({ only: ['examples', 'workflow_examples', 'packages', 'history', 'rate'] }),
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
        await wrapper.get('[data-test="settings-form"]').trigger('submit');
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
        await wrapper.get('[data-test="settings-form"]').trigger('submit');
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
        await wrapper.get('#pricing-rate').setValue(6);
        await wrapper.get('#pricing-effective').setValue('2026-10-23T09:30');
        await wrapper.get('#pricing-note').setValue('Cotação revisada');
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
        await wrapper.get('[data-test="rate-form"]').trigger('submit');
        await confirm(wrapper);
        expect(wrapper.get('#pricing-rate').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#pricing-rate-error').text()).toBe('Cotação inválida.');
    });

    it('foca o formulário enviado mesmo quando o outro ainda tem erro', async () => {
        const wrapper = render();
        respond({ message: 'Markup inválido.', field: 'markup_multiplier' }, 422);
        await wrapper.get('[data-test="settings-form"]').trigger('submit');
        await confirm(wrapper);
        respond({ message: 'Cotação inválida.', field: 'rate_brl_per_usd' }, 422);
        await wrapper.get('[data-test="rate-form"]').trigger('submit');
        await confirm(wrapper);
        expect(document.activeElement).toBe(wrapper.get('#pricing-rate').element);
    });

    it('renderiza a conta com custo USD, câmbio, markup, resultado e piso', () => {
        const wrapper = render();
        const examples = wrapper.findAll('[data-test="example"]');
        expect(examples[0].text()).toMatch(/Custo do provedor US\$ 1 × Câmbio 5,5 × Markup 5 = R\$ 27,50/);
        expect(examples[1].text()).toContain('US$ 0,001');
        expect(examples[1].text()).toContain('R$ 0,10');
        expect(examples[1].text()).toContain('Piso aplicado');
    });

    it('cancela por Escape e devolve o foco sem enviar', async () => {
        const wrapper = render();
        wrapper.get('[data-test="settings-review"]').element.focus();
        await wrapper.get('[data-test="settings-form"]').trigger('submit');
        await flushPromises();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.get('[data-test="settings-review"]').element);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('impede envio duplicado e mantém a confirmação durante a requisição', async () => {
        const wrapper = render();
        fetch.mockReturnValue(new Promise(() => {}));
        await wrapper.get('[data-test="settings-form"]').trigger('submit');
        await wrapper.get('#pricing-reason').setValue('Revisão mensal dos valores');
        await wrapper.get('#pricing-confirm-form').trigger('submit');
        await wrapper.get('#pricing-confirm-form').trigger('submit');
        expect(fetch).toHaveBeenCalledTimes(1);
        await wrapper.get('.btn-close').trigger('click');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    });

    describe('lote 2: câmbio automático e markup por ação', () => {
        it('mostra fonte, idade, badge automática e a conta das 9 ações (internas não cobradas) sem buscar câmbio ao carregar', () => {
            const wrapper = render({ rate: { ...autoRate } });
            const card = wrapper.get('[data-test="rate-card"]').text();
            expect(card).toContain('Frankfurter (BCE)');
            expect(card).toContain('há 1 dia');
            expect(card).toContain('Buscada em');
            expect(wrapper.get('[data-test="badge-automatic"]').text()).toBe('Automática');
            expect(wrapper.find('[data-test="badge-manual-override"]').exists()).toBe(false);
            const rows = wrapper.findAll('[data-test="workflow-example"]');
            expect(rows).toHaveLength(9);
            expect(rows[1].text()).toContain('Rascunho de laudo');
            expect(rows[1].text()).toContain('R$ 0,44');
            expect(rows.filter((row) => row.text().includes('Interno — não cobrado'))).toHaveLength(3);
            expect(rows[6].text()).toContain('Interno — não cobrado');
            expect(fetch).not.toHaveBeenCalled();
        });

        it('marca a manual que sobrepõe a automática de hoje e mostra "hoje" na idade', () => {
            const wrapper = render({ rate: { ...rate, fetched_today: true, is_manual_override: true } });
            expect(wrapper.get('[data-test="badge-manual-override"]').text()).toBe(
                'Manual sobrepõe a automática de hoje',
            );
            expect(wrapper.get('[data-test="rate-card"]').text()).toContain('hoje');
        });

        it('"Buscar agora" fica desabilitado quando a automática de hoje já foi buscada e habilitado na contingência', () => {
            const fetched = render({ rate: { ...autoRate } });
            expect(fetched.get('[data-test="fetch-now"]').attributes('disabled')).toBeDefined();
            expect(fetched.get('[data-test="fetch-now"]').attributes('type')).toBe('button');
            expect(fetched.text()).toContain('A automática de hoje já foi buscada');

            const fallback = render({ rate: { ...rate, source: 'fallback', is_fallback: true } });
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
                expect.objectContaining({ only: ['examples', 'workflow_examples', 'packages', 'history', 'rate'] }),
            );
        });

        it('"Buscar agora" repetido (200 already_fetched) mostra o aviso e não duplica o histórico', async () => {
            const wrapper = render();
            respond(
                { message: 'A automática de hoje já foi buscada.', rate: { ...autoRate }, already_fetched: true },
                200,
            );
            await wrapper.get('[data-test="fetch-now"]').trigger('click');
            await confirm(wrapper, 'Tentativa repetida no dia');
            expect(wrapper.get('[role="status"]').text()).toBe('A automática de hoje já foi buscada.');
            expect(wrapper.findAll('[data-test="history-row"]')).toHaveLength(1);
            expect(wrapper.get('[data-test="fetch-now"]').attributes('disabled')).toBeDefined();
        });

        it('"Buscar agora" com a API fora (502) mostra o erro e mantém a cotação', async () => {
            const wrapper = render();
            respond({ message: 'Não foi possível buscar a cotação agora.', rate: { ...rate } }, 502);
            await wrapper.get('[data-test="fetch-now"]').trigger('click');
            await confirm(wrapper, 'Tentativa com API fora');
            expect(wrapper.get('[data-test="fetch-error"]').text()).toBe('Não foi possível buscar a cotação agora.');
            expect(wrapper.get('[data-test="fetch-error"]').attributes('role')).toBe('alert');
            expect(wrapper.get('[data-test="current-rate"]').text()).toContain('5,5');
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
            expect(router.reload).not.toHaveBeenCalled();
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
            await wrapper.get('#pricing-markup-default-6').setValue(false);
            await wrapper.get('#pricing-markup-default-6').setValue(true);

            respond({
                message: 'Regras salvas.',
                settings: { ...settings, markup_by_workflow: { report_drafting: 8 } },
            });
            await wrapper.get('[data-test="settings-form"]').trigger('submit');
            const dialog = wrapper.get('[role="dialog"]').text();
            expect(dialog).toContain('Rascunho de laudo');
            expect(dialog).toContain('8');
            expect(dialog).toContain('Padrão global');
            await confirm(wrapper);
            expect(JSON.parse(fetch.mock.calls[0][1].body).markup_by_workflow).toEqual({ report_drafting: 8 });
            expect(wrapper.get('#pricing-markup-1').element.value).toBe('8');

            await wrapper.get('#pricing-markup-default-1').setValue(true);
            await wrapper.get('[data-test="settings-form"]').trigger('submit');
            await confirm(wrapper);
            expect(JSON.parse(fetch.mock.calls[1][1].body).markup_by_workflow).toEqual({});
        });

        it('422 de markup_by_workflow destaca o fieldset, associa a mensagem e foca o override', async () => {
            const wrapper = render();
            await wrapper.get('#pricing-markup-default-1').setValue(false);
            respond({ message: 'Markup por ação inválido.', field: 'markup_by_workflow' }, 422);
            await wrapper.get('[data-test="settings-form"]').trigger('submit');
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
            expect(wrapper.get('[data-test="example"]').text()).toMatch(/Câmbio 5,5 × Markup 8 = R\$ 44,00/);
        });
    });
});
