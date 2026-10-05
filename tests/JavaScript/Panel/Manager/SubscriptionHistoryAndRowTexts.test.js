import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SubscriptionHistory from '@/Pages/Panel/Manager/Subscriptions/SubscriptionHistory.vue';
import SubscriptionTable from '@/Pages/Panel/Manager/Subscriptions/SubscriptionTable.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { on: vi.fn(() => () => {}), get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

/**
 * Manager → Assinaturas:
 *  - histórico: contratação aguardando o 1º pagamento não aparece como "Em
 *    atraso" (selo e antes/depois dos eventos); o cancelamento feito pelo
 *    manager não é atribuído ao sistema;
 *  - tabela: a linha em atraso não usa o fundo amarelo forte do tema, e a
 *    informação da régua fica na cor de ênfase (contraste ≥ 4,5:1).
 */
const t = {
    status_awaiting_first_payment: 'Aguardando 1º pagamento',
    history_by: 'por :name',
    history_by_system: 'pelo sistema',
    history_by_manager: 'pelo manager',
    history_reason: 'Motivo',
    history_field: { status: 'Situação', plan: 'Plano' },
    history_event: { activation: 'Cobrança automática ativada', cancelled: 'Assinatura cancelada', other: 'Alteração' },
    modality: { gateway: 'Cobrança automática', trial: 'Trial' },
};

const statuses = [
    { value: 'trial', label: 'Trial' },
    { value: 'past_due', label: 'Em atraso' },
    { value: 'cancelled', label: 'Cancelado' },
];

let wrapper;

afterEach(() => wrapper?.unmount());

async function mountHistory(data) {
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ data }) }));
    wrapper = mount(SubscriptionHistory, { props: { subscriptionId: 'sub-1', statuses, t } });
    await flushPromises();

    return wrapper;
}

describe('histórico da empresa', () => {
    it('contratação aguardando o 1º pagamento: selo e evento sem "Em atraso"', async () => {
        await mountHistory({
            subscriptions: [
                {
                    id: 'sub-1',
                    is_current: true,
                    plan_name: 'Pro',
                    modality: 'gateway',
                    status: 'past_due',
                    status_label: 'Aguardando 1º pagamento',
                    amount: null,
                    starts_at: '2026-10-04T10:00:00-03:00',
                    ends_at: null,
                },
            ],
            events: [
                {
                    id: 'ev-1',
                    type: 'activation',
                    at: '2026-10-04T10:00:00-03:00',
                    actor: 'Ana Admin',
                    previous: { plan_name: 'Pro', status: 'trial', billing_mode: null },
                    new: {
                        plan_name: 'Pro',
                        status: 'past_due',
                        billing_mode: 'gateway',
                        awaiting_first_payment: true,
                    },
                },
            ],
        });

        expect(wrapper.get('[data-test="history-status"]').text()).toBe('Aguardando 1º pagamento');

        const event = wrapper.get('[data-type="activation"]').text();
        expect(event).toContain('Aguardando 1º pagamento');
        expect(event).not.toContain('Em atraso');
    });

    it('cancelamento do manager sem autor gravado (registro antigo) não aparece "pelo sistema"', async () => {
        await mountHistory({
            subscriptions: [],
            events: [
                { id: 'ev-1', type: 'cancelled', at: '2026-10-04T10:00:00-03:00', actor: null, source: 'manager' },
                { id: 'ev-2', type: 'expired', at: '2026-10-04T10:00:00-03:00', actor: null, source: 'dunning' },
                {
                    id: 'ev-3',
                    type: 'cancelled',
                    at: '2026-10-04T10:00:00-03:00',
                    actor: 'Ana Admin',
                    source: 'manager',
                    reason: 'Cliente pediu o cancelamento por telefone.',
                },
            ],
        });

        const events = wrapper.findAll('.sub-history__event');
        expect(events[0].text()).toContain('pelo manager');
        expect(events[1].text()).toContain('pelo sistema');
        expect(events[2].text()).toContain('por Ana Admin');
        expect(events[2].text()).toContain('Cliente pediu o cancelamento por telefone.');
    });
});

describe('tabela de assinaturas', () => {
    it('linha em atraso: sem o fundo amarelo forte do tema, régua na cor de ênfase', () => {
        wrapper = mount(SubscriptionTable, {
            props: {
                subscriptions: {
                    data: [
                        {
                            id: 'sub-1',
                            entity_id: 'ent-1',
                            entity_name: 'Clínica Atrasada',
                            entity_active: true,
                            plan_name: 'Pro',
                            status: 'past_due',
                            status_label: 'Em atraso',
                            status_badge: 'badge-soft-danger',
                            modality: 'gateway',
                            billing_mode: 'gateway',
                            amount: 300,
                            is_current: true,
                            needs_attention: true,
                            access_ends_at: '2026-10-01T23:59:59-03:00',
                            days_left: -3,
                            days_overdue: 3,
                            dunning_stage_label: 'Acesso limitado',
                        },
                    ],
                },
                t: {
                    ...t,
                    period_until: 'até :date',
                    amount_none: 'Sem cobrança',
                    amount_per_cycle: ':amount/:period',
                    days_overdue: 'Venceu há :days dia|Venceu há :days dias',
                    overdue_for: 'Em atraso há :days dia|Em atraso há :days dias',
                },
            },
        });

        const row = wrapper.get('tbody tr');
        expect(row.classes()).not.toContain('table-warning');
        expect(row.classes()).toContain('sub-row--attention');

        const access = wrapper.get('[data-test="sub-access"]');
        expect(access.text()).toBe('Venceu há 3 dias');
        expect(access.classes()).toContain('text-danger-emphasis');
        expect(access.classes()).not.toContain('text-danger');

        const dunning = wrapper.get('[data-test="sub-dunning"]');
        expect(dunning.text()).toBe('Em atraso há 3 dias · Acesso limitado');
        expect(dunning.classes()).toContain('text-danger-emphasis');
        expect(dunning.classes()).not.toContain('text-danger');
    });
});
