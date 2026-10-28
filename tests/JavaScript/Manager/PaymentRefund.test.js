import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import PaymentRefundModal from '@/Pages/Panel/Manager/Subscriptions/PaymentRefundModal.vue';
import SubscriptionDetailDrawer from '@/Pages/Panel/Manager/Subscriptions/SubscriptionDetailDrawer.vue';

/**
 * Estorno pelo manager (Assinaturas → detalhe → faturas → Estornar): botão só
 * para o pagamento que o gateway estorna, total ou parcial (com valor e
 * moeda localizada), justificativa obrigatória (auditoria) e a situação
 * "estorno solicitado" até o gateway confirmar.
 */
const t = {
    request_failed: 'Falha na requisição.',
    field_reason: 'Justificativa',
    reason_hint: 'Fica no registro de auditoria.',
    reason_placeholder: 'Por que…',
    btn_cancel: 'Cancelar',
    tab_overview: 'Resumo',
    tab_history: 'Histórico',
    tab_invoices: 'Faturas',
    tab_retries: 'Tentativas',
    invoice_payments: 'Pagamentos',
    empty_invoices: 'Sem faturas',
    loading: 'Carregando',
    refund: {
        button: 'Estornar',
        button_hint: 'Devolver o pagamento.',
        title: 'Estornar pagamento',
        message: 'Pagamento de :amount em :gateway (:reference).',
        mode_full: 'Estorno total (:amount)',
        mode_partial: 'Estorno parcial',
        amount: 'Valor a devolver',
        amount_hint: 'Até :max.',
        confirm: 'Pedir estorno',
        status_requested: 'Estorno solicitado',
        status_done: 'Estornado',
        refunded_value: 'Devolvido: :amount',
        partial_unavailable: 'Este gateway só estorna o valor total.',
        request_url: 'Link para a clínica informar a conta (boleto)',
        status_failed: 'Estorno recusado',
        status_cancelled: 'Pedido liberado',
        check: 'Conferir',
        check_hint: 'Consultar no gateway.',
        notes: {
            awaiting_payer_account: 'Aguardando a clínica informar a conta (boleto)',
            inconclusive: 'Sem resposta do gateway — conferir',
            not_found: 'Não chegou ao gateway',
        },
    },
};

const norm = (text) => String(text).replace(/\u00a0/g, ' ');

function paidPayment(overrides = {}) {
    return {
        id: 'pay-1',
        status: 'paid',
        status_label: 'Pago',
        status_badge: 'badge-soft-success',
        amount: 299.9,
        refunded_amount: 0,
        currency: 'BRL',
        gateway_code: 'asaas',
        external_payment_id: 'pay_080225913252',
        refund: { can_refund: true, partial: true, remaining: 299.9, refunded: 0, pending: null },
        refunds: [],
        refund_url: '/_routes/manager.subscriptions.payments.refund',
        ...overrides,
    };
}

const REASON = 'Cliente cobrado em duplicidade no mesmo período';

let wrapper;

beforeEach(() => {
    window.axios = vi
        .fn()
        .mockResolvedValue({ data: { message: 'Estorno solicitado ao gateway.', data: { status: 'requested' } } });
});

afterEach(() => {
    wrapper?.unmount();
    delete window.axios;
});

function renderModal(payment = paidPayment()) {
    wrapper = mount(PaymentRefundModal, {
        props: { open: false, payment, invoice: { id: 'inv-1', reference: 'INV-1' }, t },
        attachTo: document.body,
    });

    return wrapper;
}

async function openModal() {
    await wrapper.setProps({ open: true });
    await flushPromises();
}

