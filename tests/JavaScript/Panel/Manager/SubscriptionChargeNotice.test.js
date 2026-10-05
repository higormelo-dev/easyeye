import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SubscriptionCreateModal from '@/Pages/Panel/Manager/Subscriptions/SubscriptionCreateModal.vue';
import SubscriptionDetailDrawer from '@/Pages/Panel/Manager/Subscriptions/SubscriptionDetailDrawer.vue';

/**
 * Rodada 5 — manager:
 *  - A3: "Enviar cobrança à clínica" no modal pós-upgrade (marcado por
 *    padrão) e no detalhe da assinatura (fatura em aberto e lista de faturas);
 *  - A1: aviso "recorrência desativada pelo gateway" com "Marcar como visto".
 */
const t = {
    request_failed: 'Falhou',
    btn_cancel: 'Cancelar',
    create_title: 'Nova assinatura',
    field_company: 'Empresa',
    field_plan: 'Plano',
    field_mode: 'Modalidade',
    field_cycle: 'Ciclo',
    btn_create: 'Criar',
    company_current: 'Atual: :plan · :modality · :status',
    period_until: 'até :date',
    modality: { trial: 'Trial', gateway: 'Automática', complimentary: 'Cortesia' },
    change_preview_title_upgrade: 'Upgrade imediato',
    change_preview_upgrade: 'Fatura de :amount (:days dias); depois :next_amount em :next.',
    change_preview_unpaid_note: 'Não paga, nada muda.',
    change_preview_gateway_kept: 'Mantém :gateway.',
    btn_change_upgrade: 'Gerar fatura do upgrade',
    loading: 'Carregando',
    tab_overview: 'Visão geral',
    tab_history: 'Histórico',
    tab_invoices: 'Faturas',
    tab_retries: 'Retentativas',
    section_current: 'Atual',
    recurrence_alert_badge: 'Recorrência desativada',
    recurrence_alert_hint: 'Dica',
    recurrence_lost: {
        title: 'Recorrência desativada pelo gateway',
        body: 'O :gateway desativou em :date.',
        next: 'Próximo vencimento: :date',
        access_until: 'Acesso pago até: :date',
        event: 'Evento do gateway: :event',
        acknowledge: 'Marcar como visto',
    },
    charge_notice: {
        button: 'Enviar cobrança à clínica',
        button_hint: 'E-mail e WhatsApp',
        open_title: 'Fatura em aberto',
        open_plan_change: 'Diferença do upgrade',
        open_value: ':reference · :amount · vence em :date',
        last_sent: 'Último envio: :date por :name (:channels)',
        last_sent_system: 'Último envio: :date (:channels)',
        channel: { mail: 'e-mail', whatsapp: 'WhatsApp' },
        after_create: 'Enviar a cobrança à clínica agora',
        after_create_hint: 'Link para pagar no sistema.',
        after_create_failed: 'O upgrade foi feito, mas a cobrança não foi enviada: :error',
    },
};

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
});

beforeEach(() => {
    window.axios = vi
        .fn()
        .mockResolvedValue({ data: { message: 'Upgrade pedido.', data: { id: 'sub-paid', invoice_id: 'inv-up' } } });
    window.axios.get = vi.fn();
    window.axios.post = vi.fn().mockResolvedValue({ data: { message: 'Cobrança enviada a 2 contatos.' } });
});

