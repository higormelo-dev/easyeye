import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SubscriptionTable from '@/Pages/Panel/Manager/Subscriptions/SubscriptionTable.vue';
import SubscriptionCards from '@/Pages/Panel/Manager/Subscriptions/SubscriptionCards.vue';
import SubscriptionFunnel from '@/Pages/Panel/ManagerDashboard/SubscriptionFunnel.vue';

// Os cards buscam a página por fetch e recarregam após cada visita do Inertia.
vi.mock('@inertiajs/vue3', () => ({
    router: { on: vi.fn(() => () => {}), get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

/**
 * Manager: a cobrança automática do código anterior aguardando conciliação
 * aparece destacada ("Revisar cobrança", com a explicação no title) na
 * tabela e nos cards; no painel, a contratação aguardando o 1º pagamento
 * tem linha própria no funil, separada de "Em atraso".
 */
const t = {
    action_cancel: 'Cancelar assinatura',
    needs_review_badge: 'Revisar cobrança',
    needs_review_hint: 'Cobrança automática criada antes da régua: acesso liberado até a conciliação.',
    modality: { gateway: 'Cobrança automática' },
    period_until: 'até :date',
    amount_none: 'Sem cobrança',
    amount_per_cycle: ':amount/:period',
};

const row = (overrides = {}) => ({
    id: 'sub-1',
    entity_id: 'ent-1',
    entity_name: 'Clínica Legada',
    entity_active: true,
    plan_id: 'plan-1',
    plan_name: 'Pro',
    status: 'expired',
    status_label: 'Expirada',
    status_badge: 'badge-soft-danger',
    modality: 'gateway',
    billing_mode: 'gateway',
    amount: null,
    is_current: true,
    can_extend: false,
    can_change_terms: true,
    needs_reconciliation: true,
    access_ends_at: '2026-07-10T09:00:00-03:00',
    days_left: -87,
    ...overrides,
});

describe('"Revisar cobrança" no manager', () => {
    it('tabela destaca a linha aguardando conciliação, com a explicação', () => {
        const wrapper = mount(SubscriptionTable, {
            props: { subscriptions: { data: [row(), row({ id: 'sub-2', needs_reconciliation: false })] }, t },
        });

        const badges = wrapper.findAll('[data-test="sub-needs-review"]');

        expect(badges).toHaveLength(1);
        expect(badges[0].text()).toContain('Revisar cobrança');
        expect(badges[0].attributes('title')).toBe(t.needs_review_hint);
    });

    it('cards destacam a linha aguardando conciliação', async () => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                json: () => Promise.resolve({ data: [row()], meta: { current_page: 1, last_page: 1 } }),
            }),
        );

        const wrapper = mount(SubscriptionCards, { props: { cardsUrl: '/manager/subscriptions/cards', t } });
        await flushPromises();

        expect(wrapper.find('[data-test="sub-needs-review"]').text()).toBe('Revisar cobrança');
    });
});

const cancelButtons = (wrapper) => wrapper.findAll('button').filter((b) => b.text().includes(t.action_cancel));

// O menu de ações é teleportado e só existe aberto: aqui, os itens ficam no lugar.
const inlineMenu = { global: { stubs: { ActionDropdown: { template: '<ul><slot /></ul>' } } } };

