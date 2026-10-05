<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue';
import PixPayment from './PixPayment.vue';
import BoletoPayment from './BoletoPayment.vue';
import CardPaymentForm from './CardPaymentForm.vue';
import { checkoutError, newIdempotencyKey } from '@/Support/billing/checkoutApi.js';
import { unloadCheckoutSdks } from '@/Support/billing/loadScript.js';
import { useBillingRealtime } from '@/composables/useBillingRealtime.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Checkout transparente reutilizável (Minha assinatura, aviso de pagamento,
 * /subscription/expired e o cadastro no site).
 *
 * Três usos:
 *  - `invoice`: paga uma fatura em aberto (GET instructions — só lê; sem
 *    cobrança na forma escolhida, POST charge emite / POST card);
 *  - `contract` { plan_id, billing_cycle }: contrata (ou troca plano/ciclo)
 *    já pagando (POST contract — a contratação pendente do mesmo plano e
 *    ciclo é reaproveitada pelo servidor);
 *  - `aiPack` { package_code }: compra um pacote de créditos de IA já
 *    pagando (POST ai-credits; cartão à vista). Aberta a fatura do pacote,
 *    o resto segue como `invoice`.
 *
 * Formas: Pix (QR + copia-e-cola + validade), boleto (linha digitável +
 * PDF + vencimento), cartão (SDK oficial do gateway, só o token sai do
 * navegador; parcelas sem juros no anual) e, onde o gateway não tem
 * transparente (InfinitePay; cartão no Asaas), o link externo — avisando que
 * abre o site do gateway.
 *
 * Confirmação: evento `.invoice.paid` no canal privado billing.{entityId}
 * (tempo real, sem polling). Sem tempo real, "Já paguei — atualizar" relê o
 * resumo sob demanda. Cobrança ainda sendo gerada (charge_pending): novas
 * tentativas espaçadas, depois o botão "Tentar de novo".
 */
const props = defineProps({
    t: { type: Object, required: true }, // trans('checkout')
    api: { type: Object, required: true }, // createCheckoutApi(...)
    payment: { type: Object, default: null }, // { gateway, methods[], card? }
    invoice: { type: Object, default: null },
    contract: { type: Object, default: null }, // { plan_id, billing_cycle }
    aiPack: { type: Object, default: null }, // { package_code }
    amount: { type: [Number, String], default: null },
    realtime: { type: Object, default: null }, // { channel, event }
});

const emit = defineEmits(['paid', 'invoice', 'done']);

const RETRY_DELAYS = [3, 5, 8, 13, 21];

const ui = computed(() => props.t?.ui ?? {});
const { tx } = useTrans(() => ui.value);
const { money } = useLocaleFormat();

const headingId = `ee-checkout-step-${useId()}`;
const heading = ref(null);
const cardForm = ref(null);

const step = ref('choose'); // choose | loading | pix | boleto | card | link | processing | paid
const method = ref(null);
const instructions = ref(null);
const cardConfig = ref(null);
const paymentUrl = ref(null);
const error = ref(null);
const notice = ref('');
const checking = ref(false);
const currentInvoice = ref(props.invoice);
const retryState = ref({ count: 0, seconds: 0 });
let retryTimer = null;
let countdownTimer = null;
let lastCardAttempt = null; // { payload, key } — reenvio idêntico só em falha de rede/ocupado

const isContract = computed(() => !!props.contract);
const isAiPack = computed(() => !!props.aiPack);
// Ainda sem fatura: o 1º pedido contrata ou compra o pacote (depois, paga a fatura aberta).
const startsOrder = computed(() => (isContract.value || isAiPack.value) && !currentInvoice.value);

function placeOrder(payload, key) {
    return isAiPack.value
        ? props.api.aiPack({ ...props.aiPack, ...payload }, key)
        : props.api.contract({ ...props.contract, ...payload }, key);
}
const methods = computed(() => props.payment?.methods ?? []);
const gatewayCode = computed(() => cardConfig.value?.gateway ?? props.payment?.gateway ?? '');
const gatewayName = computed(
    () => props.t?.gateways?.[gatewayCode.value] ?? props.t?.gateways?.default ?? gatewayCode.value,
);
const displayAmount = computed(() => currentInvoice.value?.amount ?? props.amount);
const methodLabel = (code) => props.t?.methods?.[code] ?? code;

