<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import CidField from './CidField.vue';
import { generalErrors, tablePriceInfo } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * Novo lote por convênio. Antes de criar, resume o que vai entrar: quantos
 * marcados são do convênio escolhido, quantos de outro convênio ficarão de
 * fora e — sem seleção — que o lote pega todos os elegíveis do período.
 * O valor unitário é sugerido pela tabela de preços quando os atendimentos do
 * lote têm o mesmo preço (preços diferentes: avisa, sem pré-preencher) e o
 * total estimado é ao vivo (guias × quantidade × valor). Versão/layout TISS
 * vêm do contrato da operadora (o servidor mantém os padrões para legados).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    covenants: { type: Array, default: () => [] },
    eligibleSchedules: { type: Array, default: () => [] },
    selectedIds: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    tussCodes: { type: Array, default: () => [] },
    url: { type: String, required: true },
    cid10SearchUrl: { type: String, default: '' },
    procedurePricesUrl: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const FIELDS = [
    'covenant_id',
    'date_from',
    'date_until',
    'quantity',
    'unit_price',
    'tuss_code',
    'procedure_description',
    'clinical_indication',
];

const { tx } = useTrans(() => props.t);
const { money, number } = useLocaleFormat();

const form = useForm({
    covenant_id: '',
    date_from: '',
    date_until: '',
    quantity: 1,
    unit_price: null,
    tuss_code: '',
    procedure_description: '',
    clinical_indication: '',
});

const rootRef = ref(null);
const confirmingDiscard = ref(false);
/** Último valor preenchido pela sugestão: trocar de convênio só o substitui se o usuário não mexeu. */
const autoFilledPrice = ref(null);

const otherErrors = computed(() => generalErrors(form.errors, FIELDS));
const covenant = computed(() => props.covenants.find((c) => c.id === form.covenant_id) ?? null);

function onlyDate(value) {
    return String(value ?? '').slice(0, 10);
}

/**
 * Atendimentos que entram no lote: os marcados do convênio ou, sem seleção,
 * os da lista atual (limitada) do convênio no período do lote.
 */
function candidatesFor(covenantId, dateFrom, dateUntil) {
    if (!covenantId) return [];

    if (props.selectedIds.length > 0) {
        return props.eligibleSchedules.filter((s) => props.selectedIds.includes(s.id) && s.covenant_id === covenantId);
    }

    return props.eligibleSchedules.filter((s) => {
        const day = onlyDate(s.date_time);

        return s.covenant_id === covenantId && (!dateFrom || day >= dateFrom) && (!dateUntil || day <= dateUntil);
    });
}

const candidates = computed(() => candidatesFor(form.covenant_id, form.date_from, form.date_until));
const selectedInCovenant = computed(() => (props.selectedIds.length > 0 ? candidates.value.length : 0));
const selectedExcluded = computed(() => props.selectedIds.length - selectedInCovenant.value);
const listedInPeriod = computed(() => (props.selectedIds.length > 0 ? 0 : candidates.value.length));
const estimatedCount = computed(() => candidates.value.length);
const priceInfo = computed(() => tablePriceInfo(candidates.value));

const estimatedTotal = computed(() => {
    const price = Number(form.unit_price ?? 0);
    const qty = Number(form.quantity || 0);

    return price > 0 && qty > 0 && estimatedCount.value > 0
        ? Math.round(price * qty * estimatedCount.value * 100) / 100
        : null;
});

const priceDescribedBy = computed(
    () =>
        [
            form.covenant_id ? 'billing-batch-price-hint' : null,
            form.errors.unit_price ? 'billing-batch-price-error' : null,
        ]
            .filter(Boolean)
            .join(' ') || undefined,
);

