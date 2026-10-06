<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import CheckoutFlow from '@/Components/Billing/CheckoutFlow.vue';
import ReplaceCardFlow from '@/Components/Billing/ReplaceCardFlow.vue';
import AiCreditPackCheckout from '@/Components/Billing/AiCreditPackCheckout.vue';
import { checkoutError, createCheckoutApi, newIdempotencyKey, panelEndpoints } from '@/Support/billing/checkoutApi.js';
import { useBillingRealtime } from '@/composables/useBillingRealtime.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Minha assinatura (admin, financeiro e dono — middleware billing.contact):
 * plano, ciclo, valor, situação, próxima cobrança e cartão da renovação;
 * faturas em aberto com "Pagar" (checkout no próprio sistema, em modal);
 * histórico; trocar o cartão (onde o gateway permite) e mudar de plano/ciclo.
 *
 * Troca de quem já tem plano pago e vigente (options.change): upgrade mostra
 * o valor proporcional cobrado agora antes do pagamento; downgrade mostra a
 * data em que a mudança vale e só pede confirmação (nada é cobrado agora).
 *
 * Pedidos de créditos de IA ainda não pagos (open_ai_packs) ficam à parte das
 * faturas da assinatura — não são dívida (sem "vencida"): "Pagar" ou
 * "Descartar".
 *
 * Links de entrada: ?invoice={id} abre o pagamento dessa fatura (aviso de
 * pagamento, /subscription/expired); id que não está em aberto cai na 1ª
 * fatura da assinatura (nunca num pedido de créditos); ?plan={id}&cycle={ciclo}
 * abre a contratação. Pagamento confirmado chega pelo tempo real (billing.{entityId})
 * e a tela relê o resumo.
 *
 * Volta do ambiente seguro do Asaas (cartão — Asaas Checkout):
 * ?checkout_return=success|cancel|expired&checkout_invoice={id}. "success" só
 * diz que o pagador concluiu a página: a tela mostra "aguardando
 * confirmação" até o webhook confirmar (tempo real). Os parâmetros saem da
 * URL depois de lidos.
 */
const props = defineProps({
    checkout: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
    // Pacotes de créditos de IA (AiCreditPackCheckoutService::options) — já na página.
    aiCredits: { type: Object, default: null },
});

const page = usePage();
const { money, date, dateTime } = useLocaleFormat();
const pt = computed(() => props.t?.page ?? {});
const { tx } = useTrans(() => pt.value);

const api = createCheckoutApi(panelEndpoints());

const subscription = computed(() => props.checkout?.subscription ?? null);
const openInvoices = computed(() => props.checkout?.open_invoices ?? []);
// Pedidos de créditos de IA ainda não pagos: à parte, não são dívida da assinatura.
const openAiPacks = computed(() => props.checkout?.open_ai_packs ?? []);
const hasOpen = computed(() => openInvoices.value.length > 0 || openAiPacks.value.length > 0);
const invoices = computed(() => props.checkout?.invoices ?? []);
const payment = computed(() => props.checkout?.payment ?? null);
const plans = computed(() => props.checkout?.plans ?? []);
const settings = computed(() => props.checkout?.checkout ?? {});

const breadcrumbs = computed(() => [
    { label: pt.value.breadcrumb_home, url: route('panel.dashboard'), active: false },
    { label: pt.value.title, url: '#', active: true },
]);

const methodLabel = (code) => (code ? (props.t?.methods?.[code] ?? code) : '—');
const invoiceStatus = (status) => props.t?.invoice_status?.[status] ?? status ?? '—';

const STATUS_BADGE = {
    paid: 'badge-soft-success',
    pending: 'badge-soft-warning',
    overdue: 'badge-soft-danger',
    failed: 'badge-soft-danger',
    refunded: 'badge-soft-info',
};
const invoiceBadge = (status) => STATUS_BADGE[status] ?? 'badge-soft-secondary';

const SUBSCRIPTION_BADGE = { active: 'badge-soft-success', trialing: 'badge-soft-info', past_due: 'badge-soft-danger' };
const subscriptionBadge = computed(() =>
    subscription.value?.is_awaiting_first_payment
        ? 'badge-soft-warning'
        : (SUBSCRIPTION_BADGE[subscription.value?.status] ?? 'badge-soft-secondary'),
);

function cardText(card) {
    return card ? tx('card_value', { brand: card.brand ?? '', last4: card.last4 ?? '' }) : pt.value.no_card;
}

function isOverdue(invoice) {
    return invoice.status === 'overdue' || (invoice.due_date && new Date(`${invoice.due_date}T23:59:59`) < new Date());
}

// ── Recarregar o resumo (props) ───────────────────────────────────────────────
const justPaid = ref(false);

