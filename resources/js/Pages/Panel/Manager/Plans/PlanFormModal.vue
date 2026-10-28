<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { savingsPercent } from '@/utils/billingPeriods.js';

const props = defineProps({
    open: { type: Boolean, required: true },
    planId: { type: String, default: null },
    features: { type: Array, default: () => [] },
    billingCycles: { type: Array, default: () => [] }, // [{ value, label, months }] — ciclos vendáveis
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);
const { money } = useLocaleFormat();
const { tx } = useTrans(() => props.t);

const isEdit = computed(() => !!props.planId);
const title = computed(() => (isEdit.value ? props.t.form_title_edit : props.t.form_title_create));
const loading = ref(false);
const activeTab = ref('dados');

function buildFeaturesDefault() {
    return Object.fromEntries(props.features.map((f) => [f.key, f.is_boolean ? '0' : 0]));
}

const form = useForm({
    name: '',
    description: '',
    billing_cycle: 'monthly',
    active: true,
    is_featured: false,
    sort_order: 0,
    features: {},
});

// Uma linha por ciclo vendável: oferecer? + preço. O ciclo padrão é um deles.
const cycleRows = ref([]);
const discount = ref(10);

function buildCycleRows(prices = []) {
    const byCycle = Object.fromEntries(prices.map((p) => [p.cycle, Number(p.price)]));

    cycleRows.value = props.billingCycles.map((c) => ({
        value: c.value,
        label: c.label,
        months: c.months,
        offered: Object.prototype.hasOwnProperty.call(byCycle, c.value),
        price: byCycle[c.value] ?? null,
    }));
}

function resetForm() {
    form.reset();
    form.clearErrors();
    form.features = buildFeaturesDefault();
    form.billing_cycle = props.billingCycles[0]?.value ?? 'monthly';
    buildCycleRows([{ cycle: form.billing_cycle, price: null }]);
    activeTab.value = 'dados';
}

async function loadEditData(id) {
    loading.value = true;
    try {
        const res = await fetch(route('manager.plans.show', id), { headers: { Accept: 'application/json' } });
        const d = (await res.json()).data;

        form.name = d.name ?? '';
        form.description = d.description ?? '';
        form.billing_cycle = d.billing_cycle ?? props.billingCycles[0]?.value ?? 'monthly';
        form.active = d.active ?? true;
        form.is_featured = d.is_featured ?? false;
        form.sort_order = d.sort_order ?? 0;
        form.features = { ...buildFeaturesDefault(), ...d.features };
        buildCycleRows(d.prices ?? []);
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    async (val) => {
        if (val) {
            resetForm();
            if (props.planId) await loadEditData(props.planId);
        }
    },
);

// ── Preços e ciclos ──────────────────────────────────────────────────────────
const offeredRows = computed(() => cycleRows.value.filter((r) => r.offered));
const monthlyRow = computed(() => cycleRows.value.find((r) => r.value === 'monthly' && r.offered && r.price > 0));

// Desmarcar o ciclo padrão passa o padrão para o primeiro ciclo ainda oferecido.
watch(
    offeredRows,
    (rows) => {
        if (rows.length && !rows.some((r) => r.value === form.billing_cycle)) {
            form.billing_cycle = rows[0].value;
        }
    },
    { deep: true },
);

function rowSavings(row) {
    if (!monthlyRow.value || row.months <= 1 || row.price === null) return null;

    return savingsPercent(monthlyRow.value.price, row.price, row.months);
}

function rowHint(row) {
    if (!row.offered || row.price === null || row.price === '') return '';

    const parts = [];
    if (row.months > 1) parts.push(tx('pricing_monthly_equivalent', { price: money(row.price / row.months) }));

    const savings = rowSavings(row);
    if (savings === null) return parts.join(' · ');

    if (savings > 0) parts.push(tx('pricing_savings', { percent: savings }));
    else if (row.price > monthlyRow.value.price * row.months) parts.push(props.t.pricing_more_expensive);
    else parts.push(props.t.pricing_no_savings);

    return parts.join(' · ');
}

function applyDiscount() {
    if (!monthlyRow.value) return;

    const factor = 1 - Math.min(Math.max(Number(discount.value) || 0, 0), 90) / 100;

    for (const row of cycleRows.value) {
        if (row.offered && row.months > 1) {
            row.price = Math.round(monthlyRow.value.price * row.months * factor * 100) / 100;
        }
    }
}

// Índice de cada ciclo no payload enviado (os erros do servidor vêm como prices.N.*).
const submittedOrder = ref([]);

function priceError(row) {
    const i = submittedOrder.value.indexOf(row.value);

    return i < 0 ? '' : (form.errors[`prices.${i}.price`] ?? form.errors[`prices.${i}.billing_cycle`] ?? '');
}

function submit() {
    submittedOrder.value = offeredRows.value.map((r) => r.value);

    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    const request = form.transform((data) => ({
        ...data,
        prices: offeredRows.value.map((r) => ({ billing_cycle: r.value, price: r.price })),
    }));

    isEdit.value
        ? request.put(route('manager.plans.update', props.planId), opts)
        : request.post(route('manager.plans.store'), opts);
}

const booleanFeatures = computed(() => props.features.filter((f) => f.is_boolean));
const numericFeatures = computed(() => props.features.filter((f) => f.is_numeric));

const statusOptions = computed(() => [
    { value: true, label: props.t.status_option_active },
    { value: false, label: props.t.status_option_inactive },
]);

const booleanFeatureOptions = computed(() => [
    { value: '0', label: props.t.features_boolean_not_included },
    { value: '1', label: props.t.features_boolean_included },
]);

const errorKeys = computed(() => Object.keys(form.errors));
const tabErrors = computed(() => ({
    dados: ['name', 'description', 'sort_order', 'is_featured'].some((k) => k in form.errors),
    pricing: errorKeys.value.some((k) => k === 'billing_cycle' || k.startsWith('prices')),
    features: errorKeys.value.some((k) => k.startsWith('features')),
}));

// Abre a aba do primeiro erro devolvido pelo servidor.
watch(
    () => form.errors,
    () => {
        if (tabErrors.value[activeTab.value]) return;
        const firstWithError = ['dados', 'pricing', 'features'].find((tab) => tabErrors.value[tab]);
        if (firstWithError) activeTab.value = firstWithError;
    },
);
</script>

<template>
    <OffcanvasPanel :open="open" :width="600" :loading="loading" :loading-label="t.loading" @close="$emit('close')">
        <!-- Header -->
        <template #header>
            <h5 class="mb-0 fw-semibold"><i class="ti ti-box me-2 text-info" aria-hidden="true"></i>{{ title }}</h5>
        </template>

        <!-- Tabs -->
        <template #tabs>
            <ul class="nav nav-tabs border-0" role="tablist">
                <li v-for="tab in ['dados', 'pricing', 'features']" :key="tab" class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link"
                        role="tab"
                        :aria-selected="activeTab === tab"
                        :class="{ active: activeTab === tab, 'text-danger': tabErrors[tab] }"
                        @click="activeTab = tab"
                    >
                        <i
                            :class="[
                                'ti me-1',
                                { dados: 'ti-info-circle', pricing: 'ti-receipt', features: 'ti-adjustments' }[tab],
                            ]"
                            aria-hidden="true"
                        ></i>
                        {{ { dados: t.tab_data, pricing: t.tab_pricing, features: t.tab_features }[tab] }}
                        <i
                            v-if="tabErrors[tab]"
                            class="ti ti-alert-circle text-danger ms-1 fs-12"
                            aria-hidden="true"
                        ></i>
                    </button>
                </li>
            </ul>
        </template>

        <!-- Body -->
        <form @submit.prevent="submit">
            <!-- TAB: Dados -->
            <div v-show="activeTab === 'dados'">
                <div class="row g-3">
                    <div class="col-9">
                        <label for="plan-name" class="form-label">{{ t.field_name_required }}</label>
                        <input
                            id="plan-name"
                            v-model="form.name"
                            type="text"
                            maxlength="100"
                            class="form-control"
                            autocomplete="off"
                            :class="{ 'is-invalid': form.errors.name }"
                        />
                        <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-3">
                        <label for="plan-order" class="form-label">{{ t.field_sort_order }}</label>
                        <input
                            id="plan-order"
                            v-model.number="form.sort_order"
                            type="number"
                            min="0"
                            class="form-control"
                        />
                    </div>
                    <div class="col-12">
                        <label for="plan-description" class="form-label">{{ t.field_description }}</label>
                        <textarea
                            id="plan-description"
                            v-model="form.description"
                            class="form-control"
                            rows="2"
                            maxlength="500"
                            :placeholder="t.field_description_placeholder"
                        ></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input
                                id="plan-featured"
                                v-model="form.is_featured"
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                aria-describedby="plan-featured-hint"
                            />
                            <label class="form-check-label" for="plan-featured">{{ t.field_is_featured }}</label>
                        </div>
                        <div id="plan-featured-hint" class="form-text">{{ t.field_is_featured_hint }}</div>
                    </div>
                    <div v-if="isEdit" class="col-6">
                        <label class="form-label">{{ t.field_status }}</label>
                        <SearchSelect
                            v-model="form.active"
                            :options="statusOptions"
                            :value-key="'value'"
                            :label-key="'label'"
                            :placeholder="t.field_status"
                            :clearable="false"
                        />
                    </div>
                </div>
            </div>

            <!-- TAB: Preços e ciclos -->
            <div v-show="activeTab === 'pricing'">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i> {{ t.pricing_info }}
                </div>

                <div v-if="form.errors.prices" class="alert alert-danger small py-2" role="alert">
                    {{ form.errors.prices }}
                </div>
                <div v-if="form.errors.billing_cycle" class="alert alert-danger small py-2" role="alert">
                    {{ form.errors.billing_cycle }}
                </div>

                <fieldset class="plan-cycles">
                    <legend class="visually-hidden">{{ t.tab_pricing }}</legend>
                    <div
                        v-for="row in cycleRows"
                        :key="row.value"
                        class="plan-cycle"
                        :class="{ 'plan-cycle--off': !row.offered }"
                        :data-cycle="row.value"
                    >
                        <div class="form-check plan-cycle__offer">
                            <input
                                :id="`cycle-offer-${row.value}`"
                                v-model="row.offered"
                                class="form-check-input"
                                type="checkbox"
                                :aria-label="tx('pricing_offer', { cycle: row.label })"
                            />
                            <label class="form-check-label fw-medium" :for="`cycle-offer-${row.value}`">{{
                                row.label
                            }}</label>
                        </div>

                        <div class="plan-cycle__price">
                            <MoneyInput
                                v-model="row.price"
                                size=""
                                :disabled="!row.offered"
                                :invalid="!!priceError(row)"
                                :aria-label="tx('pricing_price_label', { cycle: row.label })"
                            />
                            <div v-if="priceError(row)" class="invalid-feedback d-block">{{ priceError(row) }}</div>
                            <div v-else-if="rowHint(row)" class="form-text">{{ rowHint(row) }}</div>
                        </div>

                        <div class="form-check plan-cycle__default">
                            <input
                                :id="`cycle-default-${row.value}`"
                                v-model="form.billing_cycle"
                                class="form-check-input"
                                type="radio"
                                name="plan-default-cycle"
                                :value="row.value"
                                :disabled="!row.offered"
                                :aria-label="tx('pricing_default_label', { cycle: row.label })"
                            />
                            <label class="form-check-label small" :for="`cycle-default-${row.value}`">{{
                                t.pricing_default
                            }}</label>
                        </div>
                    </div>
                </fieldset>

                <div class="plan-discount mt-3">
                    <label for="plan-discount" class="form-label small mb-1">{{ t.pricing_discount_label }}</label>
                    <div class="d-flex gap-2 align-items-start flex-wrap">
                        <input
                            id="plan-discount"
                            v-model.number="discount"
                            type="number"
                            min="0"
                            max="90"
                            step="1"
                            class="form-control form-control-sm"
                            style="max-width: 90px"
                            aria-describedby="plan-discount-hint"
                        />
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary"
                            :disabled="!monthlyRow"
                            :title="monthlyRow ? '' : t.pricing_need_monthly"
                            @click="applyDiscount"
                        >
                            <i class="ti ti-calculator me-1" aria-hidden="true"></i>{{ t.pricing_apply_discount }}
                        </button>
                    </div>
                    <div id="plan-discount-hint" class="form-text">{{ t.pricing_apply_hint }}</div>
                </div>
            </div>

            <!-- TAB: Features -->
            <div v-show="activeTab === 'features'">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i> {{ t.features_info }}
                </div>
                <div v-if="numericFeatures.length" class="mb-4">
                    <h6 class="plan-section-title">{{ t.features_numeric_section }}</h6>
                    <div class="row g-3">
                        <div v-for="f in numericFeatures" :key="f.key" class="col-6">
                            <label :for="`feature-${f.key}`" class="form-label small">{{ f.label }}</label>
                            <input
                                :id="`feature-${f.key}`"
                                v-model.number="form.features[f.key]"
                                type="number"
                                min="0"
                                step="1"
                                class="form-control form-control-sm"
                                :class="{ 'is-invalid': form.errors[`features.${f.key}`] }"
                                :placeholder="
                                    f.zero_means_none ? t.features_numeric_hint_none : t.features_numeric_placeholder
                                "
                            />
                            <div v-if="form.errors[`features.${f.key}`]" class="invalid-feedback">
                                {{ form.errors[`features.${f.key}`] }}
                            </div>
                            <div v-else class="form-text" style="font-size: 0.7rem">
                                {{ f.zero_means_none ? t.features_numeric_hint_none : t.features_numeric_hint }}
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="booleanFeatures.length">
                    <h6 class="plan-section-title">{{ t.features_boolean_section }}</h6>
                    <div class="row g-3">
                        <div v-for="f in booleanFeatures" :key="f.key" class="col-6">
                            <label class="form-label small">{{ f.label }}</label>
                            <SearchSelect
                                v-model="form.features[f.key]"
                                :options="booleanFeatureOptions"
                                :value-key="'value'"
                                :label-key="'label'"
                                :placeholder="t.features_boolean_not_included"
                                :clearable="false"
                                :invalid="!!form.errors[`features.${f.key}`]"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Footer -->
        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.btn_cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ isEdit ? t.btn_save_changes : t.btn_create_plan }}
            </button>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.plan-section-title {
    color: var(--bs-secondary-color);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 0.5rem;
}
.plan-cycles {
    display: grid;
    gap: 0.5rem;
    margin: 0;
    padding: 0;
    border: 0;
}
.plan-cycle {
    display: grid;
    grid-template-columns: minmax(120px, 1fr) minmax(160px, 1.6fr) auto;
    align-items: start;
    gap: 0.75rem;
    padding: 0.75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
}
.plan-cycle--off {
    background: var(--bs-tertiary-bg);
}
.plan-cycle__offer,
.plan-cycle__default {
    padding-top: 0.45rem;
}
@media (max-width: 575.98px) {
    .plan-cycle {
        grid-template-columns: 1fr auto;
    }
    .plan-cycle__price {
        grid-column: 1 / -1;
        grid-row: 2;
    }
}
</style>
