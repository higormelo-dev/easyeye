<script setup>
import { computed, defineAsyncComponent, nextTick, onMounted, ref } from 'vue';
import axios from 'axios';
import { Head } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import SignupCheckout from '@/Components/Billing/SignupCheckout.vue';

// O site não importa Echo no entry-point. O listener só existe depois do
// mount e carrega a configuração ANTES do componente que assina o canal.
const SignupCheckoutReturnRealtime = defineAsyncComponent(() =>
    import('@/echo.js').then(() => import('@/Components/Billing/SignupCheckoutReturnRealtime.vue')),
);

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },
    tAuth: { type: Object, default: () => ({}) },
    tCheckout: { type: Object, default: () => ({}) },
    routes: { type: Object, default: () => ({}) },
    status: { type: String, default: 'success' },
    invoice: { type: Object, default: null },
    subscription_active: { type: Boolean, default: false },
    next_step: { type: String, default: 'verify_email' },
    next_url: { type: String, default: '' },
    summary_url: { type: String, default: '' },
});

const statusTarget = ref(null);
const summary = ref(null);
const refreshing = ref(false);
const refreshError = ref('');
const paid = ref(false);
const realtimeEnabled = ref(false);

const checkoutTexts = computed(() => props.tCheckout?.page ?? props.t?.page ?? props.tCheckout ?? {});
const checkoutUi = computed(() => props.tCheckout?.ui ?? {});
const authTexts = computed(() => props.tAuth?.register ?? props.tAuth ?? {});
const invoiceFromSummary = computed(() => {
    if (!props.invoice?.id) return null;
    const invoices = summary.value?.invoices ?? [];

    return invoices.find((row) => row.id === props.invoice?.id) ?? props.invoice;
});
const invoicePaid = computed(() => invoiceFromSummary.value?.status === 'paid');
const isPaid = computed(() => paid.value || props.subscription_active || invoicePaid.value);
const canRetry = computed(
    () =>
        !!props.invoice?.id &&
        ['cancel', 'expired'].includes(props.status) &&
        !isPaid.value &&
        invoiceFromSummary.value?.can_pay !== false,
);
const retryPlan = computed(() => summary.value?.subscription?.plan ?? summary.value?.plan ?? null);
const retryPlanId = computed(() => retryPlan.value?.id ?? '');
const retryCycle = computed(() => summary.value?.subscription?.cycle ?? summary.value?.cycle ?? '');
const realtime = computed(() => summary.value?.realtime ?? null);
const resultMessage = computed(() => {
    if (isPaid.value) return checkoutUi.value.paid_title ?? checkoutTexts.value.checkout_return_success;
    if (props.status === 'cancel') return checkoutTexts.value.checkout_return_cancel;
    if (props.status === 'expired') return checkoutTexts.value.checkout_return_expired;

    return checkoutTexts.value.checkout_return_success;
});
const resultClass = computed(() => (isPaid.value ? 'success' : props.status === 'success' ? 'info' : 'warning'));
const next = computed(() => {
    const steps = {
        verify_email: { href: '/verify-email', label: 'Verificar e-mail' },
        verify_phone: { href: '/verify-phone', label: 'Verificar WhatsApp' },
        panel: { href: '/panel/dashboard', label: authTexts.value.go_to_panel ?? 'Entrar no painel' },
    };

    const fallback = steps[props.next_step] ?? steps.panel;

    return { ...fallback, href: props.next_url || fallback.href };
});

async function refreshSummary() {
    if (!props.invoice?.id || !props.summary_url || refreshing.value) return;

    refreshing.value = true;
    refreshError.value = '';
    try {
        const response = await axios.get(props.summary_url, { headers: { Accept: 'application/json' } });
        summary.value = response.data?.data ?? response.data ?? null;
        if (summary.value?.subscription?.status === 'active' || invoiceFromSummary.value?.status === 'paid')
            paid.value = true;
    } catch {
        refreshError.value = props.tCheckout?.errors?.unknown ?? checkoutUi.value.still_pending;
    } finally {
        refreshing.value = false;
    }
}

function paymentConfirmed() {
    paid.value = true;
    refreshSummary();
}