// ── Volta do ambiente seguro do gateway (Asaas Checkout) ───────────────────
const CHECKOUT_RETURNS = ['success', 'cancel', 'expired'];
const checkoutReturn = ref(null); // { result, invoiceId }

function readCheckoutReturn() {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    const result = params.get('checkout_return');
    if (!CHECKOUT_RETURNS.includes(result)) return;

    checkoutReturn.value = { result, invoiceId: params.get('checkout_invoice') };
    params.delete('checkout_return');
    params.delete('checkout_invoice');
    const query = params.toString();
    try {
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
    } catch {
        // sem history (ambiente de teste): só não limpa a URL
    }
}

readCheckoutReturn();

// Aguardando confirmação: a volta com sucesso ou o checkout pago (CHECKOUT_PAID) de alguma fatura.
const awaitingConfirmation = computed(
    () =>
        !justPaid.value &&
        ((checkoutReturn.value?.result === 'success' &&
            openInvoices.value
                .concat(openAiPacks.value)
                .some((i) => !checkoutReturn.value.invoiceId || i.id === checkoutReturn.value.invoiceId)) ||
            openInvoices.value.concat(openAiPacks.value).some((i) => i.awaiting_confirmation)),
);

function reloadSummary() {
    router.reload({ only: ['checkout'] });
}

useBillingRealtime(() => props.checkout?.realtime, {
    onPaid: () => {
        justPaid.value = true;
        checkoutReturn.value = null;
        reloadSummary();
    },
    onResync: () => {
        if (hasOpen.value) reloadSummary();
    },
    active: () => hasOpen.value,
});

// ── Pagar fatura / contratar (modal com o checkout) ─────────────────────────
const modal = ref({ open: false, kind: null, invoice: null, contract: null, options: null, title: '', key: 0 });

// Fatura de pacote de IA traz as formas do gateway dela (a clínica em cortesia não tem `payment`).
const paymentFor = (invoice) => invoice?.payment ?? payment.value;

// Já paga no cartão (ambiente seguro) aguardando a confirmação: sem "Pagar" — outro
// pagamento cobraria o cartão de novo (o servidor também recusa: 409).
const canPay = (invoice) => !!invoice?.can_pay && !invoice.awaiting_confirmation && !!paymentFor(invoice);

function openPay(invoice) {
    modal.value = {
        open: true,
        kind: 'invoice',
        invoice,
        contract: null,
        options: null,
        title: tx('pay_title', { reference: invoice.reference ?? '' }),
        key: modal.value.key + 1,
    };
}

function closeModal() {
    modal.value = { ...modal.value, open: false };
    scheduled.value = { busy: false, error: '', done: null };
}

// ── Troca de plano de quem já paga (upgrade/downgrade) ──────────────────────
const change = computed(() => (modal.value.kind === 'contract' ? (modal.value.options?.change ?? null) : null));
const scheduled = ref({ busy: false, error: '', done: null });

function cycleLabel(plan, cycle) {
    return plan?.prices?.find((p) => p.cycle === cycle)?.label ?? cycle ?? '';
}

const changeText = computed(() => {
    const c = change.value;
    if (!c) return '';
    const plan = plans.value.find((p) => p.id === c.plan?.id);

    if (c.type === 'upgrade') {
        const sameCycle = c.cycle === c.current?.cycle;

        return tx(sameCycle ? 'upgrade_body' : 'upgrade_body_cycle', {
            amount: money(c.amount_now),
            days: c.remaining_days,
            credit: money(c.credit),
            next_amount: money(c.new_amount),
            date: date(c.next_charge_at),
        });
    }

    if (c.type === 'scheduled') {
        return tx('scheduled_body', {
            plan: c.plan?.name ?? '',
            cycle: cycleLabel(plan, c.cycle),
            date: date(c.effective_at),
            amount: money(c.new_amount),
        });
    }

    return '';
});

async function confirmScheduled() {
    if (scheduled.value.busy || !modal.value.contract) return;

    scheduled.value = { busy: true, error: '', done: null };
    try {
        const res = await api.contract({ ...modal.value.contract }, newIdempotencyKey());
        scheduled.value = { busy: false, error: '', done: res?.change?.effective_at ?? change.value?.effective_at };
        reloadSummary();
    } catch (e) {
        scheduled.value = { busy: false, error: checkoutError(e, props.t).message, done: null };
    }
}

function invoiceLabel(invoice) {
    if (invoice.kind === 'ai_credit_pack')
        return tx('ai_pack_invoice', { credits: invoice.ai_credit_pack?.credits ?? '' });

    return invoice.kind === 'plan_change' && invoice.plan_change?.plan?.name
        ? tx('plan_change_invoice', { plan: invoice.plan_change.plan.name })
        : invoice.reference;
}

function onPaid() {
    justPaid.value = true;
    reloadSummary();
}

// ── Descartar pedido de créditos de IA não pago ─────────────────────────────
const discard = ref({ id: null, busy: false, error: '' });
const discardedNotice = ref('');