const maxInstallments = computed(() =>
    Math.max(1, ...(props.payment?.card?.installments ?? []).map((o) => Number(o.count) || 1)),
);

function methodHint(info) {
    if (info.method === 'pix') return ui.value.method_hint_pix;
    if (info.method === 'boleto') return ui.value.method_hint_boleto;
    const split = maxInstallments.value > 1 ? ` ${tx('method_hint_card_split', { count: maxInstallments.value })}` : '';

    return `${ui.value.method_hint_card}${split}`;
}

const methodIcon = (code) => ({ pix: 'ti-qrcode', boleto: 'ti-barcode', credit_card: 'ti-credit-card' })[code];

// Aguardando a confirmação (tempo real ouvindo).
const waiting = computed(() => ['pix', 'boleto', 'link', 'processing'].includes(step.value));

const statusMessage = computed(() => {
    switch (step.value) {
        case 'loading':
            return retryState.value.seconds ? tx('retrying', { seconds: retryState.value.seconds }) : ui.value.loading;
        case 'pix':
        case 'boleto':
        case 'link':
            return ui.value.pix_waiting;
        case 'processing':
            return ui.value.processing;
        case 'paid':
            return ui.value.paid_title;
        default:
            return '';
    }
});

const stepTitle = computed(
    () =>
        ({
            pix: ui.value.pix_title,
            boleto: ui.value.boleto_title,
            card: ui.value.card_title,
            link: tx('link_title', { gateway: gatewayName.value }),
            processing: ui.value.processing,
            paid: ui.value.paid_title,
        })[step.value] ?? '',
);

const safePaymentUrl = computed(() => {
    try {
        const url = new URL(String(paymentUrl.value ?? ''));

        return url.protocol === 'https:' ? url.href : '';
    } catch {
        return '';
    }
});

function go(next) {
    step.value = next;
    if (stepTitle.value) nextTick(() => heading.value?.focus({ preventScroll: false }));
}

function cancelRetry() {
    clearTimeout(retryTimer);
    clearInterval(countdownTimer);
    retryTimer = null;
    countdownTimer = null;
    retryState.value = { ...retryState.value, seconds: 0 };
}

function useInvoice(invoice) {
    if (!invoice?.id) return;
    currentInvoice.value = invoice;
    emit('invoice', invoice);
}

function markPaid() {
    cancelRetry();
    error.value = null;
    notice.value = '';
    go('paid');
    emit('paid', { invoice_id: currentInvoice.value?.id ?? null });
}

/** Cobrança ainda sendo gerada (Asaas): tenta de novo espaçado; esgotou, botão manual. */
function scheduleRetry() {
    cancelRetry();
    const count = retryState.value.count;

    if (count >= RETRY_DELAYS.length) {
        retryState.value = { count, seconds: 0 };
        error.value = { code: 'charge_pending', message: props.t?.errors?.charge_pending ?? '', retryable: true };
        step.value = 'choose';

        return;
    }

    const delay = RETRY_DELAYS[count];
    retryState.value = { count: count + 1, seconds: delay };
    step.value = 'loading';
    countdownTimer = setInterval(() => {
        retryState.value = { ...retryState.value, seconds: Math.max(0, retryState.value.seconds - 1) };
    }, 1000);
    retryTimer = setTimeout(() => {
        clearInterval(countdownTimer);
        load();
    }, delay * 1000);
}

function applyResponse(res) {
    if (res?.invoice) useInvoice(res.invoice);

    if (res?.status === 'paid') return markPaid();

    if (res?.mode === 'link') {
        paymentUrl.value = res.payment_url ?? null;

        return go('link');
    }

    if (method.value === 'credit_card') {
        cardConfig.value = res?.card ?? props.payment?.card ?? null;

        return go('card');
    }

    if (res?.retry === 'charge_pending' || !res?.instructions) return scheduleRetry();

    cancelRetry();
    instructions.value = res.instructions;
    go(method.value);
}