function onRealtimePaid(event) {
    if (props.invoice?.id && event?.invoice_id === props.invoice.id) paymentConfirmed();
}

onMounted(async () => {
    await nextTick();
    statusTarget.value?.focus({ preventScroll: false });
    realtimeEnabled.value = true;
    await refreshSummary();
});
</script>

<template>
    <Head
        ><title>{{ appName }} — pagamento</title></Head
    >

    <SiteLayout :t="t" :app-name="appName" :has-hero="false" :routes="routes">
        <main class="signup-return container" aria-labelledby="checkout-return-title">
            <section class="signup-return-card">
                <SignupCheckoutReturnRealtime
                    v-if="realtimeEnabled && realtime?.channel && !isPaid"
                    :realtime="realtime"
                    @paid="onRealtimePaid"
                    @resync="refreshSummary"
                />
                <h1 id="checkout-return-title">{{ checkoutTexts.awaiting_confirmation ?? 'Status do pagamento' }}</h1>
                <div
                    ref="statusTarget"
                    class="signup-return-status"
                    :class="`signup-return-status--${resultClass}`"
                    role="status"
                    tabindex="-1"
                    data-testid="checkout-return-status"
                >
                    {{ resultMessage }}
                </div>

                <p v-if="invoiceFromSummary?.id" class="signup-return-reference">
                    {{ invoiceFromSummary.reference ?? invoiceFromSummary.id }}
                </p>

                <div v-if="!isPaid" class="signup-return-actions">
                    <button type="button" class="signup-return-refresh" :disabled="refreshing" @click="refreshSummary">
                        {{ refreshing ? checkoutUi.processing : checkoutUi.check_status }}
                    </button>
                    <p v-if="refreshError" class="signup-return-error" role="alert">{{ refreshError }}</p>
                </div>

                <div v-if="isPaid" class="signup-return-next" data-testid="checkout-return-next">
                    <p>Próximo passo: {{ next.label }}</p>
                    <a class="signup-return-primary" :href="next.href">{{ next.label }}</a>
                </div>

                <SignupCheckout
                    v-else-if="canRetry && retryPlanId && retryCycle"
                    :key="`${retryPlanId}-${retryCycle}`"
                    :t="tCheckout"
                    :labels="authTexts"
                    :plan-id="retryPlanId"
                    :cycle="retryCycle"
                    redirect="/panel/dashboard"
                />
            </section>
        </main>
    </SiteLayout>
</template>

<style scoped>
.signup-return {
    max-width: 720px;
    padding-block: 3rem;
}
.signup-return-card {
    background: #fff;
    border: 1px solid var(--border, #dbe3ec);
    border-radius: 14px;
    box-shadow: 0 8px 24px rgb(15 37 81 / 8%);
    padding: clamp(1.25rem, 4vw, 2.5rem);
}
.signup-return-card h1 {
    color: var(--navy, #0f2551);
    font-size: clamp(1.35rem, 3vw, 1.8rem);
    margin: 0 0 1rem;
}
.signup-return-status {
    border-radius: 8px;
    font-weight: 600;
    line-height: 1.5;
    padding: 1rem;
}
.signup-return-status:focus-visible,
.signup-return-refresh:focus-visible,
.signup-return-primary:focus-visible {
    outline: 3px solid var(--teal, #00b4d8);
    outline-offset: 3px;
}
.signup-return-status--success {
    background: #ddf6e9;
    color: #075c35;
}
.signup-return-status--info {
    background: #e6f6fa;
    color: #07566a;
}
.signup-return-status--warning {
    background: #fff3d6;
    color: #754b00;
}
.signup-return-reference {
    color: var(--text-muted, #526171);
    font-size: 0.875rem;
}
.signup-return-actions,
.signup-return-next {
    margin-top: 1.25rem;
}
.signup-return-refresh,
.signup-return-primary {
    background: var(--teal, #008fad);
    border: 1px solid var(--teal, #008fad);
    border-radius: 8px;
    color: #fff;
    display: inline-block;
    font-weight: 700;
    padding: 0.7rem 1rem;
    text-decoration: none;
}
.signup-return-refresh:disabled {
    cursor: wait;
    opacity: 0.7;
}
.signup-return-error {
    color: #a61b14;
    margin-bottom: 0;
}
</style>
