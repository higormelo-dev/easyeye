<script setup>
import { computed, onMounted, ref } from 'vue';
import CheckoutFlow from './CheckoutFlow.vue';
import { checkoutError, createCheckoutApi, signupEndpoints } from '@/Support/billing/checkoutApi.js';

/**
 * Checkout do cadastro no site ("Contratar agora", sem o trial): logo depois
 * do POST /register, com a sessão da clínica recém-criada. Carrega as formas
 * de pagamento do plano/ciclo escolhido (signup-checkout.options) e contrata
 * pagando (signup-checkout.contract). Pago, segue para o painel.
 */
const props = defineProps({
    t: { type: Object, required: true }, // trans('checkout')
    labels: { type: Object, default: () => ({}) }, // tAuth.register
    planId: { type: String, required: true },
    cycle: { type: String, required: true },
    redirect: { type: String, default: '/panel/dashboard' },
});

const api = createCheckoutApi(signupEndpoints());

const options = ref(null);
const loading = ref(true);
const error = ref('');
const paid = ref(false);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        options.value = await api.options(props.planId, props.cycle);
    } catch (e) {
        error.value = checkoutError(e, props.t).message || props.labels.checkout_failed;
    } finally {
        loading.value = false;
    }
}

const contract = computed(() => ({ plan_id: props.planId, billing_cycle: props.cycle }));

function enterPanel() {
    window.location.href = props.redirect;
}

onMounted(load);
</script>

<template>
    <div class="ee-signup-checkout" data-test="signup-checkout">
        <div v-if="loading" class="text-center py-4 text-muted" role="status">
            <div class="spinner-border text-primary mb-2" aria-hidden="true"></div>
            <p class="small mb-0">{{ labels.checkout_loading }}</p>
        </div>

        <div v-else-if="error" class="alert alert-danger" role="alert" data-test="signup-checkout-error">
            <p class="mb-2">{{ error }}</p>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="load">
                {{ t.ui?.retry }}
            </button>
        </div>

        <CheckoutFlow
            v-else-if="options"
            :t="t"
            :api="api"
            :payment="options"
            :contract="contract"
            :amount="options.amount"
            :realtime="options.realtime"
            @paid="paid = true"
        >
            <template #paid>
                <button type="button" class="btn btn-primary" data-test="signup-enter-panel" @click="enterPanel">
                    {{ labels.go_to_panel }}
                </button>
            </template>
        </CheckoutFlow>

        <p v-if="!paid" class="text-center small mt-3 mb-0">
            <a :href="redirect" data-test="signup-pay-later">{{ labels.pay_later }}</a>
        </p>
    </div>
</template>

<style>
/*
 * O site (bundle site.js) não carrega o Bootstrap: este é o mínimo para o
 * checkout (os mesmos componentes do painel) ficar legível no cadastro —
 * tudo sob .ee-signup-checkout, sem vazar para o resto da página.
 */
