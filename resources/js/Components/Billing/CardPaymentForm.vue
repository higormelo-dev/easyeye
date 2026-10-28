<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useId } from 'vue';
import { createCardAdapter, CardSdkError } from '@/Support/billing/cardGateways.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Formulário do cartão com o SDK JS oficial do gateway, carregado só aqui:
 *  - Mercado Pago: Card Payment Brick (formulário e botão do próprio SDK);
 *  - Stripe: Payment Element (iframe) + createConfirmationToken; 3DS pelo
 *    handleNextAction (exposto ao CheckoutFlow);
 *  - Pagar.me: tokenizecard.js lendo os campos marcados com
 *    data-pagarmecheckout-element;
 *  - PagBank: PagSeguro.encryptCard com a chave pública.
 *
 * Os campos próprios (Pagar.me/PagBank) não têm `name` (nunca entram num
 * envio de formulário) e são limpos assim que o token sai. Só o token vai
 * para `submitCard` — que devolve uma Promise (rejeitada = recusado/erro; o
 * CheckoutFlow mostra a mensagem).
 */
const props = defineProps({
    config: { type: Object, required: true }, // objeto `card` do contrato
    amount: { type: [Number, String], default: 0 },
    mode: { type: String, default: 'payment' }, // payment | setup (troca de cartão)
    ui: { type: Object, default: () => ({}) },
    gatewayName: { type: String, default: '' },
    submitCard: { type: Function, required: true },
});

const { money, locale } = useLocaleFormat();
const { tx } = useTrans(() => props.ui);

const uid = useId();
const brickId = `ee-card-brick-${uid}`.replace(/[^A-Za-z0-9_-]/g, '-');
const fieldId = (name) => `ee-card-${name}-${uid}`;

const adapter = createCardAdapter(props.config);
const kind = computed(() => adapter?.kind ?? null);
const isPagarme = props.config?.gateway === 'pagarme';

const sdkState = ref(adapter ? 'loading' : 'unavailable'); // loading | ready | failed | unavailable
const busy = ref(false);
const localError = ref('');
const elementTarget = ref(null);

const fields = reactive({ holder: '', number: '', exp_month: '', exp_year: '', cvv: '' });

const installmentOptions = computed(() => (props.mode === 'setup' ? [] : (props.config?.installments ?? [])));
const installments = ref(1);
const maxInstallments = computed(() => Math.max(1, ...installmentOptions.value.map((o) => Number(o.count) || 1)));
const showInstallments = computed(() => kind.value !== 'brick' && installmentOptions.value.length > 1);
// Parcelado só na contratação: a renovação no cartão salvo é à vista no
// gateway (config.renewal_in_full) — o cliente é avisado antes de pagar.
const renewalInFull = computed(
    () => props.mode !== 'setup' && !!props.config?.renewal_in_full && installmentOptions.value.length > 1,
);

function installmentLabel(option) {
    return Number(option.count) > 1
        ? tx('installment_many', { count: option.count, amount: money(option.amount) })
        : tx('installment_one', { amount: money(option.total ?? option.amount) });
}

const submitLabel = computed(() =>
    props.mode === 'setup' ? props.ui.card_save : tx('card_pay', { amount: money(props.amount) }),
);

function clearSensitive() {
    fields.number = '';
    fields.cvv = '';
    fields.exp_month = '';
    fields.exp_year = '';
    fields.holder = '';
}

async function mountSdk() {
    if (!adapter) return;
    sdkState.value = 'loading';
    localError.value = '';
    await nextTick();

    const sdkLocale = String(locale.value || 'pt-BR').startsWith('pt') ? 'pt-BR' : 'en-US';

    try {
        if (adapter.kind === 'brick') {
            await adapter.mount(brickId, {
                amount: props.amount,
                maxInstallments: props.mode === 'setup' ? 1 : maxInstallments.value,
                locale: sdkLocale,
                onSubmit: (payload) => props.submitCard({ ...payload, installments: payload.installments || 1 }),
            });
        } else if (adapter.kind === 'element') {
            await adapter.mount(elementTarget.value, { amount: props.amount, mode: props.mode, locale: sdkLocale });
        } else {
            await adapter.mount();
        }
        sdkState.value = 'ready';
    } catch {
        sdkState.value = 'failed';
    }
}

