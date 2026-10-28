import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import NoSubscriptionDrawer from '@/Pages/Panel/Manager/Subscriptions/NoSubscriptionDrawer.vue';
import Index from '@/Pages/Panel/Manager/Subscriptions/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { on: vi.fn(() => () => {}), get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

/**
 * Card "Sem assinatura" do manager: antes abria o mesmo modal do botão
 * "Nova assinatura" (com TODAS as empresas). Agora mostra QUAIS empresas
 * estão sem assinatura e cria a de cada uma; com 0, só informa.
 */
const t = {
    loading: 'Carregando...',
    summary_no_subscription: 'Sem assinatura',
    summary_no_sub_hint: 'Ver quais empresas clientes estão sem assinatura',
    summary_no_sub_none: 'Todas as empresas clientes têm assinatura.',
    no_sub_drawer_title: 'Empresas sem assinatura',
    no_sub_drawer_hint: 'Empresas clientes que nunca tiveram assinatura',
    no_sub_drawer_empty: 'Nenhuma empresa cliente sem assinatura.',
    no_sub_drawer_error: 'Não foi possível carregar as empresas.',
    no_sub_drawer_retry: 'Tentar de novo',
    no_sub_drawer_since: 'Cadastrada em',
    no_sub_drawer_inactive: 'Inativa',
    no_sub_drawer_create: 'Criar assinatura',
    no_sub_drawer_more: 'Mostrando :shown de :total.',
};

const company = (overrides = {}) => ({
    id: 'ent-1',
    name: 'Clínica Nova',
    sub_label: '12.345.678/0001-90',
    active: true,
    created_at: '2026-10-01',
    current: null,
    ...overrides,
});

afterEach(() => {
    delete window.axios;
});

function mountDrawer(props = {}) {
    return mount(NoSubscriptionDrawer, {
        props: { open: false, total: 1, t, ...props },
        global: {
            stubs: {
                OffcanvasPanel: { props: ['open'], template: '<div v-if="open"><slot name="header" /><slot /></div>' },
            },
        },
    });
}

describe('Gaveta "Empresas sem assinatura"', () => {
    it('ao abrir, busca só as empresas sem assinatura e cria a assinatura da escolhida', async () => {
        window.axios = {
            get: vi.fn().mockResolvedValue({
                data: { data: [company(), company({ id: 'ent-2', name: 'Ótica Sul', active: false })] },
            }),
        };
        const wrapper = mountDrawer({ total: 2 });

        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledWith(
            '/_routes/manager.subscriptions.entities?without_subscription=1',
            expect.anything(),
        );
        const rows = wrapper.findAll('[data-test="no-sub-company"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Clínica Nova');
        expect(rows[0].text()).toContain('01/10/2026');
        expect(rows[1].text()).toContain('Inativa');
        expect(wrapper.find('[data-test="no-sub-more"]').exists()).toBe(false);

        await rows[1].find('[data-test="no-sub-create"]').trigger('click');
        expect(wrapper.emitted('create')).toEqual([['ent-2']]);
    });

    it('mais empresas do que as listadas: avisa quantas faltam', async () => {
        window.axios = { get: vi.fn().mockResolvedValue({ data: { data: [company()] } }) };
        const wrapper = mountDrawer({ total: 80 });

        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.find('[data-test="no-sub-more"]').text()).toBe('Mostrando 1 de 80.');
    });

    it('falha ao carregar: mensagem e "Tentar de novo"', async () => {
        window.axios = {
            get: vi
                .fn()
                .mockRejectedValueOnce(new Error('rede'))
                .mockResolvedValueOnce({ data: { data: [] } }),
        };
        const wrapper = mountDrawer();

        await wrapper.setProps({ open: true });
        await flushPromises();
        expect(wrapper.text()).toContain('Não foi possível carregar as empresas.');

        await wrapper.find('[data-test="no-sub-retry"]').trigger('click');
        await flushPromises();
        expect(window.axios.get).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-test="no-sub-empty"]').exists()).toBe(true);
    });
});

