<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import CheckoutFlow from './CheckoutFlow.vue';
import AiCreditPackCard from './AiCreditPackCard.vue';
import { checkoutError, createCheckoutApi, panelEndpoints } from '@/Support/billing/checkoutApi.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Pacotes de créditos de IA pagos no checkout do próprio sistema (Pix,
 * boleto, cartão à vista) — tela de IA e Minha assinatura.
 *
 * Lê GET /panel/my-subscription/ai-credits (pode comprar? pacotes, compras
 * recentes); "Comprar" busca as formas de pagamento com o valor do pacote e
 * abre o CheckoutFlow no modo `aiPack`. Os créditos entram na carteira
 * quando o pagamento é confirmado (cartão aprovado ou webhook) — a tela
 * recebe o aviso em tempo real (billing.{entityId}) e emite `paid`.
 *
 * Sem acesso total (acesso limitado bloqueia a IA) ou sem IA no plano: a
 * mensagem do servidor no lugar dos botões.
 *
 * `showPending` (tela de IA): pedidos ainda não pagos (`open_orders`) com
 * "Continuar pagamento" (abre o checkout da fatura do pedido) e
 * "Descartar" (com confirmação — DELETE ai-credits/{invoice}). Em Minha
 * assinatura os pedidos já aparecem à parte, então fica desligado.
 */
const props = defineProps({
    t: { type: Object, required: true }, // trans('checkout')
    initial: { type: Object, default: null }, // resposta de options já carregada
    showRecent: { type: Boolean, default: true },
    showPending: { type: Boolean, default: false },
});

const emit = defineEmits(['paid']);

const api = createCheckoutApi(panelEndpoints());
const pt = computed(() => props.t?.page ?? {});
const { tx } = useTrans(() => pt.value);

const state = ref(props.initial);
const loading = ref(!props.initial);
const error = ref('');
const opening = ref('');
const paidNotice = ref('');
const modal = ref({ open: false, options: null, pkg: null, invoice: null, key: 0 });
const { money, moneyCents, date } = useLocaleFormat();

const uid = useId();
const selectedCode = ref('premium');
const customAmount = ref('1000');
const attempted = ref(false);
const failedCode = ref('');
const modalError = ref('');
const selected = computed(() => packages.value.find((pkg) => pkg.code === selectedCode.value) ?? packages.value[0]);
const custom = computed(() => packages.value.find((pkg) => pkg.custom));
const customMin = computed(() => (custom.value?.custom_min_cents ?? 100000) / 100);
const customMax = computed(() => (custom.value?.custom_max_cents ?? 1000000) / 100);
const validCustom = computed(
    () =>
        String(customAmount.value).trim() !== '' &&
        Number.isSafeInteger(Number(customAmount.value)) &&
        Number(customAmount.value) >= customMin.value &&
        Number(customAmount.value) <= customMax.value,
);
const selectedCents = computed(() =>
    selected.value?.custom
        ? validCustom.value
            ? Number(customAmount.value) * 100
            : null
        : selected.value?.value_cents,
);
const customError = computed(() =>
    attempted.value && selected.value?.custom && !validCustom.value
        ? tx('ai_custom_error', { min: money(customMin.value), max: money(customMax.value) })
        : '',
);
const checkoutPayload = computed(() => ({
    package_code: modal.value.pkg?.code,
    ...(modal.value.pkg?.custom ? { custom_amount_cents: modal.value.pkg?.value_cents } : {}),
}));

const packages = computed(() => state.value?.packages ?? []);
const openOrders = computed(() => (props.showPending ? (state.value?.open_orders ?? []) : []));
const allowed = computed(() => !!state.value?.allowed);
const purchases = computed(() => state.value?.purchases ?? []);
const invoiceStatus = (status) => props.t?.invoice_status?.[status] ?? status ?? '';

