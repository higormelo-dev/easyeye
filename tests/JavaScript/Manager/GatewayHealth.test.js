import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() }, usePage: () => ({ props: {} }) }));

import GatewayCard from '@/Pages/Panel/Manager/Gateways/GatewayCard.vue';

/**
 * Card do gateway: situação do último health check real (billing:gateway-health)
 * e as capacidades novas do Asaas (cartão no ambiente seguro, estorno pelo manager).
 */
const t = {
    caps_title: 'Na EasyEye hoje',
    caps_transparent: 'Sem sair do EasyEye:',
    caps_method: { pix: 'Pix', boleto: 'Boleto', credit_card: 'Cartão' },
    caps_card_link: 'Cartão pelo link',
    caps_hosted_card: 'Cartão no ambiente seguro',
    caps_refund: 'Estorno total pelo sistema',
    caps_refund_partial: 'Estorno total e parcial',
    caps_native_recurrence: 'Recorrência no gateway',
    caps_local_renewal: 'Renovação pelo EasyEye',
    health_title: 'Conexão com a API',
    health_checked_at: 'conferido em :date',
    health_status: { ok: 'Funcionando', auth_error: 'Chave recusada', environment_mismatch: 'Chave de outro ambiente' },
};

function asaas(overrides = {}) {
    return {
        id: 'g-asaas',
        code: 'asaas',
        name: 'Asaas',
        active: true,
        is_default: true,
        priority: 1,
        credentials_label: '1 ativa',
        can_be_default: true,
        capabilities: {
            transparent: ['pix', 'boleto'],
            card_link: true,
            max_installments: null,
            saved_card_renewal: false,
            card_replacement: false,
            native_recurrence: true,
            hosted_card_checkout: true,
            refund: true,
            partial_refund: true,
        },
        health: {
            status: 'ok',
            healthy: true,
            message: 'Asaas respondeu com a chave configurada.',
            checked_at: '2026-10-05T06:10:00-03:00',
        },
        ...overrides,
    };
}

describe('GatewayCard — saúde e capacidades do Asaas', () => {
    it('mostra a conexão funcionando, o cartão no ambiente seguro e o estorno parcial', () => {
        const wrapper = mount(GatewayCard, { props: { gateway: asaas(), t } });

        const health = wrapper.get('[data-test="gateway-health"]');
        expect(health.text()).toContain('Conexão com a API');
        expect(health.text()).toContain('Funcionando');
        expect(health.get('[data-status]').classes()).toContain('badge-soft-success');
        expect(wrapper.get('[data-test="gateway-cap-hosted"]').text()).toBe('Cartão no ambiente seguro');
        expect(wrapper.text()).not.toContain('Cartão pelo link');
        expect(wrapper.get('[data-test="gateway-cap-refund"]').text()).toBe('Estorno total e parcial');
    });

    it('chave recusada aparece em vermelho; sem health check, nada aparece', () => {
        const refused = mount(GatewayCard, {
            props: { gateway: asaas({ health: { status: 'auth_error', healthy: false, message: 'HTTP 401' } }), t },
        });
        expect(refused.get('[data-test="gateway-health"] [data-status]').classes()).toContain('badge-soft-danger');
        expect(refused.get('[data-test="gateway-health"]').text()).toContain('Chave recusada');

        const unknown = mount(GatewayCard, { props: { gateway: asaas({ health: null }), t } });
        expect(unknown.find('[data-test="gateway-health"]').exists()).toBe(false);
    });
});
