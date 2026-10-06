import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() }, usePage: () => ({ props: {} }) }));

import GatewayCard from '@/Pages/Panel/Manager/Gateways/GatewayCard.vue';
import GatewaysIndex from '@/Pages/Panel/Manager/Gateways/Index.vue';

/**
 * Manager → Gateways é só do dono do SaaS: os gateways cobram as clínicas
 * (assinatura e pacotes de IA). Sem "Acesso por Clínica" nem card de
 * "Tenant Payment"; cada card mostra o que o gateway faz hoje na EasyEye.
 */
const t = {
    title: 'Gateways de Pagamento do EasyEye',
    subtitle: 'Gateways da empresa dona do <strong>EasyEye</strong> para cobrar as clínicas.',
    ctx_saas_title: 'Para que servem estes gateways',
    ctx_saas_desc: 'As credenciais são da empresa dona do EasyEye.',
    ctx_saas_badge: 'Credenciais globais do EasyEye',
    btn_credentials: 'Credenciais',
    btn_set_default: 'Definir como Padrão',
    caps_title: 'Na EasyEye hoje',
    caps_transparent: 'Sem sair do EasyEye:',
    caps_method: { pix: 'Pix', boleto: 'Boleto', credit_card: 'Cartão' },
    caps_card_public_key_title: 'Cartão transparente só com a chave pública.',
    caps_link_only: 'Só pelo link do gateway',
    caps_card_link: 'Cartão pelo link',
    caps_installments: 'Até :countx no cartão',
    caps_installments_one: 'Cartão só à vista',
    caps_saved_card: 'Renova no cartão salvo',
    caps_card_replacement: 'Troca de cartão',
    caps_native_recurrence: 'Recorrência no gateway',
    caps_local_renewal: 'Renovação pelo EasyEye',
    empty_state: 'Nenhum gateway cadastrado.',
};

function gateway(overrides = {}) {
    return {
        id: 'g1',
        code: 'mercadopago',
        name: 'Mercado Pago',
        active: true,
        is_default: false,
        priority: 10,
        supports_subscriptions: true,
        supports_one_time_charges: true,
        supports_refunds: true,
        supports_webhooks: true,
        credentials_label: '1 ativa',
        can_be_default: true,
        capabilities: {
            transparent: ['pix', 'boleto', 'credit_card'],
            card_link: false,
            max_installments: 12,
            saved_card_renewal: true,
            card_replacement: true,
            native_recurrence: false,
        },
        ...overrides,
    };
}

let wrapper;
afterEach(() => wrapper?.unmount());

describe('Manager → Gateways — card do gateway', () => {
    it('não tem botão nem linha de acesso por clínica', () => {
        wrapper = mount(GatewayCard, { props: { gateway: gateway(), t } });

        const text = wrapper.text();
        expect(text).not.toMatch(/Acesso por Cl[ií]nica|Cl[ií]nicas com acesso/);
        expect(wrapper.find('.btn-outline-success').exists()).toBe(false);
        expect(wrapper.get('[data-test="gateway-btn-credentials"]').text()).toContain('Credenciais');
        expect(wrapper.vm.$options.emits ?? []).not.toContain('open-entity-access');
    });

    it('o botão Credenciais emite open-credentials', async () => {
        const g = gateway();
        wrapper = mount(GatewayCard, { props: { gateway: g, t } });

        await wrapper.get('[data-test="gateway-btn-credentials"]').trigger('click');

        expect(wrapper.emitted('open-credentials')?.[0]).toEqual([g]);
    });

    it('mostra o que o gateway faz hoje: formas transparentes, parcelas, renovação e troca de cartão', () => {
        wrapper = mount(GatewayCard, { props: { gateway: gateway(), t } });

        const caps = wrapper.get('[data-test="gateway-caps"]');
        expect(caps.text()).toContain('Na EasyEye hoje');
        expect(wrapper.get('[data-test="gateway-cap-method-pix"]').text()).toBe('Pix');
        expect(wrapper.get('[data-test="gateway-cap-method-credit_card"]').attributes('title')).toBe(
            t.caps_card_public_key_title,
        );
        expect(wrapper.get('[data-test="gateway-cap-installments"]').text()).toContain('Até 12x no cartão');
        expect(caps.text()).toContain('Renova no cartão salvo');
        expect(caps.text()).toContain('Troca de cartão');
        expect(wrapper.get('[data-test="gateway-cap-recurrence"]').text()).toContain('Renovação pelo EasyEye');
        expect(caps.text()).not.toContain('Cartão pelo link');
    });

    it('gateway só de link (InfinitePay) e à vista/recorrência nativa', () => {
        wrapper = mount(GatewayCard, {
            props: {
                gateway: gateway({
                    code: 'infinitepay',
                    capabilities: {
                        transparent: [],
                        card_link: true,
                        max_installments: null,
                        saved_card_renewal: false,
                        card_replacement: false,
                        native_recurrence: true,
                    },
                }),
                t,
            },
        });

        expect(wrapper.get('[data-test="gateway-cap-link-only"]').text()).toContain('Só pelo link do gateway');
        expect(wrapper.text()).toContain('Cartão pelo link');
        expect(wrapper.find('[data-test="gateway-cap-installments"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="gateway-cap-recurrence"]').text()).toContain('Recorrência no gateway');
    });

    it('cartão só à vista (Stripe) e sem capacidades quando o gateway não tem classe', async () => {
        wrapper = mount(GatewayCard, {
            props: { gateway: gateway({ capabilities: { ...gateway().capabilities, max_installments: 1 } }), t },
        });
        expect(wrapper.get('[data-test="gateway-cap-installments"]').text()).toContain('Cartão só à vista');

        await wrapper.setProps({ gateway: gateway({ capabilities: null }) });
        expect(wrapper.find('[data-test="gateway-caps"]').exists()).toBe(false);
    });
});

describe('Manager → Gateways — página', () => {
    function renderIndex(gateways = [gateway()]) {
        wrapper = mount(GatewaysIndex, {
            props: { gateways, defaultGateway: null, t },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                    GatewayCredentialsModal: true,
                    GatewayPriorityModal: true,
                    GatewayChangeDefaultModal: true,
                },
            },
        });
    }

    it('um só card de contexto: gateways do EasyEye para cobrar as clínicas, sem "Tenant Payment"', () => {
        renderIndex();

        const ctx = wrapper.get('[data-test="gateways-context"]');
        expect(ctx.text()).toContain('Para que servem estes gateways');
        expect(ctx.text()).toContain('Credenciais globais do EasyEye');
        // Clínica nunca tem gateway: a tela nem fala disso (sem selo "não têm gateway próprio").
        expect(wrapper.find('[data-test="gateways-no-clinic-gateway"]').exists()).toBe(false);
        expect(wrapper.html()).not.toMatch(/Tenant Payment|recebem de pacientes|Acesso por Cl[ií]nica|gateway próprio/);
        expect(wrapper.html()).toContain('Gateways da empresa dona do <strong>EasyEye</strong>');
    });

    it('não monta modal de acesso por clínica', () => {
        renderIndex();

        expect(wrapper.html()).not.toMatch(/entity-access|GatewayEntityAccess/i);
    });

    it('estado vazio vem do i18n', () => {
        renderIndex([]);

        expect(wrapper.text()).toContain('Nenhum gateway cadastrado.');
    });
});