describe('Card "Sem assinatura" na tela de Assinaturas', () => {
    const stubs = {
        AppLayout: { template: '<div><slot /></div>' },
        PageHeader: { template: '<div><slot name="actions" /></div>' },
        ManagerBillingNav: true,
        SearchInput: true,
        SubscriptionTable: true,
        SubscriptionCards: true,
        SubscriptionDetailDrawer: true,
        SubscriptionExtendModal: true,
        SubscriptionTermsModal: true,
        ConfirmationWithReasonModal: true,
        SubscriptionCreateModal: {
            props: ['open', 'preset'],
            template: '<div data-test="create-modal" :data-open="open" :data-entity="preset?.entity_id" />',
        },
        NoSubscriptionDrawer: {
            props: ['open'],
            emits: ['create'],
            template:
                '<div data-test="drawer" :data-open="open"><button data-test="drawer-create" @click="$emit(\'create\', \'ent-9\')" /></div>',
        },
    };

    const mountIndex = (noSubscription) =>
        mount(Index, {
            props: { subscriptions: { data: [] }, summary: { no_subscription: noSubscription }, t },
            global: { stubs },
        });

    it('com empresas sem assinatura: abre a lista (não o modal) e "Criar assinatura" abre o modal já com a empresa', async () => {
        const wrapper = mountIndex(3);
        const card = wrapper.find('[data-summary="no_subscription"]');

        expect(card.element.tagName).toBe('BUTTON');
        await card.trigger('click');

        expect(wrapper.find('[data-test="drawer"]').attributes('data-open')).toBe('true');
        expect(wrapper.find('[data-test="create-modal"]').attributes('data-open')).toBe('false');

        await wrapper.find('[data-test="drawer-create"]').trigger('click');

        expect(wrapper.find('[data-test="drawer"]').attributes('data-open')).toBe('false');
        expect(wrapper.find('[data-test="create-modal"]').attributes('data-open')).toBe('true');
        expect(wrapper.find('[data-test="create-modal"]').attributes('data-entity')).toBe('ent-9');
    });

    it('cada card do resumo tem uma cor diferente e o selecionado fica destacado na cor dele', async () => {
        const wrapper = mount(Index, {
            props: {
                subscriptions: { data: [] },
                summary: {
                    trial: 2,
                    gateway: 4,
                    complimentary: 3,
                    past_due: 0,
                    awaiting_payment: 1,
                    without_access: 0,
                    needs_review: 1,
                    recurrence_alert: 1,
                    no_subscription: 0,
                },
                filters: { status: 'awaiting_payment' },
                t,
            },
            global: { stubs },
        });
        const keys = [
            'trial',
            'gateway',
            'complimentary',
            'past_due',
            'awaiting_payment',
            'without_access',
            'needs_review',
            'recurrence_alert',
            'no_subscription',
        ];
        const tone = (key) =>
            wrapper
                .find(`[data-summary="${key}"]`)
                .classes()
                .find((c) => c.startsWith('kpi-card--tone-'));
        const tones = keys.map(tone);

        expect(tones).toEqual([
            'kpi-card--tone-purple',
            'kpi-card--tone-primary',
            'kpi-card--tone-success',
            'kpi-card--tone-warning',
            'kpi-card--tone-orange',
            'kpi-card--tone-danger',
            'kpi-card--tone-indigo',
            'kpi-card--tone-pink',
            'kpi-card--tone-cyan',
        ]);
        expect(new Set(tones).size).toBe(keys.length);

        const awaiting = wrapper.find('[data-summary="awaiting_payment"]');
        expect(awaiting.attributes('aria-pressed')).toBe('true');
        expect(awaiting.classes()).toContain('kpi-card--tinted-active');
        expect(wrapper.find('[data-summary="trial"]').attributes('aria-pressed')).toBe('false');
        expect(awaiting.text()).toContain('1');
    });

    it('com 0: só informa (não é botão, não abre nada)', async () => {
        const wrapper = mountIndex(0);
        const card = wrapper.find('[data-summary="no_subscription"]');

        expect(card.element.tagName).toBe('DIV');
        expect(card.attributes('title')).toBe(t.summary_no_sub_none);
        await card.trigger('click');

        expect(wrapper.find('[data-test="drawer"]').attributes('data-open')).toBe('false');
        expect(wrapper.find('[data-test="create-modal"]').attributes('data-open')).toBe('false');
    });
});