async function load() {
    loading.value = true;
    error.value = '';
    try {
        state.value = await api.aiPackOptions();
    } catch (e) {
        error.value = checkoutError(e, props.t).message;
    } finally {
        loading.value = false;
    }
}

async function buy(pkg = selected.value) {
    attempted.value = true;
    if (!pkg || !allowed.value || (pkg.custom && !validCustom.value)) return;
    const purchase = { ...pkg, value_cents: pkg.custom ? Number(customAmount.value) * 100 : pkg.value_cents };
    modalError.value = '';
    failedCode.value = pkg.code;
    if (opening.value) return;

    opening.value = pkg.code;
    error.value = '';
    paidNotice.value = '';
    try {
        const options = await api.aiPackOptions(pkg.code, pkg.custom ? purchase.value_cents : null);
        if (!options?.allowed) {
            state.value = options;

            return;
        }
        modal.value = { open: true, options, pkg: purchase, invoice: null, key: modal.value.key + 1 };
    } catch (e) {
        error.value = checkoutError(e, props.t).message;
    } finally {
        opening.value = '';
    }
}

function closeModal() {
    modalError.value = '';
    modal.value = { ...modal.value, open: false };
    load();
}

// ── Pedido pendente: continuar pagando a fatura dele ou descartar ──────────
function orderPackage(order) {
    return {
        code: order.ai_credit_pack?.package_code ?? '',
        name: order.ai_credit_pack?.package_name ?? order.reference ?? '',
        value_cents: order.ai_credit_pack?.value_cents ?? order.amount_cents,
    };
}

function continueOrder(order) {
    if (!order?.payment) return;
    modalError.value = '';

    error.value = '';
    paidNotice.value = '';
    discard.value = { id: null, busy: false, error: '' };
    modal.value = {
        open: true,
        options: { payment: order.payment, realtime: state.value?.realtime ?? null },
        pkg: orderPackage(order),
        invoice: order,
        key: modal.value.key + 1,
    };
}

const discard = ref({ id: null, busy: false, error: '' });
const discardedNotice = ref('');

function askDiscard(order) {
    discardedNotice.value = '';
    discard.value = { id: order.id, busy: false, error: '' };
}

function keepOrder() {
    discard.value = { id: null, busy: false, error: '' };
}

async function confirmDiscard(order) {
    if (discard.value.busy) return;

    discard.value = { id: order.id, busy: true, error: '' };
    try {
        await api.aiPackDiscard(order.id);
        discard.value = { id: null, busy: false, error: '' };
        discardedNotice.value = pt.value.ai_pack_discarded ?? '';
        await load();
    } catch (e) {
        discard.value = { id: order.id, busy: false, error: checkoutError(e, props.t).message };
    }
}

function onCheckoutError(failure) {
    modalError.value = failure.message;
    error.value = failure.message;
    failedCode.value = modal.value.pkg?.code;
}

function onPaid() {
    error.value = '';
    modalError.value = '';
    paidNotice.value = tx('ai_balance_paid_notice', { amount: moneyCents(modal.value.pkg?.value_cents) });
    emit('paid', { package_code: modal.value.pkg?.code, wallet_credit_cents: modal.value.pkg?.value_cents });
}

onMounted(() => {
    if (!props.initial) load();
});

defineExpose({ buy, reload: load });
</script>

