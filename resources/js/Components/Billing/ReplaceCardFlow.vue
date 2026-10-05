<script setup>
import { computed, ref } from 'vue';
import CardPaymentForm from './CardPaymentForm.vue';
import { checkoutError, newIdempotencyKey } from '@/Support/billing/checkoutApi.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Troca o cartão da renovação sem cobrar (PUT card). Stripe: SetupIntent —
 * se o banco pedir 3DS, o SDK confirma (handleNextAction) e o servidor
 * conclui com a referência `seti_…`. Gateway que não troca cartão sem um
 * pagamento (PagBank) responde card_change_unsupported: mostra o aviso.
 */
const props = defineProps({
    t: { type: Object, required: true },
    api: { type: Object, required: true },
    card: { type: Object, required: true }, // objeto `card` do contrato
    amount: { type: [Number, String], default: 0 },
});

const emit = defineEmits(['saved']);

const ui = computed(() => props.t?.ui ?? {});
const { tx } = useTrans(() => ui.value);
const gatewayName = computed(() => props.t?.gateways?.[props.card.gateway] ?? props.t?.gateways?.default ?? '');

const form = ref(null);
const error = ref(null);
const notice = ref('');
const saved = ref(null);

async function finish(result) {
    saved.value = result?.card ?? null;
    emit('saved', saved.value);
}

async function submitCard(payload) {
    error.value = null;
    notice.value = '';

    try {
        let result = await props.api.replaceCard({ card_token: payload.card_token }, newIdempotencyKey());

        if (result?.status === 'requires_action') {
            notice.value = ui.value.card_3ds;
            const outcome = await form.value?.handleNextAction(result.next_action);
            notice.value = '';

            if (!outcome?.ok || !result.next_action?.reference) {
                error.value = { message: outcome?.error || ui.value.card_3ds_failed };
                throw new Error('3ds');
            }

            result = await props.api.replaceCard({ card_token: result.next_action.reference }, newIdempotencyKey());
        }

        if (result?.status !== 'saved') throw new Error('not_saved');

        await finish(result);
    } catch (e) {
        if (!error.value) error.value = checkoutError(e, props.t);
        throw e;
    }
}
</script>

<template>
    <div data-test="replace-card">
        <div class="visually-hidden" role="status" aria-live="polite">
            {{ saved ? tx('card_saved', { brand: saved.brand ?? '', last4: saved.last4 ?? '' }) : notice }}
        </div>

        <div v-if="saved" class="text-center py-3" data-test="replace-card-saved">
            <i class="ti ti-circle-check text-success fs-1 d-block mb-2" aria-hidden="true"></i>
            <p class="fw-semibold mb-0">
                {{ tx('card_saved', { brand: saved.brand ?? '', last4: saved.last4 ?? '' }) }}
            </p>
        </div>

        <template v-else>
            <div
                v-if="error"
                class="alert alert-danger text-danger-emphasis"
                role="alert"
                data-test="replace-card-error"
            >
                <i class="ti ti-alert-circle me-1" aria-hidden="true"></i>{{ error.message }}
            </div>
            <p v-if="notice" class="alert alert-info text-info-emphasis small">{{ notice }}</p>
            <CardPaymentForm
                ref="form"
                :config="card"
                :amount="amount"
                mode="setup"
                :ui="ui"
                :gateway-name="gatewayName"
                :submit-card="submitCard"
            />
        </template>
    </div>
</template>