describe('PaymentRefundModal', () => {
    it('estorno total: valor localizado, justificativa obrigatória e o pedido ao servidor', async () => {
        renderModal();
        await openModal();

        expect(norm(document.body.textContent)).toContain('Estorno total (R$ 299,90)');
        const submit = document.querySelector('[data-test="refund-submit"]');
        expect(submit.disabled).toBe(true);

        const textarea = document.querySelector('textarea');
        textarea.value = REASON;
        textarea.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(submit.disabled).toBe(false);

        submit.click();
        await flushPromises();

        expect(window.axios).toHaveBeenCalledWith(
            expect.objectContaining({
                method: 'post',
                url: '/_routes/manager.subscriptions.payments.refund',
                data: { mode: 'full', amount: null, reason: REASON },
            }),
        );
        expect(wrapper.emitted('refunded')[0][0]).toBe('Estorno solicitado ao gateway.');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('estorno parcial: pede o valor (até o que falta devolver) e envia o número', async () => {
        renderModal();
        await openModal();

        const partial = document.querySelector('[data-test="refund-mode-partial"]');
        partial.checked = true;
        partial.dispatchEvent(new Event('change'));
        await flushPromises();

        expect(norm(document.body.textContent)).toContain('Até R$ 299,90.');
        const amount = document.querySelector('[data-test="refund-amount"]');
        amount.value = '100,50';
        amount.dispatchEvent(new Event('input'));
        amount.dispatchEvent(new Event('blur'));

        const textarea = document.querySelector('textarea');
        textarea.value = REASON;
        textarea.dispatchEvent(new Event('input'));
        await flushPromises();

        document.querySelector('[data-test="refund-submit"]').click();
        await flushPromises();

        expect(window.axios).toHaveBeenCalledWith(
            expect.objectContaining({ data: { mode: 'partial', amount: 100.5, reason: REASON } }),
        );
    });

    it('valor acima do que falta devolver não envia', async () => {
        renderModal();
        await openModal();

        const partial = document.querySelector('[data-test="refund-mode-partial"]');
        partial.checked = true;
        partial.dispatchEvent(new Event('change'));
        await flushPromises();

        const amount = document.querySelector('[data-test="refund-amount"]');
        amount.value = '500,00';
        amount.dispatchEvent(new Event('input'));
        const textarea = document.querySelector('textarea');
        textarea.value = REASON;
        textarea.dispatchEvent(new Event('input'));
        await flushPromises();

        expect(document.querySelector('[data-test="refund-submit"]').disabled).toBe(true);
    });

    it('gateway sem estorno parcial: a opção parcial fica desabilitada', async () => {
        renderModal(paidPayment({ refund: { can_refund: true, partial: false, remaining: 299.9, refunded: 0 } }));
        await openModal();

        expect(document.querySelector('[data-test="refund-mode-partial"]').disabled).toBe(true);
    });

    it('recusa do gateway aparece no modal (role=alert) e nada é emitido', async () => {
        window.axios = vi.fn().mockRejectedValue({
            response: { status: 422, data: { message: 'O gateway recusou o estorno: saldo insuficiente' } },
        });
        renderModal();
        await openModal();

        const textarea = document.querySelector('textarea');
        textarea.value = REASON;
        textarea.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector('[data-test="refund-submit"]').click();
        await flushPromises();

        expect(document.querySelector('[data-test="refund-error"]').textContent).toContain('saldo insuficiente');
        expect(wrapper.emitted('refunded')).toBeUndefined();
    });
});

describe('Detalhe da assinatura — faturas', () => {
    function invoicesPayload(payments) {
        return [
            {
                id: 'inv-1',
                reference: 'INV-1',
                status: 'paid',
                status_label: 'Paga',
                status_badge: 'badge-soft-success',
                amount: 299.9,
                currency: 'BRL',
                payments,
            },
        ];
    }

    async function renderDrawer(payments) {
        globalThis.fetch = vi.fn((url) =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        data: String(url).includes('invoices')
                            ? invoicesPayload(payments)
                            : { id: 's1', entity_name: 'Clínica', plan_name: 'Pro', status: 'active' },
                    }),
            }),
        );

        wrapper = mount(SubscriptionDetailDrawer, {
            props: { open: false, subscriptionId: 's1', t },
            global: {
                stubs: {
                    OffcanvasPanel: {
                        props: ['open'],
                        template: '<div><slot name="header" /><slot name="tabs" /><slot /></div>',
                    },
                    SubscriptionHistory: { template: '<div />' },
                    BillingStateBadge: { template: '<span />' },
                },
            },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        const tab = wrapper.findAll('button').find((b) => b.text().includes('Faturas'));
        await tab.trigger('click');
        await flushPromises();
    }

    it('"Estornar" só no pagamento que pode ser estornado; estorno solicitado e devolvido aparecem', async () => {
        await renderDrawer([
            paidPayment(),
            paidPayment({
                id: 'pay-2',
                refunded_amount: 100,
                refund: {
                    can_refund: false,
                    partial: true,
                    remaining: 199.9,
                    refunded: 100,
                    pending: { status: 'requested' },
                },
                refunds: [{ id: 'r1', status: 'requested', amount: 50, requested_at: '2026-10-05T10:00:00-03:00' }],
            }),
            paidPayment({
                id: 'pay-3',
                gateway_code: 'pagbank',
                refund: { can_refund: false, partial: false, remaining: 299.9 },
            }),
        ]);

        const rows = wrapper.findAll('[data-test="sdd-payment"]');
        expect(rows).toHaveLength(3);
        expect(rows[0].find('[data-test="sdd-payment-refund"]').exists()).toBe(true);
        expect(rows[1].find('[data-test="sdd-payment-refund"]').exists()).toBe(false);
        expect(rows[2].find('[data-test="sdd-payment-refund"]').exists()).toBe(false);

        expect(rows[1].get('[data-test="sdd-refund"]').text()).toContain('Estorno solicitado');
        expect(norm(rows[1].get('[data-test="sdd-payment-refunded"]').text())).toBe('Devolvido: R$ 100,00');
    });

    it('pagamento em duplicidade: rótulo do servidor e "Estornar" disponível', async () => {
        await renderDrawer([
            paidPayment({
                id: 'pay-dup',
                status: 'duplicate',
                status_label: 'Em duplicidade',
                status_badge: 'badge-soft-orange',
            }),
        ]);

        const row = wrapper.get('[data-test="sdd-payment"]');
        expect(row.text()).toContain('Em duplicidade');
        expect(row.find('[data-test="sdd-payment-refund"]').exists()).toBe(true);
    });

    it('estorno sem resposta do gateway: mostra a situação e "Conferir" consulta o gateway, mostra o resultado e relê as faturas', async () => {
        await renderDrawer([
            paidPayment({
                refund: {
                    can_refund: false,
                    partial: true,
                    remaining: 299.9,
                    refunded: 0,
                    pending: { status: 'requested' },
                },
                refunds: [
                    {
                        id: 'r1',
                        status: 'requested',
                        gateway_state: 'inconclusive',
                        check_note: null,
                        amount: 299.9,
                        can_check: true,
                        check_url: '/_routes/manager.subscriptions.payments.refunds.check',
                    },
                    { id: 'r0', status: 'cancelled', check_note: 'not_found', amount: 299.9, can_check: false },
                ],
            }),
        ]);
        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: { message: 'O estorno segue em andamento no gateway.', outcome: 'pending' },
            }),
        };
        const fetchesBefore = globalThis.fetch.mock.calls.length;

        const refunds = wrapper.findAll('[data-test="sdd-refund"]');
        expect(refunds[0].get('[data-test="sdd-refund-note"]').text()).toContain('Sem resposta do gateway');
        expect(refunds[1].text()).toContain('Pedido liberado');
        expect(refunds[1].text()).toContain('Não chegou ao gateway');
        expect(refunds[1].find('[data-test="sdd-refund-check"]').exists()).toBe(false);

        await refunds[0].get('[data-test="sdd-refund-check"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/_routes/manager.subscriptions.payments.refunds.check',
            {},
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(globalThis.fetch.mock.calls.length).toBeGreaterThan(fetchesBefore);
        expect(wrapper.get('[data-test="sdd-refund-check-result"]').text()).toBe(
            'O estorno segue em andamento no gateway.',
        );
    });
});
