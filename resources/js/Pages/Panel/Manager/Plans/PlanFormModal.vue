<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:          { type: Boolean, required: true },
    planId:        { type: String,  default: null },
    features:      { type: Array,   default: () => [] },
    billingCycles: { type: Array,   default: () => [] },
    t:             { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close']);
const isEdit  = computed(() => !!props.planId);
const title   = computed(() => isEdit.value ? props.t.form_title_edit : props.t.form_title_create);
const loading = ref(false);
const activeTab = ref('dados');

function buildFeaturesDefault() {
    return Object.fromEntries(props.features.map(f => [f.key, '0']));
}

const form = useForm({
    name: '', description: '', price: '0.00', billing_cycle: 'monthly',
    active: true, sort_order: 0, features: {},
});

function resetForm() {
    form.reset(); form.clearErrors();
    form.features      = buildFeaturesDefault();
    form.price         = '0.00';
    form.billing_cycle = props.billingCycles[0]?.value ?? 'monthly';
    activeTab.value    = 'dados';
}

async function loadEditData(id) {
    loading.value = true;
    try {
        const res  = await fetch(route('panel.manager.plans.show', id));
        const json = await res.json();
        const d    = json.data;
        form.name          = d.name         ?? '';
        form.description   = d.description  ?? '';
        form.price         = String(d.price  ?? '0.00');
        form.billing_cycle = d.billing_cycle ?? props.billingCycles[0]?.value ?? 'monthly';
        form.active        = d.active        ?? true;
        form.sort_order    = d.sort_order    ?? 0;
        form.features      = { ...buildFeaturesDefault(), ...d.features };
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, async (val) => {
    if (val) { resetForm(); if (props.planId) await loadEditData(props.planId); }
});

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    isEdit.value
        ? form.put(route('panel.manager.plans.update', props.planId), opts)
        : form.post(route('panel.manager.plans.store'), opts);
}

const booleanFeatures = computed(() => props.features.filter(f => f.is_boolean));
const numericFeatures  = computed(() => props.features.filter(f => f.is_numeric));

const tabErrors = computed(() => ({
    dados:    ['name','description','price','billing_cycle','sort_order'].some(k => k in form.errors),
    features: Object.keys(form.errors).some(k => k.startsWith('features')),
}));
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="540"
        :loading="loading"
        :loading-label="t.loading"
        @close="$emit('close')"
    >
        <!-- Header -->
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-box me-2 text-info"></i>{{ title }}
            </h5>
        </template>

        <!-- Tabs -->
        <template #tabs>
            <ul class="nav nav-tabs border-0">
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: activeTab==='dados', 'text-danger': tabErrors.dados }" @click="activeTab='dados'">
                        <i class="ti ti-info-circle me-1"></i> {{ t.tab_data }}
                        <i v-if="tabErrors.dados" class="ti ti-alert-circle text-danger ms-1 fs-12"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: activeTab==='features', 'text-danger': tabErrors.features }" @click="activeTab='features'">
                        <i class="ti ti-adjustments me-1"></i> {{ t.tab_features }}
                        <i v-if="tabErrors.features" class="ti ti-alert-circle text-danger ms-1 fs-12"></i>
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
                        <label class="form-label">{{ t.field_name_required }}</label>
                        <input v-model="form.name" type="text" maxlength="100" class="form-control" autocomplete="off" :class="{ 'is-invalid': form.errors.name }">
                        <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-3">
                        <label class="form-label">{{ t.field_sort_order }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.field_description }}</label>
                        <textarea v-model="form.description" class="form-control" rows="2" maxlength="500" :placeholder="t.field_description_placeholder"></textarea>
                    </div>
                    <div class="col-5">
                        <label class="form-label">{{ t.field_price }}</label>
                        <div class="input-group">
                            <span class="input-group-text">{{ t.currency_prefix }}</span>
                            <input v-model="form.price" type="number" step="0.01" min="0" class="form-control" :class="{ 'is-invalid': form.errors.price }">
                        </div>
                        <div v-if="form.errors.price" class="invalid-feedback d-block">{{ form.errors.price }}</div>
                    </div>
                    <div class="col-7">
                        <label class="form-label">{{ t.field_billing_cycle_required }}</label>
                        <select v-model="form.billing_cycle" class="form-select" :class="{ 'is-invalid': form.errors.billing_cycle }">
                            <option v-for="c in billingCycles" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                        <div v-if="form.errors.billing_cycle" class="invalid-feedback">{{ form.errors.billing_cycle }}</div>
                    </div>
                    <div v-if="isEdit" class="col-6">
                        <label class="form-label">{{ t.field_status }}</label>
                        <select v-model="form.active" class="form-select">
                            <option :value="true">{{ t.status_option_active }}</option>
                            <option :value="false">{{ t.status_option_inactive }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- TAB: Features -->
            <div v-show="activeTab === 'features'">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="ti ti-info-circle me-1"></i> {{ t.features_info }}
                </div>
                <div v-if="numericFeatures.length" class="mb-4">
                    <h6 class="text-muted fw-semibold mb-2" style="font-size:.75rem;letter-spacing:.05em;text-transform:uppercase;">
                        {{ t.features_numeric_section }}
                    </h6>
                    <div class="row g-3">
                        <div v-for="f in numericFeatures" :key="f.key" class="col-6">
                            <label class="form-label small">{{ f.label }}</label>
                            <input v-model.number="form.features[f.key]" type="number" min="0" class="form-control form-control-sm" :placeholder="t.features_numeric_placeholder">
                            <div class="form-text" style="font-size:.7rem;">{{ t.features_numeric_hint }}</div>
                        </div>
                    </div>
                </div>
                <div v-if="booleanFeatures.length">
                    <h6 class="text-muted fw-semibold mb-2" style="font-size:.75rem;letter-spacing:.05em;text-transform:uppercase;">
                        {{ t.features_boolean_section }}
                    </h6>
                    <div class="row g-3">
                        <div v-for="f in booleanFeatures" :key="f.key" class="col-6">
                            <label class="form-label small">{{ f.label }}</label>
                            <select v-model="form.features[f.key]" class="form-select form-select-sm">
                                <option value="0">{{ t.features_boolean_not_included }}</option>
                                <option value="1">{{ t.features_boolean_included }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Footer -->
        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.btn_cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? t.btn_save_changes : t.btn_create_plan }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