async function handleFailure(e) {
    const err = checkoutError(e, props.t);

    if (err.code === 'charge_pending') return scheduleRetry();

    // A fatura deixou de estar em aberto (paga em outra aba/pelo webhook)?
    if (['invoice_not_payable', 'nothing_to_pay'].includes(err.code) && currentInvoice.value) {
        if (await checkPaid(true)) return;
    }

    cancelRetry();
    error.value = err;
    step.value = method.value === 'credit_card' && cardConfig.value ? 'card' : 'choose';
}

async function load() {
    error.value = null;
    notice.value = '';
    step.value = 'loading';

    try {
        let res = startsOrder.value
            ? await placeOrder({ method: method.value }, newIdempotencyKey())
            : await props.api.instructions(currentInvoice.value.id, method.value);

        // A leitura nunca emite cobrança: sem cobrança nesta forma (ou Pix
        // vencido), a emissão é um POST — com o limite de pagamento.
        if (res?.issue_required && currentInvoice.value?.id) {
            res = await props.api.issueCharge(currentInvoice.value.id, method.value, newIdempotencyKey());
        }

        applyResponse(res);
    } catch (e) {
        await handleFailure(e);
    }
}

async function choose(code) {
    method.value = code;
    error.value = null;
    notice.value = '';
    cancelRetry();
    retryState.value = { count: 0, seconds: 0 };
    instructions.value = null;
    paymentUrl.value = null;

    const info = methods.value.find((m) => m.method === code);

    // Contratar no cartão transparente: o formulário já vem nas opções; o
    // POST contract leva o token.
    if (code === 'credit_card' && startsOrder.value && info?.mode === 'transparent' && props.payment?.card) {
        cardConfig.value = props.payment.card;

        return go('card');
    }

    await load();
}

function backToMethods() {
    cancelRetry();
    error.value = null;
    notice.value = '';
    lastCardAttempt = null;
    cardConfig.value = method.value === 'credit_card' ? null : cardConfig.value;
    step.value = 'choose';
}

async function sendCard(payload, key) {
    let res;
    try {
        res =
            isContract.value || startsOrder.value
                ? await placeOrder({ method: 'credit_card', ...payload }, key)
                : await props.api.payInvoice(currentInvoice.value.id, payload, key);
    } catch (e) {
        const err = checkoutError(e, props.t);
        // Reenvio idêntico (mesmo token e mesma chave) só quando o pedido pode
        // não ter chegado; recusa pede outro preenchimento (novo token).
        if (!['network', 'busy'].includes(err.code)) lastCardAttempt = null;
        await handleFailure(e);
        throw e;
    }

    lastCardAttempt = null;
    if (res?.invoice) useInvoice(res.invoice);

    if (res?.status === 'paid') return markPaid();

    if (res?.mode === 'link') {
        paymentUrl.value = res.payment_url ?? null;

        return go('link');
    }

    if (res?.status === 'requires_action') {
        notice.value = ui.value.card_3ds;
        const outcome = await cardForm.value?.handleNextAction(res.next_action);
        notice.value = '';

        if (!outcome?.ok) {
            error.value = { code: 'card_3ds', message: outcome?.error || ui.value.card_3ds_failed, retryable: false };
            throw new Error('3ds');
        }
    }

    // Aprovação assíncrona (3DS concluído, análise antifraude): aguarda o webhook.
    go('processing');
}

/** Token do SDK → servidor. Uma chave de idempotência por token. */
function submitCard(payload) {
    error.value = null;
    const attempt = { payload, key: newIdempotencyKey() };
    lastCardAttempt = attempt;

    return sendCard(attempt.payload, attempt.key);
}

async function retry() {
    if (lastCardAttempt) {
        error.value = null;
        try {
            await sendCard(lastCardAttempt.payload, lastCardAttempt.key);
        } catch {
            /* mensagem já na tela */
        }

        return;
    }

    retryState.value = { count: 0, seconds: 0 };
    if (method.value) await load();
}

