<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Cadastro/edição de um convênio do catálogo global.
 * - Manual: todos os campos (dados oficiais opcionais).
 * - Operadora da ANS: dados oficiais só leitura (vêm da sincronização) —
 *   nome exibido, cor, tabela de cobrança e situação editáveis.
 * - PARTICULAR: só cor e tabela de cobrança (o sistema depende do nome).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    covenant: { type: Object, default: null }, // null = novo
    modalities: { type: Array, default: () => [] },
    ufs: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const DEFAULT_COLOR = '#2563EB';

const isEdit = computed(() => !!props.covenant);
const isAns = computed(() => props.covenant?.source === 'ans');
const isParticular = computed(() => !!props.covenant?.is_particular);

const form = useForm({
    name: '',
    color: DEFAULT_COLOR,
    table: true,
    active: true,
    company_name: '',
    trade_name: '',
    national_registry: '',
    ans_registry: '',
    ans_modality: '',
    city: '',
    uf: '',
});

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        form.clearErrors();
        const c = props.covenant ?? {};
        form.name = c.name ?? '';
        form.color = c.color || DEFAULT_COLOR;
        form.table = c.table ?? true;
        form.active = c.active ?? true;
        form.company_name = c.company_name ?? '';
        form.trade_name = c.trade_name ?? '';
        form.national_registry = c.cnpj_formatted ?? c.national_registry ?? '';
        form.ans_registry = c.ans_registry ?? '';
        form.ans_modality = c.ans_modality ?? '';
        form.city = c.city ?? '';
        form.uf = c.uf ?? '';
    },
);

// Cada tipo só envia o que o servidor aceita alterar.
function payload(data) {
    if (isParticular.value) return { color: data.color, table: data.table };
    if (isAns.value) return { name: data.name, color: data.color, table: data.table, active: data.active };
    return data;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    // transform é persistente no useForm: definido nos DOIS caminhos.
    if (!isEdit.value) {
        form.transform((data) => data).post(route('manager.covenants.store'), options);
        return;
    }

    form.transform(payload).put(route('manager.covenants.update', props.covenant.id), options);
}

const otherErrors = computed(() => {
    const shown = [
        'name',
        'color',
        'company_name',
        'trade_name',
        'national_registry',
        'ans_registry',
        'ans_modality',
        'city',
        'uf',
    ];
    return Object.entries(form.errors)
        .filter(([key]) => !shown.includes(key))
        .map(([, message]) => message);
});
</script>