watch(
    () => props.open,
    (open) => {
        confirmingDiscard.value = false;
        if (!open) return;

        const firstSelected = props.eligibleSchedules.find((s) => props.selectedIds.includes(s.id));
        const covenantId = firstSelected?.covenant_id ?? props.filters.covenant_id ?? '';
        const suggestion =
            tablePriceInfo(candidatesFor(covenantId, props.filters.from ?? '', props.filters.to ?? ''))?.single ?? null;

        autoFilledPrice.value = suggestion;

        form.defaults({
            covenant_id: covenantId,
            date_from: props.filters.from ?? '',
            date_until: props.filters.to ?? '',
            quantity: 1,
            unit_price: suggestion,
            tuss_code: '',
            procedure_description: '',
            clinical_indication: '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

// Convênio/período trocado: acompanha a sugestão enquanto o valor não foi
// digitado pelo usuário (vazio ou igual ao último preenchido automaticamente).
watch(
    () => priceInfo.value?.single ?? null,
    (single) => {
        if (!props.open) return;

        const untouched = form.unit_price === null || form.unit_price === autoFilledPrice.value;
        if (!untouched) return;

        form.unit_price = single;
        autoFilledPrice.value = single;
    },
);

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

function submit() {
    if (form.processing) return;

    // Envia todos os marcados, como antes: o servidor filtra pelo convênio. Não
    // filtrar aqui — lista vazia significaria "todos os elegíveis do período".
    form.transform((data) => ({ ...data, schedule_ids: [...props.selectedIds] })).post(props.url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved'),
    });
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <OffcanvasPanel :open="open" :width="580" @close="requestClose">
        <template #header>
            <h2 class="h5 mb-0 fw-semibold">
                <i class="ti ti-package me-2 text-primary" aria-hidden="true"></i>{{ t.batch_title }}
            </h2>
        </template>

        <form ref="rootRef" novalidate @submit.prevent="submit">
            <div class="row g-3">
                <div class="col-12">
                    <label for="billing-batch-covenant" class="form-label">
                        {{ t.batch_covenant }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <select
                        id="billing-batch-covenant"
                        v-model="form.covenant_id"
                        class="form-select"
                        required
                        aria-required="true"
                        :class="{ 'is-invalid': form.errors.covenant_id }"
                        :aria-invalid="form.errors.covenant_id ? 'true' : 'false'"
                    >
                        <option value="">{{ t.select }}</option>
                        <option v-for="c in covenants" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <div v-if="form.errors.covenant_id" class="invalid-feedback">{{ form.errors.covenant_id }}</div>
                    <small
                        v-if="covenant && !covenant.has_ans_registry"
                        class="d-block text-warning-emphasis mt-1"
                        data-test="particular-hint"
                    >
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.particular_hint }}
                    </small>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="billing-batch-from" class="form-label">
                        {{ t.batch_period_from }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="billing-batch-from"
                        v-model="form.date_from"
                        type="date"
                        class="form-control"
                        required
                        aria-required="true"
                        :class="{ 'is-invalid': form.errors.date_from }"
                    />
                    <div v-if="form.errors.date_from" class="invalid-feedback">{{ form.errors.date_from }}</div>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="billing-batch-until" class="form-label">
                        {{ t.batch_period_to }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="billing-batch-until"
                        v-model="form.date_until"
                        type="date"
                        class="form-control"
                        required
                        aria-required="true"
                        :class="{ 'is-invalid': form.errors.date_until }"
                    />
                    <div v-if="form.errors.date_until" class="invalid-feedback">{{ form.errors.date_until }}</div>
                </div>

                <div class="col-12">
                    <div class="alert alert-info small py-2 mb-0" aria-live="polite" data-test="batch-summary">
                        <template v-if="!form.covenant_id">{{ t.batch_hint_pick }}</template>
                        <template v-else-if="selectedIds.length > 0">
                            <div>{{ tx('batch_hint_selected', { count: selectedInCovenant }) }}</div>
                            <div
                                v-if="selectedExcluded > 0"
                                class="text-warning-emphasis fw-medium"
                                data-test="batch-excluded"
                            >
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                >{{ tx('batch_hint_excluded', { count: selectedExcluded }) }}
                            </div>
                        </template>
                        <template v-else>{{ tx('batch_hint_all', { count: listedInPeriod }) }}</template>
                        <div v-if="estimatedTotal !== null" class="fw-semibold mt-1" data-test="batch-estimated">
                            {{ tx('batch_estimated_total', { total: money(estimatedTotal) }) }}
                            <span class="fw-normal">
                                ({{
                                    tx('batch_estimated_formula', {
                                        count: estimatedCount,
                                        quantity: number(form.quantity),
                                        unit: money(form.unit_price),
                                    })
                                }})
                            </span>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="billing-batch-qty" class="form-label">{{ t.batch_quantity }}</label>
                    <input
                        id="billing-batch-qty"
                        v-model.number="form.quantity"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        max="99"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.quantity }"
                        :aria-invalid="form.errors.quantity ? 'true' : 'false'"
                    />
                    <div v-if="form.errors.quantity" class="invalid-feedback">{{ form.errors.quantity }}</div>
                </div>
                <div class="col-12 col-sm-8">
                    <label for="billing-batch-price" class="form-label">
                        {{ t.unit_price }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <MoneyInput
                        id="billing-batch-price"
                        v-model="form.unit_price"
                        size=""
                        required
                        aria-required="true"
                        :invalid="!!form.errors.unit_price"
                        :aria-describedby="priceDescribedBy"
                        data-test="batch-price"
                    />
                    <div v-if="form.errors.unit_price" id="billing-batch-price-error" class="invalid-feedback d-block">
                        {{ form.errors.unit_price }}
                    </div>
                    <small
                        v-if="form.covenant_id"
                        id="billing-batch-price-hint"
                        class="form-text d-block"
                        data-test="batch-suggested"
                    >
                        <template v-if="priceInfo && priceInfo.single !== null">
                            <i class="ti ti-table me-1" aria-hidden="true"></i
                            >{{ tx('suggested_price_batch', { amount: money(priceInfo.single) }) }}
                        </template>
                        <template v-else-if="priceInfo">
                            <i class="ti ti-alert-triangle me-1 text-warning" aria-hidden="true"></i
                            >{{ tx('suggested_price_mixed', { min: money(priceInfo.min), max: money(priceInfo.max) }) }}
                        </template>
                        <template v-else>
                            {{ t.suggested_price_none }}
                            <a
                                v-if="procedurePricesUrl"
                                :href="procedurePricesUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ t.price_table_link }}<span class="visually-hidden"> {{ t.new_tab }}</span>
                                <i class="ti ti-external-link ms-1" aria-hidden="true"></i>
                            </a>
                        </template>
                    </small>
                </div>
                <div class="col-12 col-sm-6">
                    <label id="billing-batch-tuss-label" class="form-label">{{ t.tuss_code }}</label>
                    <SearchSelect
                        v-model="form.tuss_code"
                        :options="tussCodes"
                        value-key="code"
                        label-key="label"
                        :placeholder="t.tuss_code_placeholder"
                        :invalid="!!form.errors.tuss_code"
                        aria-labelledby="billing-batch-tuss-label"
                        @option-selected="onTussSelected"
                    />
                    <div v-if="form.errors.tuss_code" class="invalid-feedback d-block">{{ form.errors.tuss_code }}</div>
                </div>
                <div class="col-12 col-sm-6">
                    <CidField
                        id="billing-batch-cid"
                        v-model="form.clinical_indication"
                        :search-url="cid10SearchUrl"
                        :label="t.clinical_indication"
                        :placeholder="t.cid_placeholder"
                        :hint="t.clinical_indication_hint"
                        :error="form.errors.clinical_indication || ''"
                    />
                </div>
                <div class="col-12">
                    <label for="billing-batch-desc" class="form-label">{{ t.procedure_desc }}</label>
                    <input
                        id="billing-batch-desc"
                        v-model="form.procedure_description"
                        type="text"
                        maxlength="255"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.procedure_description }"
                    />
                    <div v-if="form.errors.procedure_description" class="invalid-feedback">
                        {{ form.errors.procedure_description }}
                    </div>
                </div>
                <div v-for="message in otherErrors" :key="message" class="col-12">
                    <div class="alert alert-danger small py-2 mb-0" role="alert" data-test="batch-error">
                        {{ message }}
                    </div>
                </div>
            </div>
        </form>

        <template #footer>
            <template v-if="confirmingDiscard">
                <span class="me-auto small fw-medium" role="alert" data-test="discard-prompt">{{
                    t.discard_title
                }}</span>
                <button type="button" class="btn btn-light" @click="confirmingDiscard = false">
                    {{ t.btn_keep_editing }}
                </button>
                <button type="button" class="btn btn-outline-danger" data-test="discard" @click="requestClose">
                    {{ t.btn_discard }}
                </button>
            </template>
            <template v-else>
                <button type="button" class="btn btn-light" :disabled="form.processing" @click="requestClose">
                    {{ t.btn_cancel }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    data-test="create-batch"
                    :disabled="form.processing"
                    @click="submit"
                >
                    <span
                        v-if="form.processing"
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    {{ form.processing ? t.processing : t.btn_create_batch }}
                </button>
            </template>
        </template>
    </OffcanvasPanel>
</template>