function askDiscard(invoice) {
    discard.value = { id: invoice.id, busy: false, error: '' };
}

function keepOrder() {
    discard.value = { id: null, busy: false, error: '' };
}

async function confirmDiscard(invoice) {
    if (discard.value.busy) return;

    discard.value = { id: invoice.id, busy: true, error: '' };
    try {
        await api.aiPackDiscard(invoice.id);
        discard.value = { id: null, busy: false, error: '' };
        discardedNotice.value = pt.value.ai_pack_discarded;
        reloadSummary();
    } catch (e) {
        discard.value = { id: invoice.id, busy: false, error: checkoutError(e, props.t).message };
    }
}

// ── Mudar de plano/ciclo ─────────────────────────────────────────────────────
const selectedPlanId = ref(subscription.value?.plan?.id ?? plans.value[0]?.id ?? null);
const selectedCycle = ref(subscription.value?.cycle ?? null);
const optionsLoading = ref(false);
const optionsError = ref('');

const selectedPlan = computed(() => plans.value.find((p) => p.id === selectedPlanId.value) ?? null);
const selectedPrice = computed(() => selectedPlan.value?.prices?.find((p) => p.cycle === selectedCycle.value) ?? null);

watch(
    selectedPlan,
    (plan) => {
        if (!plan) return;
        if (!plan.prices?.some((p) => p.cycle === selectedCycle.value)) {
            selectedCycle.value =
                plan.prices?.find((p) => p.cycle === plan.default_cycle)?.cycle ?? plan.prices?.[0]?.cycle ?? null;
        }
    },
    { immediate: true },
);

const isCurrentSelection = computed(
    () =>
        !!subscription.value &&
        subscription.value.status === 'active' &&
        !subscription.value.is_awaiting_first_payment &&
        subscription.value.plan?.id === selectedPlanId.value &&
        subscription.value.cycle === selectedCycle.value,
);

const installmentsHint = computed(() => {
    const max = Number(settings.value.max_installments ?? 1);
    const cycles = settings.value.installment_cycles ?? [];

    return max > 1 && cycles.includes(selectedCycle.value) ? tx('installments_hint', { count: max }) : '';
});

async function openContract(planId = selectedPlanId.value, cycle = selectedCycle.value) {
    if (!planId || !cycle || optionsLoading.value) return;

    optionsLoading.value = true;
    optionsError.value = '';
    try {
        const options = await api.options(planId, cycle);
        const plan = plans.value.find((p) => p.id === planId);
        const price = plan?.prices?.find((p) => p.cycle === cycle);
        modal.value = {
            open: true,
            kind: 'contract',
            invoice: null,
            contract: { plan_id: planId, billing_cycle: cycle },
            options,
            title: tx('contract_modal', {
                plan: options?.plan?.name ?? plan?.name ?? '',
                cycle: price?.label ?? cycle,
            }),
            key: modal.value.key + 1,
        };
    } catch (e) {
        optionsError.value = checkoutError(e, props.t).message;
    } finally {
        optionsLoading.value = false;
    }
}

// ── Trocar cartão ────────────────────────────────────────────────────────────
const cardModal = ref({ open: false, key: 0 });
const canChangeCard = computed(
    () =>
        !!subscription.value?.can_change_card &&
        payment.value?.mode === 'transparent' &&
        !!payment.value?.card?.public_key,
);

function openCardModal() {
    cardModal.value = { open: true, key: cardModal.value.key + 1 };
}

function onCardSaved() {
    reloadSummary();
}

// ── Entrada por link (?invoice= / ?plan=&cycle=) ─────────────────────────────
let handledUrl = null;

