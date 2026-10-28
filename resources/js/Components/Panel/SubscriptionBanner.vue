<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { choice } from '@/utils/billingPeriods.js';

/**
 * Aviso da situação da assinatura no topo do painel (prop compartilhada
 * `subscriptionBanner`, montada por App\Services\Billing\SubscriptionNoticeService):
 * pagamento pendente da contratação, pagamento em atraso, acesso limitado e
 * teste grátis terminando. Os avisos de pagamento não podem ser fechados;
 * o do trial pode (lembrado por navegador até a data mudar). Datas no
 * idioma do usuário. Quem não é admin, financeiro nem dono (`can_pay` =
 * false) vê o aviso sem link da fatura e a orientação de procurar o
 * administrador; quem pode pagar e não tem o link recebe o caminho do
 * contato (`contact_url`). Anunciado ao leitor de tela uma vez por sessão.
 *
 * Checkout transparente: com `checkout_url` (só para quem paga), "Pagar
 * agora" abre o pagamento DENTRO do sistema (Minha assinatura, fatura
 * `invoice_id`) e o trial terminando ganha "Contratar agora"; o link externo
 * da cobrança (`payment_url`) fica só como alternativa sem o checkout.
 */
const props = defineProps({
    banner: { type: Object, required: true },
});

const STORAGE_KEY = 'ee-subscription-banner-dismissed';

const { date } = useLocaleFormat();
const t = computed(() => props.banner.t ?? {});
const { tx } = useTrans(() => t.value);

const kind = computed(() => props.banner.kind);
const dismissKey = computed(() => `${kind.value}:${props.banner.due_date ?? ''}`);