<template>
    <div data-test="ai-credit-pack-checkout" :aria-busy="loading || !!opening">
        <div aria-live="polite">
            <p v-if="paidNotice" class="alert alert-success text-success-emphasis small" data-test="ai-pack-paid">
                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ paidNotice }}
            </p>
        </div>
        <p v-if="error" class="alert alert-danger text-danger-emphasis small" role="alert">{{ error }}</p>
        <p
            v-if="discardedNotice"
            class="alert alert-info text-info-emphasis small"
            role="status"
            data-test="ai-pack-discarded"
        >
            {{ discardedNotice }}
        </p>

        <div v-if="loading" class="text-muted small py-2">
            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ t.ui?.loading }}
        </div>

        <template v-else-if="state">
            <!-- Pedidos não pagos (tela de IA): continuar pagando ou descartar. -->
            <section
                v-if="openOrders.length"
                class="border border-warning-subtle rounded p-2 mb-3"
                aria-labelledby="ai-pack-pending-title"
                data-test="ai-pack-pending"
            >
                <h3 id="ai-pack-pending-title" class="small fw-semibold mb-1">
                    <i class="ti ti-hourglass me-1 text-warning" aria-hidden="true"></i>{{ pt.ai_pending_title }}
                </h3>
                <p class="small text-muted mb-2">{{ pt.ai_pending_hint }}</p>
                <ul class="list-unstyled mb-0 d-grid gap-2">
                    <li
                        v-for="order in openOrders"
                        :key="order.id"
                        class="d-flex flex-column flex-sm-row align-items-sm-center gap-2"
                        data-test="ai-pack-pending-order"
                        :data-id="order.id"
                    >
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold">
                                {{
                                    tx('ai_balance_pending_value', {
                                        amount: moneyCents(order.ai_credit_pack?.value_cents ?? order.amount_cents),
                                    })
                                }}
                            </div>
                            <div class="small text-muted">
                                {{ tx('ai_pack_open_created', { date: date(order.created_at ?? order.due_date) }) }}
                            </div>
                            <p
                                v-if="discard.id === order.id && discard.error"
                                class="text-danger small mb-0 mt-1"
                                role="alert"
                                data-test="ai-pack-pending-error"
                            >
                                {{ discard.error }}
                            </p>
                        </div>
                        <div
                            v-if="discard.id === order.id && !discard.error"
                            class="d-flex flex-wrap align-items-center gap-2"
                            role="group"
                            data-test="ai-pack-pending-confirm"
                        >
                            <span class="small">{{ pt.ai_pack_discard_confirm }}</span>
                            <button
                                type="button"
                                class="btn btn-sm btn-danger text-nowrap"
                                :disabled="discard.busy"
                                data-test="ai-pack-pending-discard-yes"
                                @click="confirmDiscard(order)"
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
                                data-test="ai-pack-pending-keep"
                                @click="keepOrder"
                            >
                                {{ pt.ai_pack_discard_keep }}
                            </button>
                        </div>
                        <div v-else class="d-flex flex-wrap gap-2">
                            <button
                                v-if="order.can_pay && order.payment"
                                type="button"
                                class="btn btn-sm btn-primary text-nowrap"
                                data-test="ai-pack-pending-continue"
                                @click="continueOrder(order)"
                            >
                                <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ pt.ai_pending_continue }}
                            </button>
                            <button
                                v-if="order.can_discard"
                                type="button"
                                class="btn btn-sm btn-outline-secondary text-nowrap"
                                data-test="ai-pack-pending-discard"
                                @click="askDiscard(order)"
                            >
                                <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ pt.ai_pack_discard }}
                            </button>
                        </div>
                    </li>
                </ul>
            </section>

            <p
                v-if="!allowed"
                class="alert alert-warning text-warning-emphasis small mb-2"
                role="status"
                data-test="ai-pack-blocked"
                :data-reason="state.reason"
            >
                <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ state.message || pt.ai_unavailable }}
            </p>

            <fieldset class="border-0 p-0 m-0" :disabled="!allowed || !!opening" :aria-describedby="`${uid}-summary`">
                <legend class="h5 fw-semibold mb-3">{{ pt.ai_choose_package }}</legend>
                <div class="ai-pack-grid">
                    <AiCreditPackCard
                        v-for="pkg in packages"
                        :key="pkg.code"
                        v-model="selectedCode"
                        :pkg="pkg"
                        :name="`${uid}-package`"
                        :value-id="`${uid}-${pkg.code}-value`"
                        :error-id="`${uid}-${pkg.code}-error`"
                        :error="failedCode === pkg.code ? error : ''"
                        :t="pt"
                        @update:model-value="attempted = false"
                    />
                </div>
                <div v-if="selected?.custom" class="mt-3">
                    <label :for="`${uid}-custom`" class="form-label">{{ pt.ai_custom_amount }}</label>
                    <input
                        :id="`${uid}-custom`"
                        v-model="customAmount"
                        type="number"
                        inputmode="numeric"
                        :min="customMin"
                        :max="customMax"
                        step="1"
                        class="form-control"
                        :aria-invalid="customError ? 'true' : undefined"
                        :aria-describedby="`${uid}-custom-help${customError ? ` ${uid}-custom-error` : ''}`"
                        data-test="ai-pack-custom"
                        @blur="attempted = true"
                    />
                    <p :id="`${uid}-custom-help`" class="form-text">
                        {{ tx('ai_custom_hint', { min: money(customMin), max: money(customMax) }) }}
                    </p>
                    <p v-if="customError" :id="`${uid}-custom-error`" class="text-danger small" role="alert">
                        {{ customError }}
                    </p>
                </div>
                <p :id="`${uid}-summary`" class="fw-semibold mt-3 mb-2" aria-live="polite" data-test="ai-pack-summary">
                    {{ tx('ai_receives', { amount: moneyCents(selectedCents) }) }}
                </p>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="!allowed || !!opening || !selected"
                    data-test="ai-pack-buy"
                    @click="buy()"
                >
                    <span v-if="opening" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ pt.ai_buy }}
                </button>
            </fieldset>

            <div v-if="showRecent && purchases.length" class="mt-3">
                <div class="fw-semibold small mb-1">{{ pt.ai_recent }}</div>
                <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0">
                    <li
                        v-for="purchase in purchases"
                        :key="purchase.id"
                        class="border rounded px-2 py-1 small d-inline-flex gap-2 align-items-center"
                        data-test="ai-pack-purchase"
                    >
                        <span>{{ moneyCents(purchase.wallet_credit_cents ?? purchase.amount_cents) }}</span>
                        <span class="text-muted">{{ purchase.created_at }}</span>
                        <span class="badge badge-soft-secondary">{{
                            purchase.status === 'pending_payment' && purchase.invoice
                                ? invoiceStatus(purchase.invoice.status)
                                : purchase.status_label
                        }}</span>
                    </li>
                </ul>
            </div>
        </template>

        <CenteredModal :open="modal.open" size="md" :close-label="t.ui?.close" @close="closeModal">
            <template #header>
                <h2 class="h5 mb-0">
                    <i class="ti ti-lock me-2 text-success" aria-hidden="true"></i
                    >{{
                        tx('ai_balance_modal_title', {
                            package: modal.pkg?.name ?? '',
                            amount: moneyCents(modal.pkg?.value_cents),
                        })
                    }}
                </h2>
            </template>
            <p class="small text-muted">
                {{ pt.ai_intro }} <span class="d-block mt-1">{{ pt.ai_card_in_full }}</span>
            </p>
            <p v-if="modalError" class="alert alert-danger" role="alert" data-test="ai-pack-modal-error">
                {{ modalError }}
            </p>
            <CheckoutFlow
                v-if="modal.open"
                :key="modal.key"
                :t="t"
                :api="api"
                :payment="modal.options?.payment"
                :invoice="modal.invoice"
                :ai-pack="modal.invoice ? null : checkoutPayload"
                :amount="modal.invoice ? modal.invoice.amount : modal.pkg ? modal.pkg.value_cents / 100 : null"
                :realtime="modal.options?.realtime"
                @error="onCheckoutError"
                @paid="onPaid"
                @done="closeModal"
            />
        </CenteredModal>
    </div>
</template>

<style scoped>
.ai-pack-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}
@media (max-width: 991px) {
    .ai-pack-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 479px) {
    .ai-pack-grid {
        grid-template-columns: 1fr;
    }
}
</style>
