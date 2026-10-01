<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { generalErrors, withQuery } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * "Marcar como glosada": mostra a guia (código, paciente só pelo nome, valor),
 * a escolha Total/Parcial (parcial: valor limitado ao da guia) e o efeito
 * (atendimento volta para "A faturar"). Depois de glosar, o modal vira a
 * confirmação com o próximo passo: "Abrir conciliação" (só guia TISS — guia
 * particular não gera glosa na Conciliação).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    claim: { type: Object, default: null },
    glosaReasons: { type: Array, default: () => [] },
    glosasUrl: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const FIELDS = ['glosa_amount', 'glosa_code', 'notes'];
const TYPE_TOTAL = 'total';
const TYPE_PARTIAL = 'partial';

const { tx } = useTrans(() => props.t);
const { money } = useLocaleFormat();

const form = useForm({
    glosa_amount: null,
    glosa_code: '',
    notes: '',
});

const rootRef = ref(null);
const doneRef = ref(null);
const denyType = ref(TYPE_TOTAL);
const partialAmount = ref(null);
const partialError = ref('');
const done = ref(false);

const claimAmount = computed(() => Number(props.claim?.amount ?? 0));
const otherErrors = computed(() => generalErrors(form.errors, FIELDS));
const isPartial = computed(() => denyType.value === TYPE_PARTIAL);
const glosaValue = computed(() => (isPartial.value ? Number(partialAmount.value ?? 0) : claimAmount.value));
const remaining = computed(() => Math.max(0, Math.round((claimAmount.value - glosaValue.value) * 100) / 100));
const amountError = computed(() => partialError.value || form.errors.glosa_amount || '');

// Glosa parcial: o atendimento também volta para "A faturar", mas refaturá-lo
// antes de receber o restante trava esta guia (o servidor recusa o recebimento).
const effectHint = computed(() =>
    isPartial.value && remaining.value > 0
        ? tx('deny_effect_hint_partial', { remaining: money(remaining.value) })
        : props.t.deny_effect_hint,
);

/** Link para a Conciliação já filtrada pela guia (busca pelo código GUI). */
const conciliationUrl = computed(() => withQuery(props.glosasUrl, { search: props.claim?.code }));

const amountDescribedBy = computed(() =>
    ['billing-deny-amount-hint', amountError.value ? 'billing-deny-amount-error' : null].filter(Boolean).join(' '),
);