watch(
    () => page?.url ?? (typeof window !== 'undefined' ? window.location.href : ''),
    () => {
        if (typeof window === 'undefined') return;
        const params = new URLSearchParams(window.location.search);
        const key = window.location.search;
        if (!key || key === handledUrl) return;
        handledUrl = key;

        const invoiceId = params.get('invoice');
        const planId = params.get('plan');

        if (invoiceId) {
            // Fatura pedida; senão a 1ª da assinatura — nunca um pedido de créditos de IA.
            const invoice =
                openInvoices.value.find((i) => i.id === invoiceId) ??
                openAiPacks.value.find((i) => i.id === invoiceId) ??
                openInvoices.value.find((i) => i.kind !== 'ai_credit_pack');
            if (invoice && canPay(invoice)) openPay(invoice);
        } else if (planId && plans.value.some((p) => p.id === planId)) {
            selectedPlanId.value = planId;
            const cycle = params.get('cycle');
            if (cycle && selectedPlan.value?.prices?.some((p) => p.cycle === cycle)) selectedCycle.value = cycle;
            openContract(planId, selectedCycle.value);
        }
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout :title="pt.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="pt.title" />

            <p class="small text-muted mb-3"><i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ pt.intro }}</p>

            <div aria-live="polite">
                <div
                    v-if="justPaid"
                    class="alert alert-success text-success-emphasis d-flex align-items-center"
                    data-test="my-subscription-paid"
                >
                    <i class="ti ti-circle-check me-2 fs-5" aria-hidden="true"></i>{{ pt.paid_notice }}
                </div>
                <div
                    v-if="discardedNotice"
                    class="alert alert-info text-info-emphasis d-flex align-items-center"
                    data-test="ai-pack-discarded"
                >
                    <i class="ti ti-trash me-2 fs-5" aria-hidden="true"></i>{{ discardedNotice }}
                </div>
                <div
                    v-if="awaitingConfirmation"
                    class="alert alert-info text-info-emphasis d-flex align-items-center"
                    role="status"
                    data-test="checkout-return-awaiting"
                >
                    <span class="spinner-grow spinner-grow-sm text-primary me-2" aria-hidden="true"></span
                    >{{ pt.checkout_return_success }}
                </div>
                <div
                    v-else-if="!justPaid && ['cancel', 'expired'].includes(checkoutReturn?.result)"
                    class="alert alert-warning text-warning-emphasis d-flex align-items-center"
                    :data-test="`checkout-return-${checkoutReturn.result}`"
                >
                    <i class="ti ti-alert-circle me-2 fs-5" aria-hidden="true"></i
                    >{{ checkoutReturn.result === 'cancel' ? pt.checkout_return_cancel : pt.checkout_return_expired }}
                </div>
                <!-- A troca de plano refez a cobrança automática sem o cartão: pagar a próxima no cartão volta. -->
                <div
                    v-if="subscription?.card_reregister_required"
                    class="alert alert-warning text-warning-emphasis d-flex align-items-center"
                    data-test="my-subscription-card-reregister"
                >
                    <i class="ti ti-credit-card-off me-2 fs-5" aria-hidden="true"></i>{{ pt.card_reregister }}
                </div>
            </div>

            <div class="row g-3 mb-3">
                <!-- Assinatura -->
                <div class="col-lg-5">
                    <section class="card h-100 mb-0" aria-labelledby="my-sub-title" data-test="my-subscription-card">
                        <div class="card-header py-2">
                            <h2 id="my-sub-title" class="h6 fw-semibold mb-0">
                                <i class="ti ti-file-invoice me-1 text-primary" aria-hidden="true"></i
                                >{{ pt.subscription_title }}
                            </h2>
                        </div>
                        <div class="card-body">
                            <p v-if="!subscription" class="text-muted mb-0" data-test="my-subscription-empty">
                                {{ pt.no_subscription }}
                            </p>
                            <dl v-else class="row small mb-0 ee-my-sub__dl">
                                <dt class="col-5 text-muted fw-normal">{{ pt.plan }}</dt>
                                <dd class="col-7 fw-semibold" data-test="my-subscription-plan">
                                    {{ subscription.plan?.name ?? '—' }}
                                </dd>

                                <dt class="col-5 text-muted fw-normal">{{ pt.cycle }}</dt>
                                <dd class="col-7">{{ subscription.cycle_label ?? '—' }}</dd>

                                <dt class="col-5 text-muted fw-normal">{{ pt.amount }}</dt>
                                <dd class="col-7" data-test="my-subscription-amount">
                                    {{ money(subscription.amount) }}
                                </dd>

                                <dt class="col-5 text-muted fw-normal">{{ pt.status }}</dt>
                                <dd class="col-7">
                                    <span class="badge" :class="subscriptionBadge">{{
                                        subscription.status_label ?? subscription.status
                                    }}</span>
                                </dd>

                                <dt class="col-5 text-muted fw-normal">{{ pt.next_billing }}</dt>
                                <dd class="col-7">{{ date(subscription.next_billing_at) }}</dd>

                                <template v-if="subscription.scheduled_change">
                                    <dt class="col-5 text-muted fw-normal">{{ pt.scheduled_change }}</dt>
                                    <dd class="col-7" data-test="my-subscription-scheduled">
                                        {{
                                            tx('scheduled_change_value', {
                                                plan: subscription.scheduled_change.plan?.name ?? '',
                                                cycle: subscription.scheduled_change.cycle_label ?? '',
                                                date: date(subscription.scheduled_change.effective_at),
                                                amount: money(subscription.scheduled_change.amount),
                                            })
                                        }}
                                    </dd>
                                </template>

                                <dt class="col-5 text-muted fw-normal">{{ pt.payment_method }}</dt>
                                <dd class="col-7">{{ methodLabel(subscription.payment_method) }}</dd>

                                <dt class="col-5 text-muted fw-normal">{{ pt.card }}</dt>
                                <dd class="col-7 mb-0">
                                    <span data-test="my-subscription-saved-card">{{
                                        cardText(subscription.card)
                                    }}</span>
                                    <span
                                        v-if="subscription.card && subscription.card_installments > 1"
                                        class="d-block text-muted"
                                        >{{ tx('card_installments', { count: subscription.card_installments }) }}</span
                                    >
                                    <span
                                        v-if="subscription.renewal_in_full"
                                        class="d-block text-muted"
                                        data-test="my-subscription-renewal-in-full"
                                        >{{ pt.renewal_in_full }}</span
                                    >
                                    <button
                                        v-if="canChangeCard"
                                        type="button"
                                        class="btn btn-sm btn-outline-primary mt-2"
                                        data-test="my-subscription-change-card"
                                        @click="openCardModal"
                                    >
                                        <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ pt.change_card }}
                                    </button>
                                </dd>
                            </dl>
                        </div>
                    </section>
                </div>

                <!-- Faturas em aberto -->
                <div class="col-lg-7">
                    <section class="card h-100 mb-0" aria-labelledby="my-open-title" data-test="my-subscription-open">
                        <div class="card-header py-2">
                            <h2 id="my-open-title" class="h6 fw-semibold mb-0">
                                <i class="ti ti-receipt me-1 text-primary" aria-hidden="true"></i>{{ pt.open_title }}
                            </h2>
                        </div>
                        <div class="card-body">
                            <p
                                v-if="openInvoices.length === 0"
                                class="text-muted mb-0"
                                data-test="my-subscription-no-open"
                            >
                                <i class="ti ti-circle-check text-success me-1" aria-hidden="true"></i>{{ pt.no_open }}
                            </p>
                            <ul v-else class="list-unstyled mb-0">
                                <li
                                    v-for="invoice in openInvoices"
                                    :key="invoice.id"
                                    class="d-flex flex-column flex-sm-row align-items-sm-center gap-2 py-2 border-bottom"
                                    data-test="open-invoice"
                                >
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-semibold">
                                            {{ money(invoice.amount, invoice.currency || 'BRL') }}
                                            <span class="badge ms-1" :class="invoiceBadge(invoice.status)">{{
                                                invoiceStatus(invoice.status)
                                            }}</span>
                                            <span
                                                v-if="invoice.awaiting_confirmation"
                                                class="badge badge-soft-info ms-1"
                                                data-test="open-invoice-awaiting"
                                                >{{ pt.awaiting_confirmation }}</span
                                            >
                                        </div>
                                        <div class="small text-muted">
                                            {{ invoiceLabel(invoice) }} ·
                                            {{
                                                tx(isOverdue(invoice) ? 'overdue_on' : 'due_on', {
                                                    date: date(invoice.due_date),
                                                })
                                            }}
                                        </div>
                                    </div>
                                    <!-- Pago no cartão aguardando a confirmação: sem "Pagar" (cobraria de novo). -->
                                    <button
                                        v-if="canPay(invoice)"
                                        type="button"
                                        class="btn btn-primary text-nowrap"
                                        data-test="open-invoice-pay"
                                        @click="openPay(invoice)"
                                    >
                                        <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ pt.pay }}
                                        <span class="visually-hidden">{{ invoice.reference }}</span>
                                    </button>
                                </li>
                            </ul>

                            <!-- Pedidos de créditos de IA não pagos: não são dívida da assinatura. -->
                            <div v-if="openAiPacks.length" class="mt-3" data-test="open-ai-packs">
                                <h3 class="small fw-semibold mb-1">
                                    <i class="ti ti-sparkles me-1 text-primary" aria-hidden="true"></i
                                    >{{ pt.ai_pack_open_title }}
                                </h3>
                                <p class="small text-muted mb-1">{{ pt.ai_pack_open_hint }}</p>
                                <ul class="list-unstyled mb-0">
                                    <li
                                        v-for="invoice in openAiPacks"
                                        :key="invoice.id"
                                        class="d-flex flex-column flex-sm-row align-items-sm-center gap-2 py-2 border-bottom"
                                        data-test="open-ai-pack"
                                    >
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="fw-semibold">
                                                {{ money(invoice.amount, invoice.currency || 'BRL') }}
                                            </div>
                                            <div class="small text-muted">
                                                {{ invoiceLabel(invoice) }} ·
                                                {{
                                                    tx('ai_pack_open_created', {
                                                        date: date(invoice.created_at ?? invoice.due_date),
                                                    })
                                                }}
                                            </div>
                                            <p
                                                v-if="discard.id === invoice.id && discard.error"
                                                class="text-danger small mb-0 mt-1"
                                                role="alert"
                                            >
                                                {{ discard.error }}
                                            </p>
                                        </div>
                                        <div
                                            v-if="discard.id === invoice.id && !discard.error"
                                            class="d-flex flex-wrap align-items-center gap-2"
                                            role="group"
                                            data-test="ai-pack-discard-confirm"
                                        >
                                            <span class="small">{{ pt.ai_pack_discard_confirm }}</span>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-danger text-nowrap"
                                                :disabled="discard.busy"
                                                data-test="ai-pack-discard-yes"
                                                @click="confirmDiscard(invoice)"
                                            >
                                                <span
                                                    v-if="discard.busy"
                                                    class="spinner-border spinner-border-sm me-1"
                                                    aria-hidden="true"
                                                ></span
                                                >{{ pt.ai_pack_discard }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-light text-nowrap"
                                                :disabled="discard.busy"
                                                @click="keepOrder"
                                            >
                                                {{ pt.ai_pack_discard_keep }}
                                            </button>
                                        </div>
                                        <div v-else class="d-flex gap-2">
                                            <button
                                                v-if="canPay(invoice)"
                                                type="button"
                                                class="btn btn-primary text-nowrap"
                                                data-test="open-ai-pack-pay"
                                                @click="openPay(invoice)"
                                            >
                                                <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ pt.pay }}
                                                <span class="visually-hidden">{{ invoiceLabel(invoice) }}</span>
                                            </button>
                                            <button
                                                v-if="invoice.can_discard"
                                                type="button"
                                                class="btn btn-outline-secondary text-nowrap"
                                                data-test="open-ai-pack-discard"
                                                @click="askDiscard(invoice)"
                                            >
                                                <i class="ti ti-trash me-1" aria-hidden="true"></i
                                                >{{ pt.ai_pack_discard }}
                                                <span class="visually-hidden">{{ invoiceLabel(invoice) }}</span>
                                            </button>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Mudar de plano/ciclo (nova contratação já pagando) -->
            <section class="card mb-3" aria-labelledby="my-change-title" data-test="my-subscription-change">
                <div class="card-header py-2">
                    <h2 id="my-change-title" class="h6 fw-semibold mb-0">
                        <i class="ti ti-arrows-exchange me-1 text-primary" aria-hidden="true"></i
                        >{{ subscription ? pt.change_title : pt.contract_title }}
                    </h2>
                </div>
                <div class="card-body">
                    <p v-if="plans.length === 0" class="text-muted mb-0">{{ pt.no_plans }}</p>
                    <template v-else>
                        <p class="small text-muted">{{ pt.change_hint }}</p>

                        <fieldset class="mb-3">
                            <legend class="form-label small fw-semibold">{{ pt.choose_plan }}</legend>
                            <div class="row g-2">
                                <div v-for="plan in plans" :key="plan.id" class="col-sm-6 col-xl-4">
                                    <label
                                        class="ee-my-sub__option card h-100 mb-0 p-3"
                                        :class="{ 'border-primary ee-my-sub__option--on': selectedPlanId === plan.id }"
                                    >
                                        <span class="d-flex align-items-start gap-2">
                                            <input
                                                v-model="selectedPlanId"
                                                class="form-check-input mt-1"
                                                type="radio"
                                                name="my-sub-plan"
                                                :value="plan.id"
                                            />
                                            <span class="min-w-0">
                                                <span class="fw-semibold d-block">
                                                    {{ plan.name }}
                                                    <span
                                                        v-if="subscription?.plan?.id === plan.id"
                                                        class="badge badge-soft-primary ms-1"
                                                        >{{ pt.current }}</span
                                                    >
                                                </span>
                                                <span v-if="plan.description" class="small text-muted d-block">{{
                                                    plan.description
                                                }}</span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset v-if="selectedPlan?.prices?.length" class="mb-3">
                            <legend class="form-label small fw-semibold">{{ pt.choose_cycle }}</legend>
                            <div class="row g-2">
                                <div v-for="price in selectedPlan.prices" :key="price.cycle" class="col-sm-6 col-xl-3">
                                    <label
                                        class="ee-my-sub__option card h-100 mb-0 p-3"
                                        :class="{
                                            'border-primary ee-my-sub__option--on': selectedCycle === price.cycle,
                                        }"
                                    >
                                        <span class="d-flex align-items-start gap-2">
                                            <input
                                                v-model="selectedCycle"
                                                class="form-check-input mt-1"
                                                type="radio"
                                                name="my-sub-cycle"
                                                :value="price.cycle"
                                                :data-cycle="price.cycle"
                                            />
                                            <span>
                                                <span class="fw-semibold d-block">{{ price.label }}</span>
                                                <span class="d-block"
                                                    >{{ money(price.price)
                                                    }}<small class="text-muted">{{ price.period_label }}</small></span
                                                >
                                                <span v-if="price.months > 1" class="small text-muted d-block">
                                                    {{
                                                        tx('monthly_equivalent', {
                                                            price: money(price.monthly_equivalent),
                                                        })
                                                    }}
                                                    <strong v-if="price.savings_percent > 0" class="text-success">
                                                        ·
                                                        {{ tx('savings', { percent: price.savings_percent }) }}</strong
                                                    >
                                                </span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </fieldset>

                        <p v-if="installmentsHint" class="small text-muted" data-test="my-subscription-installments">
                            <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ installmentsHint }}
                        </p>

                        <p
                            v-if="optionsError"
                            class="text-danger small"
                            role="alert"
                            data-test="my-subscription-options-error"
                        >
                            {{ optionsError }}
                        </p>

                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="!selectedPrice || optionsLoading || isCurrentSelection"
                            :title="isCurrentSelection ? t.errors?.already_active : undefined"
                            data-test="my-subscription-contract"
                            @click="openContract()"
                        >
                            <span
                                v-if="optionsLoading"
                                class="spinner-border spinner-border-sm me-1"
                                aria-hidden="true"
                            ></span>
                            <i v-else class="ti ti-shopping-cart me-1" aria-hidden="true"></i>{{ pt.contract_cta }}
                            <template v-if="selectedPrice"> — {{ money(selectedPrice.price) }}</template>
                        </button>
                    </template>
                </div>
            </section>

            <!-- Créditos de IA (pacotes pagos no mesmo checkout) -->
            <!-- Plano sem IA: a seção nem aparece. -->
            <section
                v-if="aiCredits?.reason !== 'ai_pack_unavailable'"
                id="ai-credits"
                class="card mb-3"
                aria-labelledby="my-ai-title"
                data-test="my-subscription-ai"
            >
                <div class="card-header py-2">
                    <h2 id="my-ai-title" class="h6 fw-semibold mb-0">
                        <i class="ti ti-sparkles me-1 text-primary" aria-hidden="true"></i>{{ pt.ai_title }}
                    </h2>
                </div>
                <div class="card-body">
                    <p class="small text-muted">{{ pt.ai_intro }}</p>
                    <AiCreditPackCheckout :t="t" :initial="aiCredits" :show-recent="false" @paid="onPaid" />
                </div>
            </section>

            <!-- Histórico de faturas -->
            <section class="card mb-0" aria-labelledby="my-history-title" data-test="my-subscription-history">
                <div class="card-header py-2">
                    <h2 id="my-history-title" class="h6 fw-semibold mb-0">
                        <i class="ti ti-history me-1 text-primary" aria-hidden="true"></i>{{ pt.history_title }}
                    </h2>
                </div>
                <div v-if="invoices.length === 0" class="card-body text-muted">{{ pt.history_empty }}</div>
                <template v-else>
                    <!-- md+: tabela -->
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-hover align-middle mb-0">
                            <caption class="visually-hidden">
                                {{
                                    pt.history_title
                                }}
                            </caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">{{ pt.col_reference }}</th>
                                    <th scope="col">{{ pt.col_period }}</th>
                                    <th scope="col">{{ pt.col_due }}</th>
                                    <th scope="col" class="text-end">{{ pt.col_amount }}</th>
                                    <th scope="col">{{ pt.col_method }}</th>
                                    <th scope="col">{{ pt.col_status }}</th>
                                    <th scope="col">{{ pt.col_paid_at }}</th>
                                    <th scope="col">
                                        <span class="visually-hidden">{{ pt.pay }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="invoice in invoices" :key="invoice.id" data-test="history-row">
                                    <td class="small">
                                        {{ invoice.reference ?? '—' }}
                                        <span
                                            v-if="invoice.kind === 'ai_credit_pack'"
                                            class="d-block text-muted"
                                            data-test="history-ai-pack"
                                            >{{ invoiceLabel(invoice) }}</span
                                        >
                                    </td>
                                    <td class="small">
                                        <template v-if="invoice.period_start">{{
                                            tx('period', {
                                                start: date(invoice.period_start),
                                                end: date(invoice.period_end),
                                            })
                                        }}</template>
                                        <template v-else>—</template>
                                    </td>
                                    <td class="small">{{ date(invoice.due_date) }}</td>
                                    <td class="text-end">{{ money(invoice.amount, invoice.currency || 'BRL') }}</td>
                                    <td class="small">{{ methodLabel(invoice.payment_method) }}</td>
                                    <td>
                                        <span class="badge" :class="invoiceBadge(invoice.status)">{{
                                            invoiceStatus(invoice.status)
                                        }}</span>
                                    </td>
                                    <td class="small">{{ invoice.paid_at ? dateTime(invoice.paid_at) : '—' }}</td>
                                    <td class="text-end">
                                        <button
                                            v-if="canPay(invoice)"
                                            type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            @click="openPay(invoice)"
                                        >
                                            {{ pt.pay }}<span class="visually-hidden"> {{ invoice.reference }}</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- celular: cartões -->
                    <ul class="list-unstyled mb-0 d-md-none">
                        <li v-for="invoice in invoices" :key="invoice.id" class="p-3 border-bottom">
                            <div class="d-flex justify-content-between gap-2">
                                <strong>{{ money(invoice.amount, invoice.currency || 'BRL') }}</strong>
                                <span class="badge" :class="invoiceBadge(invoice.status)">{{
                                    invoiceStatus(invoice.status)
                                }}</span>
                            </div>
                            <div class="small text-muted">
                                {{ invoiceLabel(invoice) }} · {{ pt.col_due }}: {{ date(invoice.due_date) }}
                            </div>
                            <div v-if="invoice.paid_at" class="small text-muted">
                                {{ pt.col_paid_at }}: {{ dateTime(invoice.paid_at) }}
                            </div>
                            <button
                                v-if="canPay(invoice)"
                                type="button"
                                class="btn btn-sm btn-primary mt-2 w-100"
                                @click="openPay(invoice)"
                            >
                                {{ pt.pay }}
                            </button>
                        </li>
                    </ul>
                </template>
            </section>
        </div>

        <!-- Checkout (pagar fatura / contratar) -->
        <CenteredModal :open="modal.open" size="md" :close-label="t.ui?.close" @close="closeModal">
            <template #header>
                <h2 class="h5 mb-0">
                    <i class="ti ti-lock me-2 text-success" aria-hidden="true"></i>{{ modal.title }}
                </h2>
            </template>
            <!-- Troca de plano de quem já paga: o que acontece, antes de pagar/confirmar. -->
            <div
                v-if="change && change.type !== 'current'"
                class="alert small"
                :class="
                    change.type === 'upgrade' ? 'alert-primary text-primary-emphasis' : 'alert-info text-info-emphasis'
                "
                role="note"
                data-test="plan-change-summary"
                :data-type="change.type"
            >
                <strong class="d-block mb-1">{{
                    change.type === 'upgrade' ? pt.upgrade_title : pt.scheduled_title
                }}</strong>
                {{ changeText }}
                <span v-if="change.type === 'upgrade'" class="d-block mt-1">{{ pt.upgrade_unpaid_note }}</span>
                <span v-else-if="change.reason === 'small_difference'" class="d-block mt-1">{{
                    pt.scheduled_small_difference
                }}</span>
            </div>

            <div v-if="change?.type === 'scheduled'" data-test="plan-change-scheduled">
                <p v-if="scheduled.done" class="alert alert-success text-success-emphasis" role="status">
                    <i class="ti ti-circle-check me-1" aria-hidden="true"></i
                    >{{ tx('scheduled_done', { date: date(scheduled.done) }) }}
                </p>
                <template v-else>
                    <p v-if="scheduled.error" class="text-danger small" role="alert">{{ scheduled.error }}</p>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="scheduled.busy"
                        data-test="plan-change-confirm"
                        @click="confirmScheduled"
                    >
                        <span
                            v-if="scheduled.busy"
                            class="spinner-border spinner-border-sm me-1"
                            aria-hidden="true"
                        ></span
                        >{{ pt.scheduled_confirm }}
                    </button>
                </template>
            </div>

            <CheckoutFlow
                v-else-if="modal.open"
                :key="modal.key"
                :t="t"
                :api="api"
                :payment="modal.kind === 'contract' ? modal.options : paymentFor(modal.invoice)"
                :invoice="modal.invoice"
                :contract="modal.contract"
                :amount="modal.kind === 'contract' ? modal.options?.amount : modal.invoice?.amount"
                :realtime="
                    modal.kind === 'contract' ? (modal.options?.realtime ?? checkout.realtime) : checkout.realtime
                "
                @paid="onPaid"
                @done="closeModal"
            />
        </CenteredModal>

        <!-- Trocar o cartão da renovação -->
        <CenteredModal :open="cardModal.open" size="md" :close-label="t.ui?.close" @close="cardModal.open = false">
            <template #header>
                <h2 class="h5 mb-0">
                    <i class="ti ti-credit-card me-2 text-primary" aria-hidden="true"></i>{{ pt.change_card_title }}
                </h2>
            </template>
            <p class="small text-muted">{{ pt.change_card_hint }}</p>
            <ReplaceCardFlow
                v-if="cardModal.open && payment?.card"
                :key="cardModal.key"
                :t="t"
                :api="api"
                :card="payment.card"
                :amount="subscription?.amount ?? 0"
                @saved="onCardSaved"
            />
        </CenteredModal>
    </AppLayout>
</template>

<style scoped>
.ee-my-sub__option {
    cursor: pointer;
    transition: border-color 0.15s;
}
.ee-my-sub__option--on {
    box-shadow: 0 0 0 0.15rem rgba(var(--bs-primary-rgb, 13, 110, 253), 0.15);
}
.ee-my-sub__dl dd {
    margin-bottom: 0.5rem;
}
</style>
