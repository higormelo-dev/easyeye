<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import CidField from './CidField.vue';
import { generalErrors } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * Guia individual de um atendimento. Mostra o resumo do atendimento, sugere o
 * valor unitário pela tabela de preços (procedimento × convênio), calcula o
 * total ao vivo (qtd × valor) e avisa que guia TISS individual precisa entrar
 * num lote para ir à operadora. Fechar com dados digitados pede confirmação.
 */
const props = defineProps({
    open:               { type: Boolean, default: false },
    schedule:           { type: Object,  default: null },
    covenants:          { type: Array,   default: () => [] },
    tussCodes:          { type: Array,   default: () => [] },
    url:                { type: String,  required: true },
    cid10SearchUrl:     { type: String,  default: '' },
    procedurePricesUrl: { type: String,  default: '' },
    t:                  { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const FIELDS = [
    'schedule_id', 'quantity', 'unit_price', 'due_date', 'tuss_code', 'procedure_description',
    'authorization_code', 'eye_side', 'clinical_indication', 'notes',
];

const { tx } = useTrans(() => props.t);
const { money, number, dateTime } = useLocaleFormat();

const form = useForm({
    schedule_id:           '',
    quantity:              1,
    unit_price:            null,
    due_date:              '',
    tuss_code:             '',
    procedure_description: '',
    authorization_code:    '',
    eye_side:              '',
    clinical_indication:   '',
    notes:                 '',
});

const rootRef           = ref(null);
const confirmingDiscard = ref(false);

const otherErrors = computed(() => generalErrors(form.errors, FIELDS.filter((f) => f !== 'schedule_id')));
const covenant    = computed(() => props.covenants.find((c) => c.id === props.schedule?.covenant_id) ?? null);
const isTiss      = computed(() => Boolean(covenant.value?.has_ans_registry));

/** Preço da tabela (procedimento × convênio) — só sugestão, o usuário confirma. */
const suggested = computed(() => {
    const price = props.schedule?.suggested_price;

    return price === null || price === undefined || price === '' ? null : Number(price);
});

const suggestedText = computed(() => {
    if (suggested.value === null) return '';

    return props.schedule?.procedure_name
        ? tx('suggested_price_hint', {
            procedure: props.schedule.procedure_name,
            covenant:  props.schedule.covenant_name || props.t.no_covenant,
            amount:    money(suggested.value),
        })
        : tx('suggested_price_short', { amount: money(suggested.value) });
});

const priceDiffers = computed(() => suggested.value !== null && Number(form.unit_price) !== suggested.value);

const total = computed(() => {
    const qty   = Number(form.quantity || 0);
    const price = Number(form.unit_price ?? 0);

    return qty > 0 && price > 0 ? Math.round(qty * price * 100) / 100 : null;
});

const priceDescribedBy = computed(() => [
    'billing-ind-price-hint',
    form.errors.unit_price ? 'billing-ind-price-error' : null,
].filter(Boolean).join(' '));

watch(() => props.open, (open) => {
    confirmingDiscard.value = false;
    if (!open || !props.schedule) return;

    form.defaults({
        schedule_id:           props.schedule.id,
        quantity:              1,
        unit_price:            suggested.value,
        due_date:              '',
        tuss_code:             '',
        procedure_description: '',
        authorization_code:    '',
        eye_side:              '',
        clinical_indication:   '',
        notes:                 '',
    });
    form.reset();
    form.clearErrors();
}, { immediate: true });

function requestClose() {
    if (form.processing) return;

    if (form.isDirty && !confirmingDiscard.value) {
        confirmingDiscard.value = true;
        return;
    }

    confirmingDiscard.value = false;
    emit('close');
}

function onTussSelected(tuss) {
    if (tuss) form.procedure_description = tuss.description;
}

function useSuggested() {
    form.unit_price = suggested.value;
}

function submit() {
    if (form.processing) return;

    form.post(props.url, {
        preserveScroll: true,
        preserveState:  true,
        onSuccess:      () => emit('saved'),
    });
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <OffcanvasPanel :open="open" :width="560" @close="requestClose">
        <template #header>
            <h2 class="h5 mb-0 fw-semibold"><i class="ti ti-receipt me-2 text-primary" aria-hidden="true"></i>{{ t.individual_title }}</h2>
        </template>

        <form v-if="schedule" ref="rootRef" novalidate @submit.prevent="submit">
            <div class="border rounded p-2 mb-3 small bg-body-tertiary" data-test="individual-summary">
                <div class="text-muted">{{ t.attended_schedule }}</div>
                <div class="fw-semibold">{{ schedule.patient_name || '—' }}</div>
                <div class="text-muted">
                    {{ dateTime(schedule.date_time) }} · {{ schedule.covenant_name || t.no_covenant }}
                    <template v-if="schedule.doctor_name"> · {{ schedule.doctor_name }}</template>
                </div>
            </div>

            <div v-if="isTiss" class="alert alert-warning small py-2 d-flex gap-2" data-test="individual-tiss-hint">
                <i class="ti ti-alert-triangle mt-1" aria-hidden="true"></i>
                <span>{{ t.individual_tiss_hint }}</span>
            </div>

            <div v-for="message in otherErrors" :key="message" class="alert alert-danger small py-2" role="alert" data-test="individual-error">
                {{ message }}
            </div>

            <div class="row g-3">
                <div class="col-12 col-sm-4">
                    <label for="billing-ind-qty" class="form-label">{{ t.quantity }}</label>
                    <input
                        id="billing-ind-qty"
                        v-model.number="form.quantity"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        max="99"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.quantity }"
                        :aria-invalid="form.errors.quantity ? 'true' : 'false'"
                        :aria-describedby="form.errors.quantity ? 'billing-ind-qty-error' : undefined"
                    >
                    <div v-if="form.errors.quantity" id="billing-ind-qty-error" class="invalid-feedback">{{ form.errors.quantity }}</div>
                </div>
                <div class="col-12 col-sm-8">
                    <label for="billing-ind-price" class="form-label">
                        {{ t.unit_price }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <MoneyInput
                        id="billing-ind-price"
                        v-model="form.unit_price"
                        size=""
                        required
                        aria-required="true"
                        :invalid="!!form.errors.unit_price"
                        :aria-describedby="priceDescribedBy"
                        data-test="individual-price"
                    />
                    <div v-if="form.errors.unit_price" id="billing-ind-price-error" class="invalid-feedback d-block">{{ form.errors.unit_price }}</div>
                    <small id="billing-ind-price-hint" class="form-text d-block" data-test="individual-suggested">
                        <template v-if="suggested !== null">
                            <i class="ti ti-table me-1" aria-hidden="true"></i>{{ suggestedText }}
                            <button
                                v-if="priceDiffers"
                                type="button"
                                class="btn btn-link btn-sm p-0 align-baseline"
                                data-test="use-suggested"
                                @click="useSuggested"
                            >{{ tx('suggested_price_use', { amount: money(suggested) }) }}</button>
                        </template>
                        <template v-else>
                            {{ t.suggested_price_none }}
                            <a v-if="procedurePricesUrl" :href="procedurePricesUrl" target="_blank" rel="noopener noreferrer">
                                {{ t.price_table_link }}<span class="visually-hidden"> {{ t.new_tab }}</span>
                                <i class="ti ti-external-link ms-1" aria-hidden="true"></i>
                            </a>
                        </template>
                    </small>
                </div>
                <div class="col-12">
                    <p class="small mb-0" aria-live="polite" data-test="individual-total">
                        {{ t.claim_total }}: <strong>{{ money(total) }}</strong>
                        <span v-if="total !== null" class="text-muted">
                            ({{ tx('claim_total_formula', { quantity: number(form.quantity), unit: money(form.unit_price) }) }})
                        </span>
                    </p>
                </div>
                <div class="col-12 col-sm-6">
                    <label id="billing-ind-tuss-label" class="form-label">{{ t.tuss_code }}</label>
                    <SearchSelect
                        v-model="form.tuss_code"
                        :options="tussCodes"
                        value-key="code"
                        label-key="label"
                        :placeholder="t.tuss_code_placeholder"
                        :invalid="!!form.errors.tuss_code"
                        aria-labelledby="billing-ind-tuss-label"
                        @option-selected="onTussSelected"
                    />
                    <div v-if="form.errors.tuss_code" class="invalid-feedback d-block">{{ form.errors.tuss_code }}</div>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="billing-ind-due" class="form-label">{{ t.due_date }}</label>
                    <input id="billing-ind-due" v-model="form.due_date" type="date" class="form-control"
                           :class="{ 'is-invalid': form.errors.due_date }">
                    <div v-if="form.errors.due_date" class="invalid-feedback">{{ form.errors.due_date }}</div>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="billing-ind-eye" class="form-label">{{ t.eye_side_label }}</label>
                    <select id="billing-ind-eye" v-model="form.eye_side" class="form-select"
                            :class="{ 'is-invalid': form.errors.eye_side }">
                        <option value="">{{ t.eye_side_none }}</option>
                        <option value="OD">{{ t.eye_side_od }}</option>
                        <option value="OE">{{ t.eye_side_oe }}</option>
                        <option value="AO">{{ t.eye_side_ao }}</option>
                    </select>
                    <div v-if="form.errors.eye_side" class="invalid-feedback">{{ form.errors.eye_side }}</div>
                </div>
                <div class="col-12 col-sm-6">
                    <CidField
                        id="billing-ind-cid"
                        v-model="form.clinical_indication"
                        :search-url="cid10SearchUrl"
                        :label="t.clinical_indication"
                        :placeholder="t.cid_placeholder"
                        :hint="t.clinical_indication_hint"
                        :error="form.errors.clinical_indication || ''"
                    />
                </div>
                <div class="col-12">
                    <label for="billing-ind-desc" class="form-label">{{ t.procedure_desc }}</label>
                    <input id="billing-ind-desc" v-model="form.procedure_description" type="text" maxlength="255" class="form-control"
                           :class="{ 'is-invalid': form.errors.procedure_description }">
                    <div v-if="form.errors.procedure_description" class="invalid-feedback">{{ form.errors.procedure_description }}</div>
                </div>
                <div class="col-12">
                    <label for="billing-ind-auth" class="form-label">{{ t.authorization }}</label>
                    <input id="billing-ind-auth" v-model="form.authorization_code" type="text" maxlength="64" class="form-control"
                           :class="{ 'is-invalid': form.errors.authorization_code }">
                    <div v-if="form.errors.authorization_code" class="invalid-feedback">{{ form.errors.authorization_code }}</div>
                </div>
                <div class="col-12">
                    <label for="billing-ind-notes" class="form-label">{{ t.notes_label }}</label>
                    <textarea id="billing-ind-notes" v-model="form.notes" rows="2" maxlength="2000" class="form-control"
                              :class="{ 'is-invalid': form.errors.notes }"></textarea>
                    <div v-if="form.errors.notes" class="invalid-feedback">{{ form.errors.notes }}</div>
                </div>
            </div>
        </form>

        <template #footer>
            <template v-if="confirmingDiscard">
                <span class="me-auto small fw-medium" role="alert" data-test="discard-prompt">{{ t.discard_title }}</span>
                <button type="button" class="btn btn-light" data-test="keep-editing" @click="confirmingDiscard = false">{{ t.btn_keep_editing }}</button>
                <button type="button" class="btn btn-outline-danger" data-test="discard" @click="requestClose">{{ t.btn_discard }}</button>
            </template>
            <template v-else>
                <button type="button" class="btn btn-light" :disabled="form.processing" @click="requestClose">{{ t.btn_cancel }}</button>
                <button type="button" class="btn btn-primary" data-test="create-individual" :disabled="form.processing" @click="submit">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ form.processing ? t.processing : t.btn_create_individual }}
                </button>
            </template>
        </template>
    </OffcanvasPanel>
</template>
