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

const settings = {
    markup_multiplier: 5,
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
    recorded_by_name: 'Admin',
    note: null,
    is_fallback: false,
};
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
    workflows: { exam_assistant: 'Assistente de exames' },
    sources: { manual: 'Manual', fallback: 'Contingência' },
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
                { raw_cost_usd: 1, charge_cents: 2750, charge_formatted: 'R$ 27,50', minimum_applied: false },
                { raw_cost_usd: 0.001, charge_cents: 10, charge_formatted: 'R$ 0,10', minimum_applied: true },
            ],
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
            urls: { settings: '/pricing/settings', exchange_rates: '/pricing/rates' },
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
            markup_multiplier: 6,
            reason: 'Revisão mensal dos valores',
        });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.get('[role="status"]').text()).toBe('Regras salvas.');
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['examples', 'packages', 'history', 'rate'] }),
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
});