describe('"Cancelar" alcança a linha aguardando conciliação', () => {
    it('tabela: a vigente marcada (mesmo expirada) tem "Cancelar"; a expirada sem marcação não', () => {
        const wrapper = mount(SubscriptionTable, {
            props: {
                subscriptions: {
                    data: [
                        row({ is_accessible: false }),
                        row({ id: 'sub-2', entity_id: 'ent-2', needs_reconciliation: false, is_accessible: false }),
                    ],
                },
                t,
            },
            ...inlineMenu,
        });

        const rows = wrapper.findAll('tbody tr');

        expect(cancelButtons(rows[0])).toHaveLength(1);
        expect(cancelButtons(rows[1])).toHaveLength(0);
    });

    it('cards: idem, e a régua e o "venceu há" na cor de ênfase (contraste)', async () => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                json: () =>
                    Promise.resolve({
                        data: [
                            row({ is_accessible: false }),
                            row({
                                id: 'sub-2',
                                entity_id: 'ent-2',
                                status: 'past_due',
                                needs_reconciliation: false,
                                is_accessible: false,
                                is_current: false,
                                days_left: -3,
                                days_overdue: 3,
                                dunning_stage_label: 'Acesso limitado',
                            }),
                        ],
                        meta: { current_page: 1, last_page: 1 },
                    }),
            }),
        );

        const wrapper = mount(SubscriptionCards, {
            props: {
                cardsUrl: '/manager/subscriptions/cards',
                t: {
                    ...t,
                    days_overdue: 'Venceu há :days dia|Venceu há :days dias',
                    overdue_for: 'Em atraso há :days dia|Em atraso há :days dias',
                },
            },
            ...inlineMenu,
        });
        await flushPromises();

        const cards = wrapper
            .findAll('.card')
            .filter(
                (c) =>
                    c.find('[data-test="sub-needs-review"]').exists() || c.find('[data-test="sub-dunning"]').exists(),
            );

        expect(cancelButtons(cards[0])).toHaveLength(1);
        expect(cancelButtons(cards[1])).toHaveLength(0);

        const dunning = wrapper.get('[data-test="sub-dunning"]');
        expect(dunning.text()).toBe('Em atraso há 3 dias · Acesso limitado');
        expect(dunning.classes()).toContain('text-danger-emphasis');
        expect(dunning.classes()).not.toContain('text-danger');

        const overdue = cards[1].findAll('dd span').find((el) => el.text() === 'Venceu há 3 dias');
        expect(overdue.classes()).toContain('text-danger-emphasis');
        expect(overdue.classes()).not.toContain('text-danger');
    });
});

describe('funil de assinaturas do painel do manager', () => {
    it('mostra "Aguardando 1º pagamento" separado de "Em atraso", com o texto do idioma', () => {
        const wrapper = mount(SubscriptionFunnel, {
            props: {
                subscriptionKpis: {
                    subscriptionCounts: {
                        active: 4,
                        trial: 2,
                        past_due: 1,
                        awaiting_payment: 3,
                        expired: 0,
                        cancelled: 0,
                    },
                    totalSubscriptions: 10,
                },
                t: {
                    subscription_funnel: 'Funil',
                    actions_total: 'Total',
                    subscription_status: {
                        active: 'Ativo',
                        trial: 'Trial',
                        past_due: 'Em atraso',
                        awaiting_payment: 'Aguardando 1º pagamento',
                        expired: 'Expirado',
                        cancelled: 'Cancelado',
                    },
                },
            },
        });

        const rows = wrapper
            .findAll('.funnel-row')
            .map((r) => [r.find('.badge').text(), r.find('.funnel-count').text()]);

        expect(rows).toContainEqual(['Em atraso', '1']);
        expect(rows).toContainEqual(['Aguardando 1º pagamento', '3']);
    });

    it('mostra "Revisar cobrança" (cobrança do código anterior aguardando conciliação) em linha própria', () => {
        const wrapper = mount(SubscriptionFunnel, {
            props: {
                subscriptionKpis: {
                    subscriptionCounts: { active: 4, past_due: 1, needs_review: 2 },
                    totalSubscriptions: 7,
                },
                t: {
                    subscription_funnel: 'Funil',
                    actions_total: 'Total',
                    subscription_status: { active: 'Ativo', past_due: 'Em atraso', needs_review: 'Revisar cobrança' },
                },
            },
        });

        const rows = wrapper
            .findAll('.funnel-row')
            .map((r) => [r.find('.badge').text(), r.find('.funnel-count').text()]);

        expect(rows).toContainEqual(['Revisar cobrança', '2']);
        expect(rows).toContainEqual(['Em atraso', '1']);
    });
});