function readDismissed() {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

const dismissed = ref(props.banner.dismissible === true && readDismissed() === dismissKey.value);

watch(dismissKey, (key) => {
    dismissed.value = props.banner.dismissible === true && readDismissed() === key;
});

function dismiss() {
    dismissed.value = true;
    try {
        window.localStorage.setItem(STORAGE_KEY, dismissKey.value);
    } catch {
        /* storage indisponível: o aviso some só até recarregar */
    }
}

// Atraso e acesso limitado pedem ação: anunciados como alerta.
const urgent = computed(() => ['overdue', 'limited'].includes(kind.value));

// Leitor de tela: o aviso é anunciado uma vez por sessão (por tipo e data).
// O layout não é persistente — cada navegação monta o aviso de novo —, então
// depois do primeiro anúncio ele vira uma região identificada, sem live
// region, e não interrompe a leitura a cada página.
const ANNOUNCED_KEY = 'ee-subscription-banner-announced';

function readAnnounced() {
    try {
        return window.sessionStorage.getItem(ANNOUNCED_KEY);
    } catch {
        return null;
    }
}

function markAnnounced(key) {
    try {
        window.sessionStorage.setItem(ANNOUNCED_KEY, key);
    } catch {
        /* storage indisponível: anuncia de novo na próxima página */
    }
}

const announce = ref(readAnnounced() !== dismissKey.value);

onMounted(() => {
    if (announce.value) markAnnounced(dismissKey.value);
});

watch(dismissKey, (key) => {
    announce.value = readAnnounced() !== key;
    if (announce.value) markAnnounced(key);
});

const PAYMENT_KINDS = ['first_payment_pending', 'overdue', 'limited'];
const isPaymentNotice = computed(() => PAYMENT_KINDS.includes(kind.value));

// Aviso de pagamento para quem não vê a fatura: procurar o administrador.
const askAdmin = computed(() => props.banner.can_pay === false && isPaymentNotice.value);

// Checkout no próprio sistema (Minha assinatura): a fatura do aviso, ou contratar no fim do trial.
const checkoutHref = computed(() => {
    const b = props.banner;
    if (!b.checkout_url || b.can_pay === false) return null;
    if (isPaymentNotice.value) {
        return b.invoice_id ? `${b.checkout_url}?invoice=${encodeURIComponent(b.invoice_id)}` : b.checkout_url;
    }

    return kind.value === 'trial_ending' ? b.checkout_url : null;
});

// Aviso de pagamento sem o link da cobrança: por onde ela chega + falar com a equipe.
const noLink = computed(
    () => isPaymentNotice.value && !checkoutHref.value && !props.banner.payment_url && !!props.banner.contact_url,
);

const tone = computed(
    () => ({ limited: 'danger', overdue: 'warning', first_payment_pending: 'warning' })[kind.value] ?? 'info',
);

const icon = computed(
    () =>
        ({
            limited: 'ti-lock-access',
            overdue: 'ti-alert-triangle',
            first_payment_pending: 'ti-receipt',
        })[kind.value] ?? 'ti-hourglass',
);

const daysOverdueText = computed(() => {
    const days = Number(props.banner.days_overdue ?? 0);

    return days > 0 ? choice(t.value.days_overdue, days, { days }) : t.value.days_overdue_today;
});

const title = computed(
    () =>
        ({
            first_payment_pending: t.value.first_payment_title,
            overdue: t.value.overdue_title,
            limited: t.value.limited_title,
            trial_ending: t.value.trial_title,
        })[kind.value] ?? '',
);

const body = computed(() => {
    const b = props.banner;

    switch (kind.value) {
        case 'first_payment_pending':
            return tx('first_payment_body', { date: date(b.due_date) });
        case 'overdue':
            return tx('overdue_body', {
                days: daysOverdueText.value,
                limited_date: date(b.limited_date),
                blocked_date: date(b.blocked_date),
            });
        case 'limited':
            return tx('limited_body', { days: daysOverdueText.value, blocked_date: date(b.blocked_date) });
        case 'trial_ending': {
            const days = Number(b.days_left ?? 0);
            const left = days > 0 ? choice(t.value.trial_days_left, days, { days }) : t.value.trial_today;

            return tx('trial_body', { date: date(b.due_date), days: left });
        }
        default:
            return '';
    }
});

// 1ª exibição na sessão: alerta (atraso/limitado) ou status; depois, região.
const liveAttrs = computed(() => {
    if (!announce.value) return { role: 'region', 'aria-label': title.value };

    return urgent.value ? { role: 'alert', 'aria-live': 'assertive' } : { role: 'status', 'aria-live': 'polite' };
});
</script>

<template>
    <!--
        Contraste (WCAG ≥ 4,5:1 nos temas claro e escuro): o texto usa a cor de
        ênfase do Bootstrap (text-*-emphasis), não a cor do tom do tema (amarelo
        sobre quase branco dava 1,8:1); o tom fica na faixa à esquerda.
    -->
    <div
        v-if="!dismissed && title"
        class="alert ee-subscription-banner m-0 rounded-0 border-0 border-bottom d-flex flex-column flex-md-row align-items-md-center gap-2 py-2 px-3"
        :class="[`alert-${tone}`, `text-${tone}-emphasis`]"
        :style="{ boxShadow: `inset 4px 0 0 var(--${tone})` }"
        v-bind="liveAttrs"
        data-test="subscription-banner"
        :data-kind="kind"
    >
        <div class="d-flex align-items-start gap-2 flex-grow-1 min-w-0">
            <i :class="['ti fs-16 mt-1 flex-shrink-0', icon]" aria-hidden="true"></i>
            <p class="mb-0">
                <strong class="me-1">{{ title }}</strong>
                <span data-test="subscription-banner-body">{{ body }}</span>
                <span v-if="askAdmin" class="ms-1" data-test="subscription-banner-ask-admin">{{ t.ask_admin }}</span>
                <span v-if="noLink" class="ms-1" data-test="subscription-banner-no-link">{{ t.no_link }}</span>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 ms-md-auto flex-shrink-0">
            <!-- Botão primário (branco sobre azul, 9,5:1): o amarelo do tom dava 1,9:1. -->
            <Link
                v-if="checkoutHref"
                :href="checkoutHref"
                class="btn btn-sm btn-primary text-nowrap"
                data-test="subscription-banner-checkout"
            >
                <i class="ti ti-credit-card me-1" aria-hidden="true"></i
                >{{ isPaymentNotice ? t.pay_now : t.subscribe_now }}
            </Link>
            <a
                v-else-if="banner.payment_url"
                :href="banner.payment_url"
                target="_blank"
                rel="noopener noreferrer"
                class="btn btn-sm btn-primary text-nowrap"
                data-test="subscription-banner-pay"
            >
                <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ t.pay_now }}
                <span class="visually-hidden">{{ t.opens_new_tab }}</span>
            </a>
            <a
                v-else-if="banner.contact_url"
                :href="banner.contact_url"
                class="btn btn-sm btn-outline-primary text-nowrap"
                data-test="subscription-banner-contact"
            >
                <i class="ti ti-message-dots me-1" aria-hidden="true"></i
                >{{ isPaymentNotice ? t.contact : t.choose_plan }}
            </a>
            <button
                v-if="banner.dismissible"
                type="button"
                class="btn-close"
                :aria-label="t.dismiss"
                data-test="subscription-banner-dismiss"
                @click="dismiss"
            ></button>
        </div>
    </div>
</template>