watch(
    () => props.open,
    (open) => {
        if (!open || !props.claim) return;

        done.value = false;
        denyType.value = TYPE_TOTAL;
        partialAmount.value = null;
        partialError.value = '';

        form.defaults({ glosa_amount: claimAmount.value, glosa_code: '', notes: '' });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

watch(denyType, () => {
    partialError.value = '';
});

function onGlosaReasonSelected(reason) {
    if (reason && !form.notes) form.notes = reason.description;
}

function requestClose() {
    if (!form.processing) emit('close');
}

/** Parcial: maior que zero e até o valor da guia (o servidor também barra acima). */
function validatePartial() {
    if (!isPartial.value) return true;

    const value = partialAmount.value;

    if (value === null || value === undefined || value === '') {
        partialError.value = props.t.deny_amount_required;
    } else if (!(Number(value) > 0)) {
        partialError.value = props.t.deny_amount_min;
    } else if (Number(value) > claimAmount.value) {
        partialError.value = tx('deny_amount_max', { max: money(claimAmount.value) });
    } else {
        partialError.value = '';
    }

    return partialError.value === '';
}

function submit() {
    if (!props.claim || form.processing || done.value) return;

    form.clearErrors();
    if (!validatePartial()) return;

    form.glosa_amount = glosaValue.value;

    form.post(props.claim.mark_denied_url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            done.value = true;
            emit('saved', props.claim);
            nextTick(() => doneRef.value?.focus?.());
        },
    });
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <CenteredModal :open="open" size="md" @close="requestClose">
        <template #header>
            <h2 class="h5 mb-0 fw-semibold">
                <template v-if="done"
                    ><i class="ti ti-circle-check me-2 text-success" aria-hidden="true"></i
                    >{{ t.deny_done_title }}</template
                >
                <template v-else
                    ><i class="ti ti-receipt-off me-2 text-danger" aria-hidden="true"></i>{{ t.deny_title }}</template
                >
            </h2>
        </template>

        <div v-if="claim && done" ref="doneRef" tabindex="-1" role="status" data-test="deny-done">
            <p class="mb-0">
                {{
                    claim.is_tiss === false
                        ? tx('denied_particular', { code: claim.code })
                        : tx('denied_next_step', { code: claim.code })
                }}
            </p>
        </div>

        <form v-else-if="claim" ref="rootRef" novalidate @submit.prevent="submit">
            <dl class="row small border rounded p-2 mx-0 mb-3 bg-body-tertiary" data-test="deny-summary">
                <dt class="col-5 fw-normal text-muted">{{ t.deny_summary_guide }}</dt>
                <dd class="col-7 mb-1 fw-semibold">
                    {{ claim.code }}
                    <small
                        v-if="claim.guide_number && claim.guide_number !== claim.code"
                        class="d-block fw-normal text-muted"
                        >{{ claim.guide_number }}</small
                    >
                </dd>
                <dt class="col-5 fw-normal text-muted">{{ t.deny_summary_patient }}</dt>
                <dd class="col-7 mb-1">{{ claim.patient_name || '—' }}</dd>
                <dt class="col-5 fw-normal text-muted">{{ t.deny_summary_amount }}</dt>
                <dd class="col-7 mb-0 fw-semibold">{{ money(claim.amount) }}</dd>
            </dl>

            <div
                v-for="message in otherErrors"
                :key="message"
                class="alert alert-danger small py-2"
                role="alert"
                data-test="deny-error"
            >
                {{ message }}
            </div>

            <fieldset class="mb-3">
                <legend class="form-label fs-6 mb-1">{{ t.deny_type_legend }}</legend>
                <div class="form-check form-check-inline">
                    <input
                        id="billing-deny-type-total"
                        v-model="denyType"
                        class="form-check-input"
                        type="radio"
                        name="billing-deny-type"
                        value="total"
                        data-test="deny-type-total"
                    />
                    <label class="form-check-label" for="billing-deny-type-total">{{
                        tx('deny_type_total', { amount: money(claim.amount) })
                    }}</label>
                </div>
                <div class="form-check form-check-inline">
                    <input
                        id="billing-deny-type-partial"
                        v-model="denyType"
                        class="form-check-input"
                        type="radio"
                        name="billing-deny-type"
                        value="partial"
                        data-test="deny-type-partial"
                    />
                    <label class="form-check-label" for="billing-deny-type-partial">{{ t.deny_type_partial }}</label>
                </div>
            </fieldset>

            <div class="row g-3">
                <div v-if="isPartial" class="col-12 col-sm-6">
                    <label for="billing-deny-amount" class="form-label">
                        {{ t.deny_amount }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <MoneyInput
                        id="billing-deny-amount"
                        v-model="partialAmount"
                        size=""
                        required
                        aria-required="true"
                        :invalid="!!amountError"
                        :aria-describedby="amountDescribedBy"
                        data-test="deny-amount"
                    />
                    <div
                        v-if="amountError"
                        id="billing-deny-amount-error"
                        class="invalid-feedback d-block"
                        role="alert"
                        data-test="deny-amount-error"
                    >
                        {{ amountError }}
                    </div>
                    <small id="billing-deny-amount-hint" class="form-text d-block" data-test="deny-remaining">
                        {{ tx('deny_amount_limit', { max: money(claim.amount) }) }}
                        {{ tx('deny_amount_hint', { remaining: money(remaining) }) }}
                    </small>
                </div>
                <div class="col-12" :class="{ 'col-sm-6': isPartial }">
                    <label id="billing-deny-code-label" class="form-label">{{ t.deny_code }}</label>
                    <SearchSelect
                        v-model="form.glosa_code"
                        :options="glosaReasons"
                        value-key="code"
                        label-key="label"
                        :placeholder="t.deny_code_placeholder"
                        :invalid="!!form.errors.glosa_code"
                        aria-labelledby="billing-deny-code-label"
                        @option-selected="onGlosaReasonSelected"
                    />
                    <div v-if="form.errors.glosa_code" class="invalid-feedback d-block">
                        {{ form.errors.glosa_code }}
                    </div>
                </div>
                <div class="col-12">
                    <label for="billing-deny-notes" class="form-label">{{ t.deny_notes }}</label>
                    <textarea
                        id="billing-deny-notes"
                        v-model="form.notes"
                        rows="3"
                        maxlength="1000"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.notes }"
                    ></textarea>
                    <div v-if="form.errors.notes" class="invalid-feedback">{{ form.errors.notes }}</div>
                </div>
            </div>

            <div class="alert alert-warning small py-2 mt-3 mb-0" data-test="deny-effect">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ effectHint }}
            </div>
        </form>

        <template #footer>
            <template v-if="done">
                <button type="button" class="btn btn-light" data-test="deny-close" @click="emit('close')">
                    {{ t.btn_close }}
                </button>
                <Link
                    v-if="claim && claim.is_tiss !== false && glosasUrl"
                    :href="conciliationUrl"
                    class="btn btn-primary"
                    data-test="open-conciliation"
                >
                    <i class="ti ti-gavel me-1" aria-hidden="true"></i>{{ t.btn_open_conciliation }}
                </Link>
            </template>
            <template v-else>
                <button type="button" class="btn btn-light" :disabled="form.processing" @click="requestClose">
                    {{ t.btn_cancel }}
                </button>
                <button
                    type="button"
                    class="btn btn-danger"
                    data-test="confirm-deny"
                    :disabled="form.processing"
                    @click="submit"
                >
                    <span
                        v-if="form.processing"
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    {{ form.processing ? t.processing : t.btn_confirm_deny }}
                </button>
            </template>
        </template>
    </CenteredModal>
</template>
