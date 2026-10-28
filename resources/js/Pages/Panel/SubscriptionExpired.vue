<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Tela da empresa sem assinatura com acesso (não há período de graça): mostra
 * por que bloqueou — teste terminou, pagamento pendente ou assinatura
 * encerrada —, a última assinatura e os planos com o preço de cada ciclo
 * (mensal, anual...) para contratar pelo comercial.
 *
 * Modo "limited": cliente pagante em atraso com acesso limitado pela régua
 * de cobrança — explica o que está bloqueado (IA e financeiro), o que segue
 * liberado e volta ao painel. Com cobrança em aberto, "Pagar agora" — só
 * para admin, financeiro e dono (`canPay`); os demais perfis são orientados
 * a procurar o administrador. Sem o link da cobrança, diz por onde ela chega
 * e oferece o contato (que também fica sempre no rodapé).
 *
 * Checkout transparente (`urls.checkout`, só para quem paga): "Pagar agora"
 * abre o pagamento da fatura dentro do sistema (Minha assinatura) e
 * "Contratar" leva à contratação já pagando; o link externo da cobrança fica
 * só quando não há o checkout.
 */
const props = defineProps({
    mode: { type: String, default: 'blocked' },
    entity: { type: Object, default: null },
    lastSubscription: { type: Object, default: null },
    limited: { type: Object, default: null },
    payment: { type: Object, default: null },
    canPay: { type: Boolean, default: false },
    plans: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const { money, date } = useLocaleFormat();
const { tx } = useTrans(() => props.t);

const isLimited = computed(() => props.mode === 'limited');

// Acesso limitado / teste grátis acabou / aguardando o pagamento / assinatura encerrada.
const reason = computed(() => {
    if (isLimited.value) return 'limited';
    if (props.lastSubscription?.status === 'past_due') return 'payment';
    if (props.lastSubscription?.was_trial) return 'trial';

    return 'default';
});

const heading = computed(
    () =>
        ({ trial: props.t.heading_trial, payment: props.t.heading_payment, limited: props.t.heading_limited })[
            reason.value
        ] ?? props.t.heading,
);

const message = computed(() => {
    if (!props.entity) return props.t.blocked_generic;

    const key =
        { trial: 'blocked_trial', payment: 'blocked_payment', limited: 'blocked_limited' }[reason.value] ??
        'blocked_entity';

    return tx(key, { name: props.entity.name });
});

// Pagamento pendente ou em atraso, visto por quem não acessa a cobrança.
const askAdmin = computed(() => !props.canPay && ['limited', 'payment'].includes(reason.value));

// Pagar dentro do sistema (Minha assinatura com a fatura aberta).
const checkoutPayUrl = computed(() =>
    props.urls.checkout && props.payment?.invoice_id
        ? `${props.urls.checkout}?invoice=${encodeURIComponent(props.payment.invoice_id)}`
        : null,
);

// Quem pode pagar, sem o link da cobrança: por onde ela chega + falar com a equipe.
const noPaymentLink = computed(
    () => props.canPay && ['limited', 'payment'].includes(reason.value) && !props.payment?.url && !checkoutPayUrl.value,
);

// Contratar um plano já pagando (checkout) — sem ele, o comercial.
function contractUrl(plan) {
    if (!props.urls.checkout) return null;
    const params = new URLSearchParams({ plan: plan.id });
    const cycle = mainPrice(plan)?.cycle;
    if (cycle) params.set('cycle', cycle);

    return `${props.urls.checkout}?${params.toString()}`;
}

function mainPrice(plan) {
    return plan.prices?.find((p) => p.cycle === plan.default_cycle) ?? plan.prices?.[0] ?? null;
}
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="[]">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-10 col-xl-9">
                    <!-- Hero -->
                    <div class="text-center mb-5">
                        <div
                            class="d-inline-flex align-items-center justify-content-center mb-3 rounded-circle"
                            :class="isLimited ? 'bg-warning-subtle' : 'bg-danger-subtle'"
                            style="width: 96px; height: 96px"
                        >
                            <i
                                class="ti fs-1"
                                :class="isLimited ? 'ti-lock-access text-warning' : 'ti-lock text-danger'"
                                aria-hidden="true"
                            ></i>
                        </div>
                        <h1 class="h2 fw-bold mb-2" data-test="expired-heading">{{ heading }}</h1>
                        <p class="text-muted mb-0" data-test="expired-message">{{ message }}</p>
                    </div>

                    <!-- Cobrança em aberto: pagar agora -->
                    <section
                        v-if="payment?.url || checkoutPayUrl"
                        class="card border-primary shadow-sm mb-4"
                        aria-labelledby="expired-payment-title"
                        data-test="expired-payment"
                    >
                        <div class="card-body d-flex flex-column flex-md-row align-items-md-center gap-3">
                            <div class="flex-grow-1">
                                <h2 id="expired-payment-title" class="h6 fw-semibold mb-1">{{ t.payment_title }}</h2>
                                <p class="mb-1">
                                    {{
                                        tx('payment_due', {
                                            amount: money(payment.amount),
                                            date: date(payment.due_date),
                                        })
                                    }}
                                </p>
                                <p class="small text-muted mb-0">{{ t.payment_hint }}</p>
                            </div>
                            <Link
                                v-if="checkoutPayUrl"
                                :href="checkoutPayUrl"
                                class="btn btn-primary text-nowrap"
                                data-test="expired-pay-checkout"
                            >
                                <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ t.pay_now }}
                            </Link>
                            <a
                                v-else
                                :href="payment.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-primary text-nowrap"
                                data-test="expired-pay-now"
                            >
                                <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ t.pay_now }}
                                <span class="visually-hidden">{{ t.opens_new_tab }}</span>
                            </a>
                        </div>
                    </section>

                    <!-- Sem acesso à cobrança: procurar o administrador -->
                    <div
                        v-if="askAdmin"
                        class="alert alert-info text-info-emphasis d-flex align-items-start mb-4"
                        data-test="expired-ask-admin"
                    >
                        <i class="ti ti-user-shield fs-4 me-2 mt-1" aria-hidden="true"></i>
                        <p class="mb-0">{{ t.ask_admin }}</p>
                    </div>

                    <!-- Sem o link da cobrança: por onde ela chega e o contato -->
                    <div
                        v-if="noPaymentLink"
                        class="alert alert-info text-info-emphasis d-flex align-items-start mb-4"
                        data-test="expired-no-link"
                    >
                        <i class="ti ti-receipt fs-4 me-2 mt-1" aria-hidden="true"></i>
                        <p class="mb-0">
                            {{ t.no_link }}
                            <a :href="urls.contact" class="alert-link ms-1" data-test="expired-no-link-contact">{{
                                t.contact_link
                            }}</a>
                        </p>
                    </div>

                    <!-- Acesso limitado: o que está bloqueado e o que segue.
                         Texto na cor de ênfase (contraste ≥ 4,5:1), não no amarelo do tema. -->
                    <section
                        v-if="isLimited"
                        class="alert alert-warning text-warning-emphasis mb-4"
                        aria-labelledby="expired-limited-title"
                        data-test="expired-limited"
                    >
                        <h2 id="expired-limited-title" class="h6 fw-semibold">{{ t.limited_blocked_title }}</h2>
                        <ul class="mb-2 ps-3">
                            <li>{{ t.limited_blocked_ai }}</li>
                            <li>{{ t.limited_blocked_financial }}</li>
                        </ul>
                        <p class="mb-1">
                            <i class="ti ti-circle-check text-success me-1" aria-hidden="true"></i
                            >{{ t.limited_allowed }}
                        </p>
                        <p v-if="limited?.blocked_date" class="mb-0 fw-semibold">
                            {{ tx('limited_deadline', { date: date(limited.blocked_date) }) }}
                        </p>
                    </section>

                    <div v-if="isLimited" class="text-center mb-4">
                        <Link :href="urls.dashboard" class="btn btn-outline-primary" data-test="expired-back">
                            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ t.back_to_panel }}
                        </Link>
                    </div>

                    <!-- Última assinatura -->
                    <div
                        v-if="lastSubscription && !isLimited"
                        class="alert alert-warning text-warning-emphasis d-flex align-items-start mb-4"
                        data-test="expired-last-subscription"
                    >
                        <i class="ti ti-info-circle fs-4 me-2 mt-1" aria-hidden="true"></i>
                        <div>
                            <strong>{{ t.last_plan }}:</strong>
                            {{ lastSubscription.plan_name ?? '—' }}
                            <!-- Aguardando o pagamento não está encerrada: mostra o vencimento. -->
                            <span v-if="lastSubscription.due_at">
                                — {{ tx('due_on', { date: date(lastSubscription.due_at) }) }}</span
                            >
                            <span v-else-if="lastSubscription.ends_at">
                                — {{ tx('ended_on', { date: date(lastSubscription.ends_at) }) }}</span
                            >
                            <span v-if="lastSubscription.status_label" class="badge bg-secondary ms-2 fs-11">{{
                                lastSubscription.status_label
                            }}</span>
                        </div>
                    </div>

                    <!-- Planos (acesso limitado não troca de plano) -->
                    <h2 v-if="!isLimited" class="h5 fw-semibold mb-3">{{ t.choose_plan }}</h2>
                    <div v-if="!isLimited && plans.length === 0" class="alert alert-info text-info-emphasis">
                        {{ t.no_plans }}
                    </div>
                    <div v-else-if="!isLimited" class="row g-3 mb-4">
                        <div v-for="plan in plans" :key="plan.id" class="col-md-4">
                            <article class="card h-100 shadow-sm" :class="{ 'border-primary': plan.is_featured }">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <h3 class="h6 fw-bold mb-0">{{ plan.name }}</h3>
                                        <span v-if="plan.is_featured" class="badge badge-soft-primary">{{
                                            t.most_popular
                                        }}</span>
                                    </div>
                                    <p v-if="plan.description" class="text-muted small mb-3">{{ plan.description }}</p>

                                    <ul class="list-unstyled mb-3">
                                        <li
                                            v-for="price in plan.prices"
                                            :key="price.cycle"
                                            :class="
                                                price.cycle === mainPrice(plan)?.cycle ? 'mb-2' : 'small text-muted'
                                            "
                                        >
                                            <span
                                                :class="
                                                    price.cycle === mainPrice(plan)?.cycle
                                                        ? 'fs-3 fw-bold text-primary'
                                                        : 'fw-semibold'
                                                "
                                                >{{ money(price.price) }}</span
                                            >
                                            <small class="text-muted">{{ price.period_label }}</small>
                                            <span v-if="price.months > 1" class="d-block small text-muted">
                                                {{
                                                    tx('monthly_equivalent', { price: money(price.monthly_equivalent) })
                                                }}
                                                <strong v-if="price.savings_percent > 0" class="text-success">
                                                    · {{ tx('savings', { percent: price.savings_percent }) }}</strong
                                                >
                                            </span>
                                        </li>
                                    </ul>

                                    <ul class="list-unstyled small mb-3 flex-grow-1">
                                        <li v-for="feature in plan.features" :key="feature.key" class="mb-1">
                                            <i
                                                :class="[
                                                    'ti me-1',
                                                    feature.enabled ? 'ti-check text-success' : 'ti-minus text-muted',
                                                ]"
                                                aria-hidden="true"
                                            ></i>
                                            {{ feature.label }}
                                        </li>
                                    </ul>

                                    <Link
                                        v-if="contractUrl(plan)"
                                        :href="contractUrl(plan)"
                                        class="btn btn-primary btn-sm"
                                        data-test="expired-contract"
                                    >
                                        <i class="ti ti-shopping-cart me-1" aria-hidden="true"></i>{{ t.upgrade_cta }}
                                    </Link>
                                    <a v-else :href="urls.contact" class="btn btn-primary btn-sm">
                                        <i class="ti ti-message-dots me-1" aria-hidden="true"></i>{{ t.upgrade_cta }}
                                    </a>
                                </div>
                            </article>
                        </div>
                    </div>

                    <!-- Ações -->
                    <div class="text-center text-muted small">
                        <!-- Contato sempre visível, em qualquer modo. -->
                        <p class="mb-2" data-test="expired-contact">
                            {{ t.contact_support }}
                            <a :href="urls.contact" class="ms-1">{{ t.contact_link }}</a>
                        </p>
                        <Link :href="urls.logout" method="post" as="button" class="btn btn-link btn-sm">
                            <i class="ti ti-logout me-1" aria-hidden="true"></i>{{ t.logout }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