/** Relê o resumo (sob demanda): a fatura já está paga? */
async function checkPaid(silent = false) {
    if (!currentInvoice.value?.id || checking.value) return false;

    checking.value = true;
    try {
        const summary = await props.api.summary();
        const row = (summary?.invoices ?? []).find((i) => i.id === currentInvoice.value.id);

        if (row?.status === 'paid') {
            markPaid();

            return true;
        }
        if (!silent) notice.value = ui.value.still_pending;
    } catch (e) {
        if (!silent) error.value = checkoutError(e, props.t);
    } finally {
        checking.value = false;
    }

    return false;
}

const { realtimeConnected } = useBillingRealtime(() => props.realtime, {
    onPaid: (payload) => {
        if (step.value === 'paid' || !(waiting.value || step.value === 'card')) return;
        if (currentInvoice.value?.id && payload?.invoice_id && payload.invoice_id !== currentInvoice.value.id) return;
        markPaid();
    },
    onResync: () => {
        if (waiting.value) checkPaid(true);
    },
    active: () => waiting.value,
});

onBeforeUnmount(() => {
    cancelRetry();
    // Saiu do checkout: nada dos SDKs de cartão fica carregado na página.
    unloadCheckoutSdks();
});

defineExpose({ choose, step });
</script>

<template>
    <div class="ee-checkout" data-test="checkout-flow" :data-step="step">
        <!-- Situação do pagamento para leitores de tela. -->
        <div class="visually-hidden" role="status" aria-live="polite" :aria-label="ui.status_region">
            {{ statusMessage }}
        </div>

        <div
            v-if="displayAmount !== null && step !== 'paid'"
            class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-3 pb-2 border-bottom"
        >
            <span class="text-muted small">
                {{ ui.amount }}
                <span v-if="currentInvoice?.reference" class="ms-1"
                    >· {{ tx('invoice_ref', { reference: currentInvoice.reference }) }}</span
                >
            </span>
            <strong class="fs-4" data-test="checkout-amount">{{ money(displayAmount) }}</strong>
        </div>

        <div
            v-if="error"
            class="alert alert-danger text-danger-emphasis d-flex flex-column flex-sm-row gap-2 align-items-sm-center"
            role="alert"
            data-test="checkout-error"
            :data-code="error.code"
        >
            <span class="flex-grow-1"
                ><i class="ti ti-alert-circle me-1" aria-hidden="true"></i>{{ error.message }}</span
            >
            <button
                v-if="error.retryable"
                type="button"
                class="btn btn-sm btn-outline-danger text-nowrap"
                data-test="checkout-retry"
                @click="retry"
            >
                <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ ui.retry }}
            </button>
        </div>

        <!-- Escolha da forma de pagamento -->
        <fieldset v-if="step === 'choose'" data-test="checkout-methods">
            <legend class="h6 fw-semibold mb-3">{{ ui.choose_method }}</legend>
            <div class="d-grid gap-2">
                <button
                    v-for="info in methods"
                    :key="info.method"
                    type="button"
                    class="btn btn-outline-secondary text-start d-flex align-items-center gap-3 py-3 ee-checkout__method"
                    :class="{ active: method === info.method && error }"
                    :data-method="info.method"
                    :data-mode="info.mode"
                    @click="choose(info.method)"
                >
                    <i :class="['ti fs-3 flex-shrink-0 text-primary', methodIcon(info.method)]" aria-hidden="true"></i>
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-block fw-semibold text-body">{{ info.label || methodLabel(info.method) }}</span>
                        <span class="d-block small text-muted">{{ methodHint(info) }}</span>
                        <span
                            v-if="info.mode === 'link'"
                            class="badge badge-soft-secondary mt-1"
                            data-test="method-external"
                        >
                            <i class="ti ti-external-link me-1" aria-hidden="true"></i
                            >{{ tx('method_external', { gateway: gatewayName }) }}
                        </span>
                    </span>
                    <i class="ti ti-chevron-right text-muted" aria-hidden="true"></i>
                </button>
            </div>
        </fieldset>

        <!-- Carregando / tentando de novo -->
        <div v-else-if="step === 'loading'" class="text-center py-4 text-muted" data-test="checkout-loading">
            <div class="spinner-border text-primary mb-2" aria-hidden="true"></div>
            <p class="mb-0 small">
                {{ retryState.seconds ? tx('retrying', { seconds: retryState.seconds }) : ui.loading }}
            </p>
        </div>

        <template v-else>
            <h3 :id="headingId" ref="heading" class="h6 fw-semibold mb-3" tabindex="-1">
                <i
                    v-if="step === 'paid'"
                    class="ti ti-circle-check text-success fs-1 d-block mb-2"
                    aria-hidden="true"
                ></i
                >{{ stepTitle }}
            </h3>

            <PixPayment
                v-if="step === 'pix' && instructions?.pix"
                :pix="instructions.pix"
                :amount="displayAmount"
                :ui="ui"
                @refresh="load"
            />

            <BoletoPayment
                v-else-if="step === 'boleto' && instructions?.boleto"
                :boleto="instructions.boleto"
                :ui="ui"
            />

            <div v-else-if="step === 'card'">
                <p v-if="notice" class="alert alert-info text-info-emphasis small" role="status">{{ notice }}</p>
                <CardPaymentForm
                    v-if="cardConfig"
                    ref="cardForm"
                    :config="cardConfig"
                    :amount="displayAmount"
                    :ui="ui"
                    :gateway-name="gatewayName"
                    :submit-card="submitCard"
                />
                <p v-else class="alert alert-warning text-warning-emphasis small">{{ ui.card_unavailable }}</p>
            </div>

            <div v-else-if="step === 'link'" data-test="checkout-link">
                <p class="small">{{ tx('link_body', { gateway: gatewayName }) }}</p>
                <a
                    v-if="safePaymentUrl"
                    :href="safePaymentUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn btn-primary"
                    data-test="checkout-external-link"
                >
                    <i class="ti ti-external-link me-1" aria-hidden="true"></i>{{ ui.link_button }}
                    <span class="visually-hidden">{{ ui.opens_new_tab }}</span>
                </a>
                <div v-else class="alert alert-warning text-warning-emphasis small mb-0">
                    <p class="mb-2">{{ ui.link_missing }}</p>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="load">
                        <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ ui.retry }}
                    </button>
                </div>
            </div>

            <div v-else-if="step === 'processing'" class="text-center py-3" data-test="checkout-processing">
                <div class="spinner-border text-primary mb-2" aria-hidden="true"></div>
                <p class="small text-muted mb-0">{{ tx('processing_hint', { gateway: gatewayName }) }}</p>
            </div>

            <div v-else-if="step === 'paid'" class="text-center" data-test="checkout-paid">
                <p>{{ ui.paid_body }}</p>
                <slot name="paid">
                    <button type="button" class="btn btn-primary" data-test="checkout-continue" @click="emit('done')">
                        {{ ui.continue }}
                    </button>
                </slot>
            </div>

            <!-- Aguardando a confirmação: tempo real ou, sem ele, conferir sob demanda. -->
            <div v-if="waiting" class="mt-3 pt-3 border-top small" data-test="checkout-waiting">
                <p v-if="step !== 'processing'" class="text-muted d-flex align-items-center gap-2 mb-2">
                    <span class="spinner-grow spinner-grow-sm text-primary" aria-hidden="true"></span>
                    {{ ui.pix_waiting }}
                </p>
                <p v-if="!realtimeConnected" class="text-muted mb-2" data-test="checkout-realtime-off">
                    {{ ui.realtime_off }}
                </p>
                <p v-if="notice" class="text-muted mb-2" role="status">{{ notice }}</p>
                <button
                    v-if="currentInvoice?.id"
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="checking"
                    data-test="checkout-check"
                    @click="checkPaid(false)"
                >
                    <span v-if="checking" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    <i v-else class="ti ti-refresh me-1" aria-hidden="true"></i>{{ ui.check_status }}
                </button>
            </div>

            <div v-if="!['paid', 'processing'].includes(step)" class="mt-3">
                <button type="button" class="btn btn-link btn-sm px-0" data-test="checkout-back" @click="backToMethods">
                    <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ ui.back_to_methods }}
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.ee-checkout__method {
    border-radius: 0.6rem;
    white-space: normal;
}
.ee-checkout__method:focus-visible {
    outline: 3px solid var(--bs-primary, #0d6efd);
    outline-offset: 2px;
}
</style>