<template>
    <CenteredModal :open="open" size="lg" :close-label="t.close" @close="emit('close')">
        <template #header>
            <h5 class="mb-0">
                <i class="ti ti-building-hospital me-1 text-primary"></i>
                {{ isEdit ? t.edit_title : t.new_title }}
            </h5>
        </template>

        <form id="covenant-form" novalidate @submit.prevent="submit">
            <div v-if="isParticular" class="alert alert-info py-2 small">
                <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ t.particular_hint }}
            </div>
            <div v-else-if="isAns" class="alert alert-info py-2 small">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.ans_readonly_hint }}
                <div class="mt-1 text-body">
                    <strong>{{ covenant.company_name }}</strong>
                    <div class="text-muted">
                        {{
                            [
                                covenant.ans_registry && `${t.field_ans_registry} ${covenant.ans_registry}`,
                                covenant.cnpj_formatted,
                                covenant.ans_modality,
                                [covenant.city, covenant.uf].filter(Boolean).join(' / '),
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </div>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-12 col-md-9">
                    <label class="form-label fw-semibold" for="cov-name">
                        {{ t.field_name }} <span v-if="!isParticular" class="text-danger">*</span>
                    </label>
                    <input
                        id="cov-name"
                        v-model="form.name"
                        type="text"
                        class="form-control text-uppercase"
                        :class="{ 'is-invalid': form.errors.name }"
                        :disabled="isParticular"
                        maxlength="255"
                        aria-describedby="cov-name-hint"
                    />
                    <div id="cov-name-hint" class="form-text">{{ t.field_name_hint }}</div>
                    <div class="invalid-feedback">{{ form.errors.name }}</div>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold" for="cov-color">{{ t.field_color }}</label>
                    <input
                        id="cov-color"
                        v-model="form.color"
                        type="color"
                        class="form-control form-control-color w-100"
                        :class="{ 'is-invalid': form.errors.color }"
                    />
                    <div class="invalid-feedback">{{ form.errors.color }}</div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-3 mb-3">
                <div class="form-check">
                    <input id="cov-table" v-model="form.table" type="checkbox" class="form-check-input" />
                    <label for="cov-table" class="form-check-label">{{ t.field_table }}</label>
                </div>
                <div v-if="!isParticular" class="form-check">
                    <input id="cov-active" v-model="form.active" type="checkbox" class="form-check-input" />
                    <label for="cov-active" class="form-check-label">{{ t.field_active }}</label>
                </div>
            </div>

            <!-- Manual: dados oficiais opcionais (ANS vem da sincronização) -->
            <template v-if="!isAns && !isParticular">
                <h6 class="fw-semibold mb-2">{{ t.section_official }}</h6>
                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="cov-company">{{ t.field_company_name }}</label>
                        <input
                            id="cov-company"
                            v-model="form.company_name"
                            type="text"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': form.errors.company_name }"
                            maxlength="255"
                        />
                        <div class="invalid-feedback">{{ form.errors.company_name }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="cov-trade">{{ t.field_trade_name }}</label>
                        <input
                            id="cov-trade"
                            v-model="form.trade_name"
                            type="text"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': form.errors.trade_name }"
                            maxlength="255"
                        />
                        <div class="invalid-feedback">{{ form.errors.trade_name }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="cov-cnpj">{{ t.field_cnpj }}</label>
                        <input
                            id="cov-cnpj"
                            v-model="form.national_registry"
                            type="text"
                            inputmode="numeric"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': form.errors.national_registry }"
                            :placeholder="t.field_cnpj_ph"
                            maxlength="18"
                        />
                        <div class="invalid-feedback">{{ form.errors.national_registry }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="cov-ans">{{ t.field_ans_registry }}</label>
                        <input
                            id="cov-ans"
                            v-model="form.ans_registry"
                            type="text"
                            inputmode="numeric"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': form.errors.ans_registry }"
                            :placeholder="t.field_ans_ph"
                            maxlength="6"
                        />
                        <div class="invalid-feedback">{{ form.errors.ans_registry }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small" for="cov-modality">{{ t.field_modality }}</label>
                        <select
                            id="cov-modality"
                            v-model="form.ans_modality"
                            class="form-select form-select-sm"
                            :class="{ 'is-invalid': form.errors.ans_modality }"
                        >
                            <option value="">—</option>
                            <option v-for="m in modalities" :key="m" :value="m">{{ m }}</option>
                        </select>
                        <div class="invalid-feedback">{{ form.errors.ans_modality }}</div>
                    </div>
                    <div class="col-8 col-md-4">
                        <label class="form-label small" for="cov-city">{{ t.field_city }}</label>
                        <input
                            id="cov-city"
                            v-model="form.city"
                            type="text"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': form.errors.city }"
                            maxlength="120"
                        />
                        <div class="invalid-feedback">{{ form.errors.city }}</div>
                    </div>
                    <div class="col-4 col-md-2">
                        <label class="form-label small" for="cov-uf">{{ t.field_uf }}</label>
                        <select
                            id="cov-uf"
                            v-model="form.uf"
                            class="form-select form-select-sm"
                            :class="{ 'is-invalid': form.errors.uf }"
                        >
                            <option value="">—</option>
                            <option v-for="u in ufs" :key="u" :value="u">{{ u }}</option>
                        </select>
                        <div class="invalid-feedback">{{ form.errors.uf }}</div>
                    </div>
                </div>
            </template>

            <div v-if="otherErrors.length" class="text-danger small mt-2" role="alert">{{ otherErrors[0] }}</div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t.cancel }}</button>
            <button type="submit" form="covenant-form" class="btn btn-primary" :disabled="form.processing">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ t.save }}
            </button>
        </template>
    </CenteredModal>
</template>