describe('modal pós-upgrade: enviar a cobrança à clínica', () => {
    const plans = [
        {
            id: 'plan-premium',
            name: 'Premium',
            active: true,
            default_cycle: 'monthly',
            prices: [{ cycle: 'monthly', label: 'Mensal', months: 1, price: 600 }],
        },
    ];
    const billingCycles = [{ value: 'monthly', label: 'Mensal', period_label: '/mês', months: 1 }];
    const clinic = {
        id: 'clinic-1',
        name: 'Clínica Visão',
        active: true,
        current: {
            id: 'sub-paid',
            plan_name: 'Pro',
            modality: 'gateway',
            status_label: 'Ativo',
            has_access: true,
            access_ends_at: '2026-11-01T00:00:00',
        },
    };

    async function openUpgrade() {
        window.axios.get = vi.fn((url) =>
            Promise.resolve(
                url.includes('change-preview')
                    ? {
                          data: {
                              data: {
                                  change: {
                                      type: 'upgrade',
                                      amount_now: 154.84,
                                      remaining_days: 16,
                                      new_amount: 600,
                                      next_charge_at: '2026-11-01',
                                  },
                                  gateway: 'mercadopago',
                              },
                          },
                      }
                    : { data: { data: [clinic] } },
            ),
        );
        wrapper = mount(SubscriptionCreateModal, {
            props: {
                open: false,
                plans,
                billingCycles,
                gateways: [],
                trialDays: 7,
                preset: { entity_id: 'clinic-1', plan_id: 'plan-premium', mode: 'gateway' },
                t,
            },
            global: { mocks: { route: globalThis.route } },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
    }

    const dialog = () => document.body.querySelector('.ee-modal__dialog');
    const submit = () =>
        [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Gerar fatura do upgrade');

    it('mostra a opção já marcada e, depois do upgrade, envia a fatura da diferença à clínica', async () => {
        await openUpgrade();

        const checkbox = dialog().querySelector('[data-test="plan-change-notify-input"]');
        expect(checkbox).not.toBeNull();
        expect(checkbox.checked).toBe(true);
        expect(dialog().textContent).toContain('Enviar a cobrança à clínica agora');

        submit().click();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/_routes/manager.subscriptions.invoices.send-charge?subscription=sub-paid&invoice=inv-up',
            {},
            expect.anything(),
        );
        expect(wrapper.emitted('saved')?.[0]).toEqual(['Upgrade pedido. Cobrança enviada a 2 contatos.']);
    });

    it('desmarcada, só faz o upgrade', async () => {
        await openUpgrade();

        const checkbox = dialog().querySelector('[data-test="plan-change-notify-input"]');
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change'));
        await flushPromises();

        submit().click();
        await flushPromises();

        expect(window.axios.post).not.toHaveBeenCalled();
        expect(wrapper.emitted('saved')?.[0]).toEqual(['Upgrade pedido.']);
    });

    it('se o envio falhar, o upgrade continua feito e o aviso diz o motivo', async () => {
        window.axios.post = vi.fn().mockRejectedValue({ response: { status: 409, data: { message: 'Fatura paga.' } } });
        await openUpgrade();

        submit().click();
        await flushPromises();

        expect(wrapper.emitted('saved')?.[0][0]).toContain(
            'O upgrade foi feito, mas a cobrança não foi enviada: Fatura paga.',
        );
    });
});

describe('detalhe da assinatura', () => {
    const detail = {
        id: 'sub-1',
        entity_name: 'Clínica Visão',
        status: 'active',
        status_label: 'Ativo',
        status_badge: 'badge-soft-success',
        modality: 'gateway',
        is_current: true,
        entity_active: true,
        plan_name: 'Pro',
        recurrence_alert: '2026-10-20T10:00:00-03:00',
        recurrence_lost: {
            at: '2026-10-20T10:00:00-03:00',
            gateway: 'asaas',
            gateway_event: 'SUBSCRIPTION_INACTIVATED',
            next_billing_at: '2026-11-08T23:59:59-03:00',
            access_until: '2026-11-08T23:59:59-03:00',
        },
        open_invoice: {
            id: 'inv-up',
            reference: 'UPG-1',
            amount: 154.84,
            currency: 'BRL',
            due_at: '2026-10-19T23:59:59-03:00',
            plan_change: true,
            last_notice: null,
        },
    };

    async function openDrawer(data = detail) {
        globalThis.fetch = vi.fn((url) =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        data: url.includes('invoices')
                            ? [
                                  {
                                      id: 'inv-up',
                                      reference: 'UPG-1',
                                      status_label: 'Pendente',
                                      status_badge: 'badge-soft-warning',
                                      amount: 154.84,
                                      currency: 'BRL',
                                      can_send_charge: true,
                                      last_charge_notice: {
                                          at: '2026-10-20T09:00:00-03:00',
                                          by: 'Ana',
                                          channels: ['mail', 'whatsapp'],
                                      },
                                      payments: [],
                                  },
                              ]
                            : data,
                    }),
            }),
        );
        wrapper = mount(SubscriptionDetailDrawer, {
            props: { open: false, subscriptionId: 'sub-1', t },
            global: { mocks: { route: globalThis.route }, stubs: { Link: true } },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
    }

    const q = (sel) => document.body.querySelector(sel);

    it('mostra a fatura do upgrade e envia a cobrança à clínica', async () => {
        await openDrawer();

        expect(q('[data-test="sdd-open-invoice"]').textContent).toContain('Diferença do upgrade');
        q('[data-test="sdd-send-charge"]').click();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/_routes/manager.subscriptions.invoices.send-charge?subscription=sub-1&invoice=inv-up',
            {},
            expect.anything(),
        );
        expect(q('[data-test="sdd-charge-sent"]').textContent).toContain('Cobrança enviada a 2 contatos.');
    });

    it('envio recente (429) mostra a mensagem do servidor', async () => {
        window.axios.post = vi
            .fn()
            .mockRejectedValue({ response: { status: 429, data: { message: 'Tente de novo em 8 minutos.' } } });
        await openDrawer();

        q('[data-test="sdd-send-charge"]').click();
        await flushPromises();

        expect(q('[data-test="sdd-charge-error"]').textContent).toContain('Tente de novo em 8 minutos.');
    });

    it('lista de faturas: botão de envio e último envio com quem e por quais canais', async () => {
        await openDrawer();

        [...document.body.querySelectorAll('[role="tab"]')].find((b) => b.textContent.includes('Faturas')).click();
        await flushPromises();

        const row = q('[data-test="sdd-invoice-charge"]');
        expect(row.textContent).toContain('por Ana (e-mail, WhatsApp)');
        q('[data-test="sdd-invoice-send-charge"]').click();
        await flushPromises();
        expect(window.axios.post).toHaveBeenCalledTimes(1);
    });

    it('recorrência desativada pelo gateway: aviso com os dados e "Marcar como visto"', async () => {
        await openDrawer();

        const notice = q('[data-test="sdd-recurrence-lost"]');
        expect(notice.textContent).toContain('O ASAAS desativou');
        expect(notice.textContent).toContain('Evento do gateway: SUBSCRIPTION_INACTIVATED');
        expect(q('[data-test="sdd-recurrence-alert"]')).not.toBeNull();

        q('[data-test="sdd-recurrence-ack"]').click();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/_routes/manager.subscriptions.recurrence-alert.acknowledge/sub-1',
            {},
            expect.anything(),
        );
        expect(wrapper.emitted('updated')).toHaveLength(1);
    });
});
