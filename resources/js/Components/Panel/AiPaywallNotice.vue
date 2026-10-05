<script setup>
/**
 * AiPaywallNotice — aviso de "sem créditos de IA" com o próximo passo.
 *
 * Recebe o payload do AiPaywallService (props das telas de IA ou resposta
 * 422 `ai_insufficient_credits`): mensagem por situação (cortesia/trial sem
 * franquia, franquia do mês esgotada, cota que sobrou e acabou, contratação
 * aguardando o 1º pagamento, plano sem franquia) + "Comprar
 * créditos" para quem pode comprar ou "peça ao administrador" para os
 * demais. `limited` = acesso limitado pela régua de cobrança (402): orienta
 * a regularizar o pagamento, sem oferecer compra.
 *
 * `urgent`: o aviso veio da resposta de uma ação (422/402) — anunciado como
 * alerta. Vindo das props da tela, é só um status (não interrompe o leitor
 * de tela a cada página). Texto na cor de ênfase (contraste ≥ 4,5:1).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

const props = defineProps({
    paywall: { type: Object, default: null },
    limited: { type: Boolean, default: false },
    // Mensagem do servidor quando não há payload (ex.: 402 sem props de paywall).
    fallbackMessage: { type: String, default: '' },
    urgent: { type: Boolean, default: false },
    // Na própria tela de compra: o botão chama isto (abre os pacotes/checkout) em vez de navegar.
    onBuy: { type: Function, default: null },
});

const { date } = useLocaleFormat();

const texts = computed(() => props.paywall?.texts ?? {});

const message = computed(() => {
    if (props.limited) return texts.value.limited || props.fallbackMessage;

    const base = texts.value.message || props.fallbackMessage;
    // :date = renovação da franquia (condicionada ao pagamento, se em atraso)
    // ou, na cota que sobrou, até quando ela valia.
    const when = props.paywall?.renews_on || props.paywall?.renews_if_paid_on || props.paywall?.expires_on;
    return when ? base.replace(':date', date(when)) : base;
});

const canBuy = computed(
    () => !props.limited && !!props.paywall?.can_purchase && (!!props.paywall?.purchase_url || !!props.onBuy),
);
const showAskAdmin = computed(() => !props.limited && !canBuy.value && !!texts.value.ask_admin);
</script>

<template>
    <div
        class="alert alert-warning text-warning-emphasis py-2 small mb-0"
        :role="urgent ? 'alert' : 'status'"
        data-test="ai-paywall"
    >
        <div v-if="!limited && texts.title" class="fw-semibold mb-1">
            <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ texts.title }}
        </div>
        <div data-test="ai-paywall-message">
            <i v-if="limited" class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ message }}
        </div>
        <div v-if="canBuy" class="mt-2">
            <button
                v-if="onBuy"
                type="button"
                class="btn btn-sm btn-primary"
                data-test="ai-paywall-buy"
                @click="onBuy()"
            >
                <i class="ti ti-shopping-cart me-1" aria-hidden="true"></i>{{ texts.buy }}
            </button>
            <Link v-else :href="paywall.purchase_url" class="btn btn-sm btn-primary" data-test="ai-paywall-buy">
                <i class="ti ti-shopping-cart me-1" aria-hidden="true"></i>{{ texts.buy }}
            </Link>
        </div>
        <div v-else-if="showAskAdmin" class="mt-1 text-body-secondary" data-test="ai-paywall-ask-admin">
            <i class="ti ti-user-shield me-1" aria-hidden="true"></i>{{ texts.ask_admin }}
        </div>
    </div>
</template>
