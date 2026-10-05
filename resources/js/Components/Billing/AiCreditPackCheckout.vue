<script setup>
import { computed, onMounted, ref } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import CheckoutFlow from './CheckoutFlow.vue';
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
const { money, date } = useLocaleFormat();

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

async function buy(pkg) {
    if (opening.value) return;

    opening.value = pkg.code;
    error.value = '';
    paidNotice.value = '';
    try {
        const options = await api.aiPackOptions(pkg.code);
        if (!options?.allowed) {
            state.value = options;

            return;
        }
        modal.value = { open: true, options, pkg, invoice: null, key: modal.value.key + 1 };
    } catch (e) {
        error.value = checkoutError(e, props.t).message;
    } finally {
        opening.value = '';
    }
}

function closeModal() {
    modal.value = { ...modal.value, open: false };
    load();
}

// ── Pedido pendente: continuar pagando a fatura dele ou descartar ──────────
function orderPackage(order) {
    return {
        code: order.ai_credit_pack?.package_code ?? '',
        name: order.ai_credit_pack?.package_name ?? order.reference ?? '',
        credits: order.ai_credit_pack?.credits ?? '',
    };
}

function continueOrder(order) {
    if (!order?.payment) return;

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

function onPaid() {
    paidNotice.value = tx('ai_paid_notice', { credits: modal.value.pkg?.credits ?? '' });
    emit('paid', { package_code: modal.value.pkg?.code, credits: modal.value.pkg?.credits });
}

onMounted(() => {
    if (!props.initial) load();
});

defineExpose({ buy, reload: load });
</script>

<template>
    <div data-test="ai-credit-pack-checkout">
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
                                    tx('ai_pending_value', {
                                        credits: order.ai_credit_pack?.credits ?? '',
                                        amount: money(order.amount, order.currency || 'BRL'),
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

            <ul class="list-unstyled d-grid gap-2 mb-0" :aria-label="pt.ai_title">
                <li
                    v-for="pkg in packages"
                    :key="pkg.code"
                    class="border rounded p-2 d-flex flex-wrap align-items-center gap-2"
                    :class="{ 'border-primary': pkg.featured }"
                    data-test="ai-pack"
                    :data-code="pkg.code"
                >
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold">
                            {{ tx('ai_credits', { count: pkg.credits }) }}
                            <span v-if="pkg.featured" class="badge badge-soft-primary ms-1">{{ pt.ai_featured }}</span>
                        </div>
                        <div class="small text-muted">
                            {{ pkg.name }} · {{ tx('ai_unit_price', { price: pkg.unit_price_formatted }) }}
                        </div>
                    </div>
                    <strong class="text-nowrap">{{ pkg.price_formatted }}</strong>
                    <button
                        type="button"
                        class="btn btn-sm"
                        :class="pkg.featured ? 'btn-primary' : 'btn-outline-primary'"
                        :disabled="!allowed || !!opening"
                        :title="!allowed ? state.message || pt.ai_unavailable : undefined"
                        data-test="ai-pack-buy"
                        @click="buy(pkg)"
                    >
                        <span
                            v-if="opening === pkg.code"
                            class="spinner-border spinner-border-sm me-1"
                            aria-hidden="true"
                        ></span>
                        <i v-else class="ti ti-shopping-cart me-1" aria-hidden="true"></i>{{ pt.ai_buy }}
                        <span class="visually-hidden">{{ tx('ai_credits', { count: pkg.credits }) }}</span>
                    </button>
                </li>
            </ul>

            <div v-if="showRecent && purchases.length" class="mt-3">
                <div class="fw-semibold small mb-1">{{ pt.ai_recent }}</div>
                <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0">
                    <li
                        v-for="purchase in purchases"
                        :key="purchase.id"
                        class="border rounded px-2 py-1 small d-inline-flex gap-2 align-items-center"
                        data-test="ai-pack-purchase"
                    >
                        <span>{{ purchase.credits }} · {{ purchase.amount_formatted }}</span>
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
                    >{{ tx('ai_modal_title', { package: modal.pkg?.name ?? '', credits: modal.pkg?.credits ?? '' }) }}
                </h2>
            </template>
            <p class="small text-muted">
                {{ pt.ai_intro }} <span class="d-block mt-1">{{ pt.ai_card_in_full }}</span>
            </p>
            <CheckoutFlow
                v-if="modal.open"
                :key="modal.key"
                :t="t"
                :api="api"
                :payment="modal.options?.payment"
                :invoice="modal.invoice"
                :ai-pack="modal.invoice ? null : { package_code: modal.pkg?.code }"
                :amount="modal.invoice ? modal.invoice.amount : modal.pkg ? modal.pkg.price_cents / 100 : null"
                :realtime="modal.options?.realtime"
                @paid="onPaid"
                @done="closeModal"
            />
        </CenteredModal>
    </div>
</template>