async function onSubmit() {
    if (!adapter || busy.value || sdkState.value !== 'ready' || adapter.kind === 'brick') return;

    busy.value = true;
    localError.value = '';

    let token;
    try {
        // Pagar.me: o próprio script do gateway gera o token neste submit.
        token = await (isPagarme ? adapter.tokenize() : adapter.tokenize({ ...fields }));
    } catch (error) {
        localError.value =
            error instanceof CardSdkError && error.detail && !isPagarme && kind.value === 'element'
                ? error.detail
                : props.ui.card_invalid;
        busy.value = false;

        return;
    } finally {
        clearSensitive();
    }

    try {
        await props.submitCard({
            ...token,
            installments: props.mode === 'setup' ? 1 : Number(installments.value) || 1,
        });
    } catch {
        /* recusa/erro: o CheckoutFlow mostra a mensagem */
    } finally {
        busy.value = false;
    }
}

onMounted(mountSdk);

onBeforeUnmount(() => {
    clearSensitive();
    adapter?.destroy();
});

defineExpose({
    /** 3DS (Stripe): { ok, status?, error? } */
    handleNextAction: (nextAction) => adapter?.handleNextAction(nextAction) ?? Promise.resolve({ ok: false }),
});
</script>

<template>
    <div class="ee-card-form" data-test="checkout-card" :data-gateway="config.gateway">
        <p class="small text-muted d-flex align-items-start gap-2">
            <i class="ti ti-lock text-success mt-1" aria-hidden="true"></i>
            <span>{{ tx('card_secure_note', { gateway: gatewayName }) }}</span>
        </p>

        <p
            v-if="renewalInFull && sdkState !== 'unavailable'"
            class="small alert alert-info text-info-emphasis py-2"
            data-test="card-renewal-in-full"
        >
            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ ui.card_renewal_in_full }}
        </p>

        <div v-if="sdkState === 'unavailable'" class="alert alert-warning text-warning-emphasis small" role="alert">
            {{ ui.card_unavailable }}
        </div>

        <div
            v-else-if="sdkState === 'failed'"
            class="alert alert-danger text-danger-emphasis small"
            role="alert"
            data-test="card-sdk-failed"
        >
            <p class="mb-2">{{ ui.card_sdk_failed }}</p>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="mountSdk">
                <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ ui.retry }}
            </button>
        </div>

        <div v-if="sdkState === 'loading'" class="d-flex align-items-center gap-2 small text-muted mb-3" role="status">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ ui.card_loading_sdk }}
        </div>

        <!-- Mercado Pago: o Brick desenha campos, parcelas e o botão. -->
        <div v-if="kind === 'brick'" v-show="sdkState !== 'failed'" :id="brickId" data-test="card-brick"></div>

        <form
            v-else-if="kind"
            v-show="sdkState !== 'failed'"
            novalidate
            autocomplete="on"
            v-bind="isPagarme ? { 'data-pagarmecheckout-form': '' } : {}"
            data-test="card-form"
            @submit.prevent="onSubmit"
        >
            <!-- Stripe: Payment Element (iframe do Stripe). -->
            <div v-if="kind === 'element'" ref="elementTarget" class="mb-3" data-test="card-element"></div>

            <!-- Pagar.me / PagBank: campos sem `name` — só o SDK do gateway lê. -->
            <div v-else class="row g-2 mb-1">
                <div class="col-12">
                    <label :for="fieldId('holder')" class="form-label small fw-semibold mb-1">{{
                        ui.card_holder
                    }}</label>
                    <input
                        :id="fieldId('holder')"
                        v-model="fields.holder"
                        type="text"
                        class="form-control"
                        autocomplete="cc-name"
                        autocapitalize="characters"
                        spellcheck="false"
                        required
                        :disabled="sdkState !== 'ready' || busy"
                        v-bind="isPagarme ? { 'data-pagarmecheckout-element': 'holder_name' } : {}"
                    />
                </div>
                <div class="col-12">
                    <label :for="fieldId('number')" class="form-label small fw-semibold mb-1">{{
                        ui.card_number
                    }}</label>
                    <div class="input-group">
                        <input
                            :id="fieldId('number')"
                            v-model="fields.number"
                            type="text"
                            inputmode="numeric"
                            class="form-control font-monospace"
                            autocomplete="cc-number"
                            maxlength="23"
                            spellcheck="false"
                            required
                            :disabled="sdkState !== 'ready' || busy"
                            v-bind="isPagarme ? { 'data-pagarmecheckout-element': 'number' } : {}"
                        />
                        <span v-if="isPagarme" class="input-group-text" aria-hidden="true">
                            <span data-pagarmecheckout-element="brand"></span>
                            <i class="ti ti-credit-card"></i>
                        </span>
                    </div>
                </div>
                <div class="col-4">
                    <label :for="fieldId('month')" class="form-label small fw-semibold mb-1">{{
                        ui.card_exp_month
                    }}</label>
                    <input
                        :id="fieldId('month')"
                        v-model="fields.exp_month"
                        type="text"
                        inputmode="numeric"
                        class="form-control"
                        autocomplete="cc-exp-month"
                        maxlength="2"
                        required
                        :disabled="sdkState !== 'ready' || busy"
                        v-bind="isPagarme ? { 'data-pagarmecheckout-element': 'exp_month' } : {}"
                    />
                </div>
                <div class="col-4">
                    <label :for="fieldId('year')" class="form-label small fw-semibold mb-1">{{
                        ui.card_exp_year
                    }}</label>
                    <input
                        :id="fieldId('year')"
                        v-model="fields.exp_year"
                        type="text"
                        inputmode="numeric"
                        class="form-control"
                        autocomplete="cc-exp-year"
                        maxlength="4"
                        required
                        :disabled="sdkState !== 'ready' || busy"
                        v-bind="isPagarme ? { 'data-pagarmecheckout-element': 'exp_year' } : {}"
                    />
                </div>
                <div class="col-4">
                    <label :for="fieldId('cvv')" class="form-label small fw-semibold mb-1">{{ ui.card_cvv }}</label>
                    <input
                        :id="fieldId('cvv')"
                        v-model="fields.cvv"
                        type="password"
                        inputmode="numeric"
                        class="form-control"
                        autocomplete="cc-csc"
                        maxlength="4"
                        required
                        :disabled="sdkState !== 'ready' || busy"
                        v-bind="isPagarme ? { 'data-pagarmecheckout-element': 'cvv' } : {}"
                    />
                </div>
            </div>

            <div v-if="showInstallments" class="mb-3 mt-2">
                <label :for="fieldId('installments')" class="form-label small fw-semibold mb-1">{{
                    ui.card_installments
                }}</label>
                <select
                    :id="fieldId('installments')"
                    v-model.number="installments"
                    class="form-select"
                    data-test="card-installments"
                    :disabled="busy"
                >
                    <option v-for="option in installmentOptions" :key="option.count" :value="option.count">
                        {{ installmentLabel(option) }}
                    </option>
                </select>
            </div>

            <p v-if="localError" class="text-danger small mb-2" role="alert" data-test="card-local-error">
                {{ localError }}
            </p>

            <button
                type="submit"
                class="btn btn-primary w-100 mt-2"
                :disabled="sdkState !== 'ready' || busy"
                data-test="card-submit"
            >
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-lock me-1" aria-hidden="true"></i>{{ submitLabel }}
            </button>
        </form>

        <p v-if="config.saves_card && sdkState !== 'unavailable'" class="small text-muted mt-2 mb-0">
            <i class="ti ti-refresh-dot me-1" aria-hidden="true"></i
            >{{ tx('card_saved_note', { gateway: gatewayName }) }}
        </p>
    </div>
</template>