.ee-signup-checkout {
    --ck-danger: #b42318;
    --ck-warning: #8a5a00;
    --ck-info: #0b5d73;
    --ck-success: #0f7b4b;
    color: var(--text, #1f2937);
    font-size: 15px;
}
.ee-signup-checkout .d-flex {
    display: flex;
}
.ee-signup-checkout .d-grid {
    display: grid;
}
.ee-signup-checkout .d-block {
    display: block;
}
.ee-signup-checkout .flex-column {
    flex-direction: column;
}
.ee-signup-checkout .flex-wrap {
    flex-wrap: wrap;
}
.ee-signup-checkout .flex-grow-1 {
    flex-grow: 1;
}
.ee-signup-checkout .flex-shrink-0 {
    flex-shrink: 0;
}
.ee-signup-checkout .min-w-0 {
    min-width: 0;
}
.ee-signup-checkout .align-items-center {
    align-items: center;
}
.ee-signup-checkout .align-items-start {
    align-items: flex-start;
}
.ee-signup-checkout .align-items-baseline {
    align-items: baseline;
}
.ee-signup-checkout .justify-content-between {
    justify-content: space-between;
}
.ee-signup-checkout .gap-2 {
    gap: 0.5rem;
}
.ee-signup-checkout .gap-3 {
    gap: 1rem;
}
.ee-signup-checkout .text-center {
    text-align: center;
}
.ee-signup-checkout .text-start {
    text-align: left;
}
.ee-signup-checkout .text-nowrap {
    white-space: nowrap;
}
.ee-signup-checkout .w-100 {
    width: 100%;
}
.ee-signup-checkout .small {
    font-size: 0.85em;
}
.ee-signup-checkout .fw-semibold {
    font-weight: 600;
}
.ee-signup-checkout .fs-1 {
    font-size: 2.25rem;
}
.ee-signup-checkout .fs-3 {
    font-size: 1.5rem;
}
.ee-signup-checkout .fs-4 {
    font-size: 1.3rem;
}
.ee-signup-checkout .h6 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--navy, #0f2551);
}
.ee-signup-checkout .font-monospace {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.ee-signup-checkout .text-muted {
    color: var(--text-muted, #64748b);
}
.ee-signup-checkout .text-body {
    color: var(--text, #1f2937);
}
.ee-signup-checkout .text-primary {
    color: var(--teal-ink, #007a93);
}
.ee-signup-checkout .text-success {
    color: var(--ck-success);
}
.ee-signup-checkout .text-danger {
    color: var(--ck-danger);
}
.ee-signup-checkout .border {
    border: 1px solid var(--border, #e2e8f0);
}
.ee-signup-checkout .border-top {
    border-top: 1px solid var(--border, #e2e8f0);
}
.ee-signup-checkout .border-bottom {
    border-bottom: 1px solid var(--border, #e2e8f0);
}
.ee-signup-checkout .rounded {
    border-radius: 8px;
}
.ee-signup-checkout .bg-white {
    background: #fff;
}
.ee-signup-checkout .img-fluid {
    max-width: 100%;
    height: auto;
}
.ee-signup-checkout .mb-0 {
    margin-bottom: 0;
}
.ee-signup-checkout .mb-1 {
    margin-bottom: 0.25rem;
}
.ee-signup-checkout .mb-2 {
    margin-bottom: 0.5rem;
}
.ee-signup-checkout .mb-3 {
    margin-bottom: 1rem;
}
.ee-signup-checkout .mt-1 {
    margin-top: 0.25rem;
}
.ee-signup-checkout .mt-2 {
    margin-top: 0.5rem;
}
.ee-signup-checkout .mt-3 {
    margin-top: 1rem;
}
.ee-signup-checkout .me-1 {
    margin-right: 0.25rem;
}
.ee-signup-checkout .me-2 {
    margin-right: 0.5rem;
}
.ee-signup-checkout .ms-1 {
    margin-left: 0.25rem;
}
.ee-signup-checkout .p-1 {
    padding: 0.25rem;
}
.ee-signup-checkout .pt-3 {
    padding-top: 1rem;
}
.ee-signup-checkout .pb-2 {
    padding-bottom: 0.5rem;
}
.ee-signup-checkout .py-3 {
    padding-top: 1rem;
    padding-bottom: 1rem;
}
.ee-signup-checkout .py-4 {
    padding-top: 1.5rem;
    padding-bottom: 1.5rem;
}
.ee-signup-checkout .px-0 {
    padding-left: 0;
    padding-right: 0;
}
.ee-signup-checkout .row {
    display: flex;
    flex-wrap: wrap;
    margin: -0.25rem;
}
.ee-signup-checkout .row > * {
    padding: 0.25rem;
    box-sizing: border-box;
}
.ee-signup-checkout .col {
    flex: 1 0 0;
}
.ee-signup-checkout .col-12 {
    flex: 0 0 100%;
}
.ee-signup-checkout .col-4 {
    flex: 0 0 33.333%;
}
.ee-signup-checkout .col-sm-auto {
    flex: 0 0 100%;
}
@media (min-width: 576px) {
    .ee-signup-checkout .flex-sm-row {
        flex-direction: row;
    }
    .ee-signup-checkout .align-items-sm-center {
        align-items: center;
    }
    .ee-signup-checkout .col-sm-auto {
        flex: 0 0 auto;
    }
}
.ee-signup-checkout fieldset {
    border: 0;
    margin: 0;
    padding: 0;
    min-width: 0;
}
.ee-signup-checkout .btn {
    min-height: 40px;
    padding: 10px 18px;
    border: 1.5px solid transparent;
    border-radius: 10px;
    font-size: 14px;
    background: none;
}
.ee-signup-checkout .btn-sm {
    min-height: 32px;
    padding: 6px 12px;
    font-size: 13px;
}
.ee-signup-checkout .btn-primary {
    background: var(--teal, #00b4d8);
    color: #fff;
}
.ee-signup-checkout .btn-success {
    background: var(--ck-success);
    color: #fff;
}
.ee-signup-checkout .btn-outline-primary {
    border-color: var(--teal, #00b4d8);
    color: var(--teal-ink, #007a93);
}
.ee-signup-checkout .btn-outline-secondary {
    border-color: var(--border, #e2e8f0);
    color: var(--text, #1f2937);
    background: #fff;
}
.ee-signup-checkout .btn-outline-secondary:hover {
    border-color: var(--teal, #00b4d8);
}
.ee-signup-checkout .btn-outline-danger {
    border-color: var(--ck-danger);
    color: var(--ck-danger);
}
.ee-signup-checkout .btn-link {
    color: var(--teal-ink, #007a93);
    text-decoration: underline;
    min-height: 0;
}
.ee-signup-checkout .btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.ee-signup-checkout .btn:focus-visible {
    outline: 3px solid var(--teal, #00b4d8);
    outline-offset: 2px;
}
.ee-signup-checkout .alert {
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 1rem;
    border: 1px solid transparent;
}
.ee-signup-checkout .alert-danger {
    background: #fdecea;
    border-color: #f5c2bd;
    color: var(--ck-danger);
}
.ee-signup-checkout .alert-warning {
    background: #fff6e0;
    border-color: #f3dca4;
    color: var(--ck-warning);
}
.ee-signup-checkout .alert-info {
    background: #e6f6fa;
    border-color: #b6e3ee;
    color: var(--ck-info);
}
.ee-signup-checkout .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    background: var(--bg, #f1f5f9);
    color: var(--text-muted, #64748b);
}
.ee-signup-checkout .form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--navy, #0f2551);
}
.ee-signup-checkout .form-control,
.ee-signup-checkout .form-select {
    width: 100%;
    box-sizing: border-box;
    border: 1.5px solid var(--border, #e2e8f0);
    border-radius: 10px;
    padding: 9px 12px;
    font-size: 15px;
    color: var(--text, #1f2937);
    background: #fff;
}
.ee-signup-checkout .form-control:focus,
.ee-signup-checkout .form-select:focus {
    outline: none;
    border-color: var(--teal, #00b4d8);
    box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.15);
}
.ee-signup-checkout .input-group {
    display: flex;
    align-items: stretch;
}
.ee-signup-checkout .input-group > .form-control {
    flex: 1 1 auto;
    min-width: 0;
    border-radius: 10px 0 0 10px;
}
.ee-signup-checkout .input-group > .btn,
.ee-signup-checkout .input-group > .input-group-text {
    border-radius: 0 10px 10px 0;
}
.ee-signup-checkout .input-group-text {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 12px;
    border: 1.5px solid var(--border, #e2e8f0);
    border-left: 0;
    background: var(--bg, #f1f5f9);
}
.ee-signup-checkout .spinner-border,
.ee-signup-checkout .spinner-grow {
    display: inline-block;
    width: 2rem;
    height: 2rem;
    vertical-align: middle;
    border-radius: 50%;
}
.ee-signup-checkout .spinner-border {
    border: 0.25em solid currentColor;
    border-right-color: transparent;
    animation: ee-ck-spin 0.75s linear infinite;
}
.ee-signup-checkout .spinner-grow {
    background: currentColor;
    opacity: 0;
    animation: ee-ck-grow 0.75s linear infinite;
}
.ee-signup-checkout .spinner-border-sm,
.ee-signup-checkout .spinner-grow-sm {
    width: 1rem;
    height: 1rem;
    border-width: 0.2em;
}
@keyframes ee-ck-spin {
    to {
        transform: rotate(360deg);
    }
}
@keyframes ee-ck-grow {
    0% {
        transform: scale(0);
    }
    50% {
        opacity: 1;
        transform: none;
    }
}
@media (prefers-reduced-motion: reduce) {
    .ee-signup-checkout .spinner-border,
    .ee-signup-checkout .spinner-grow {
        animation-duration: 1.5s;
    }
}
</style>
