<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Cadastro/edição de um item do catálogo global de medicamentos.
 * Item da CMED/Anvisa: dados cadastrais só leitura (vêm da importação) —
 * só a posologia sugerida é editável.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    medicine: { type: Object, default: null }, // null = novo
    presentations: { type: Array, default: () => [] },
    // IAs configuradas ({code, label, model}); com mais de uma, o admin escolhe.
    aiProviders: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'providersStale']);

const isEdit = computed(() => !!props.medicine);
const isCmed = computed(() => props.medicine?.source === 'cmed');

const form = useForm({
    name: '',
    active_ingredient: '',
    concentration: '',
    medicine_presentation_id: '',
    dosage: '',
    frequency: '',
    duration: '',
    instructions: '',
    is_ophthalmic: false,
    active: true,
});

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        form.clearErrors();
        const m = props.medicine ?? {};
        form.name = m.name ?? '';
        form.active_ingredient = m.active_ingredient ?? '';
        form.concentration = m.concentration ?? '';
        form.medicine_presentation_id = m.medicine_presentation_id ?? '';
        form.dosage = m.dosage ?? '';
        form.frequency = m.frequency ?? '';
        form.duration = m.duration ?? '';
        form.instructions = m.instructions ?? '';
        form.is_ophthalmic = m.is_ophthalmic ?? false;
        form.active = m.active ?? true;
        resetAi();
    },
);

// ── Sugestão de posologia por IA (opcional) ─────────────────────────────
// Só preenche os campos; nada é salvo até o admin clicar em Salvar.
const POSOLOGY_FIELDS = ['dosage', 'frequency', 'duration', 'instructions'];
const ai = ref({ loading: false, error: '', note: '', previous: null, providerLabel: '' });
let aiRequest = 0;
const aiMenuOpen = ref(false);
const aiMenuRoot = ref(null);

function resetAi() {
    aiRequest++; // resposta de um pedido anterior (outro item) é descartada
    ai.value = { loading: false, error: '', note: '', previous: null, providerLabel: '' };
    aiMenuOpen.value = false;
}

const aiAvailable = computed(() => props.aiProviders.length > 0);
const chooseAi = computed(() => props.aiProviders.length > 1);
const canGenerate = computed(() => aiAvailable.value && (isCmed.value || form.name.trim() !== ''));

// Com mais de uma IA: menu "Gerar com qual IA?" (lembra a última escolhida
// neste navegador — conveniência, nunca obrigatório).
const AI_PREF_KEY = 'mgr_medicines_ai_provider';
const lastProvider = ref(readLastProvider());

function readLastProvider() {
    try {
        return localStorage.getItem(AI_PREF_KEY) ?? '';
    } catch {
        return '';
    }
}

function rememberProvider(code) {
    lastProvider.value = code;
    try {
        localStorage.setItem(AI_PREF_KEY, code);
    } catch {
        // armazenamento bloqueado: vale só nesta visita
    }
}

// Clique fora fecha o menu de IAs.
function onDocumentClick(event) {
    if (aiMenuOpen.value && aiMenuRoot.value && !aiMenuRoot.value.contains(event.target)) aiMenuOpen.value = false;
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));

function onAiButton() {
    if (ai.value.loading || !canGenerate.value) return;

    if (chooseAi.value) {
        aiMenuOpen.value = !aiMenuOpen.value;
        return;
    }

    generateWithAi(props.aiProviders[0]?.code ?? null);
}

function pickProvider(code) {
    aiMenuOpen.value = false;
    rememberProvider(code);
    generateWithAi(code);
}

function providerText(p) {
    return p.model ? `${p.label} · ${p.model}` : p.label;
}

async function generateWithAi(provider) {
    if (ai.value.loading || !canGenerate.value) return;

    const request = ++aiRequest;
    ai.value = { loading: true, error: '', note: '', previous: null, providerLabel: '' };

    // CMED: o servidor usa os dados do banco. Curado: o que está digitado.
    const payload = {
        provider,
        ...(isCmed.value
            ? { medicine_id: props.medicine.id }
            : {
                  name: form.name,
                  active_ingredient: form.active_ingredient || null,
                  concentration: form.concentration || null,
                  medicine_presentation_id: form.medicine_presentation_id || null,
                  is_ophthalmic: form.is_ophthalmic,
              }),
    };

    try {
        const { data } = await axios.post(route('manager.medicines.ai-posology'), payload);
        if (request !== aiRequest) return;

        const previous = Object.fromEntries(POSOLOGY_FIELDS.map((field) => [field, form[field]]));
        POSOLOGY_FIELDS.forEach((field) => {
            form[field] = data.suggestion?.[field] ?? '';
        });
        ai.value = {
            loading: false,
            error: '',
            note: data.suggestion?.note ?? '',
            previous,
            providerLabel: data.suggestion?.provider_label ?? '',
        };
    } catch (error) {
        if (request !== aiRequest) return;

        const message =
            error.response?.status === 429
                ? props.t.ai_rate_limited
                : (error.response?.data?.message ?? props.t.ai_failed);

        // Provedores mudaram com a página aberta: a tela busca a lista nova.
        if (error.response?.data?.reason === 'stale_providers') emit('providersStale');
        ai.value = { loading: false, error: message, note: '', previous: null, providerLabel: '' };
    }
}

function undoAi() {
    if (!ai.value.previous) return;
    POSOLOGY_FIELDS.forEach((field) => {
        form[field] = ai.value.previous[field];
    });
    ai.value = { loading: false, error: '', note: '', previous: null, providerLabel: '' };
}

const filledText = computed(() =>
    ai.value.providerLabel
        ? (props.t.ai_filled_by ?? '').replace(':provider', ai.value.providerLabel)
        : props.t.ai_filled,
);

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    // transform é persistente no useForm: definido nos DOIS caminhos pra uma
    // edição de item da CMED não vazar pro próximo cadastro.
    if (!isEdit.value) {
        form.transform((data) => ({ ...data, medicine_presentation_id: data.medicine_presentation_id || null })).post(
            route('manager.medicines.store'),
            options,
        );
        return;
    }

    // CMED: só a posologia vai pro servidor (o resto vem da importação).
    form.transform((data) =>
        isCmed.value
            ? {
                  dosage: data.dosage,
                  frequency: data.frequency,
                  duration: data.duration,
                  instructions: data.instructions,
              }
            : { ...data, medicine_presentation_id: data.medicine_presentation_id || null },
    ).put(route('manager.medicines.update', props.medicine.id), options);
}
</script>

<template>
    <CenteredModal :open="open" size="lg" :close-label="t.close" @close="emit('close')">
        <template #header>
            <h5 class="mb-0">
                <i class="ti ti-pill me-1 text-primary"></i>
                {{ isEdit ? t.edit_title : t.new_title }}
            </h5>
        </template>

        <form id="medicine-form" @submit.prevent="submit">
            <div v-if="isCmed" class="alert alert-info py-2 small">
                <i class="ti ti-info-circle me-1"></i>{{ t.cmed_readonly_hint }}
                <div class="mt-1 text-body">
                    <strong>{{ medicine.name }}</strong>
                    <span v-if="medicine.concentration"> {{ medicine.concentration }}</span>
                    <span v-if="medicine.form"> · {{ medicine.form }}</span>
                    <div v-if="medicine.active_ingredient" class="text-muted">{{ medicine.active_ingredient }}</div>
                    <div v-if="medicine.presentation_detail" class="text-muted">
                        {{ medicine.presentation_detail }}
                    </div>
                </div>
            </div>

            <template v-else>
                <div class="row g-2 mb-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="med-name">
                            {{ t.field_name }} <span class="text-danger">*</span>
                        </label>
                        <input
                            id="med-name"
                            v-model="form.name"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.name }"
                            maxlength="255"
                        />
                        <div class="invalid-feedback">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="med-ingredient">{{
                            t.field_active_ingredient
                        }}</label>
                        <input
                            id="med-ingredient"
                            v-model="form.active_ingredient"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.active_ingredient }"
                            maxlength="1000"
                        />
                        <div class="invalid-feedback">{{ form.errors.active_ingredient }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="med-concentration">{{
                            t.field_concentration
                        }}</label>
                        <input
                            id="med-concentration"
                            v-model="form.concentration"
                            type="text"
                            class="form-control"
                            :placeholder="t.field_concentration_ph"
                            maxlength="255"
                        />
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="med-presentation">{{ t.field_presentation }}</label>
                        <select
                            id="med-presentation"
                            v-model="form.medicine_presentation_id"
                            class="form-select"
                            :class="{ 'is-invalid': form.errors.medicine_presentation_id }"
                        >
                            <option value="">—</option>
                            <option v-for="p in presentations" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                        <div class="invalid-feedback">{{ form.errors.medicine_presentation_id }}</div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <div class="form-check">
                        <input id="med-oft" v-model="form.is_ophthalmic" type="checkbox" class="form-check-input" />
                        <label for="med-oft" class="form-check-label">{{ t.field_is_ophthalmic }}</label>
                    </div>
                    <div class="form-check">
                        <input id="med-active" v-model="form.active" type="checkbox" class="form-check-input" />
                        <label for="med-active" class="form-check-label">{{ t.field_active }}</label>
                    </div>
                </div>
            </template>

            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
                <div>
                    <h6 class="fw-semibold mb-1">{{ t.posology_title }}</h6>
                    <p class="text-muted small mb-0">{{ t.posology_hint }}</p>
                </div>
                <div
                    v-if="aiAvailable"
                    ref="aiMenuRoot"
                    class="position-relative flex-shrink-0"
                    @keydown.esc.stop="aiMenuOpen = false"
                >
                    <button
                        type="button"
                        class="btn btn-sm btn-soft-primary ai-generate-btn"
                        :disabled="!canGenerate || ai.loading"
                        :title="t.ai_generate_hint"
                        :aria-haspopup="chooseAi ? 'menu' : undefined"
                        :aria-expanded="chooseAi ? String(aiMenuOpen) : undefined"
                        @click="onAiButton"
                    >
                        <span v-if="ai.loading" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-sparkles me-1" aria-hidden="true"></i>
                        {{ ai.loading ? t.ai_generating : t.ai_generate }}
                        <i v-if="chooseAi && !ai.loading" class="ti ti-chevron-down ms-1" aria-hidden="true"></i>
                    </button>
                    <ul
                        v-if="aiMenuOpen"
                        class="dropdown-menu dropdown-menu-end show mt-1 ai-provider-menu"
                        role="menu"
                        :aria-label="t.ai_menu_title"
                    >
                        <li>
                            <h6 class="dropdown-header">{{ t.ai_menu_title }}</h6>
                        </li>
                        <li v-for="p in aiProviders" :key="p.code" role="none">
                            <button
                                type="button"
                                class="dropdown-item d-flex align-items-center gap-2"
                                role="menuitem"
                                :data-provider="p.code"
                                @click="pickProvider(p.code)"
                            >
                                <i class="ti ti-sparkles text-primary" aria-hidden="true"></i>
                                <span class="flex-grow-1">{{ providerText(p) }}</span>
                                <small v-if="p.code === lastProvider" class="text-muted">{{ t.ai_last_used }}</small>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <div aria-live="polite">
                <div v-if="ai.previous" class="alert alert-warning py-2 small d-flex align-items-start gap-2 mb-2">
                    <i class="ti ti-sparkles mt-1" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        {{ filledText }}
                        <div v-if="ai.note" class="mt-1">{{ ai.note }}</div>
                    </div>
                    <button type="button" class="btn btn-link btn-sm p-0 text-nowrap" @click="undoAi">
                        <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>{{ t.ai_undo }}
                    </button>
                </div>
                <div v-else-if="ai.error" class="alert alert-danger py-2 small mb-2" role="alert">
                    {{ ai.error }}
                </div>
            </div>

            <div class="row g-2">
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="med-dosage">{{ t.field_dosage }}</label>
                    <input
                        id="med-dosage"
                        v-model="form.dosage"
                        type="text"
                        :disabled="ai.loading"
                        class="form-control form-control-sm"
                        :placeholder="t.field_dosage_ph"
                        maxlength="255"
                    />
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="med-frequency">{{ t.field_frequency }}</label>
                    <input
                        id="med-frequency"
                        v-model="form.frequency"
                        type="text"
                        :disabled="ai.loading"
                        class="form-control form-control-sm"
                        :placeholder="t.field_frequency_ph"
                        maxlength="255"
                    />
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="med-duration">{{ t.field_duration }}</label>
                    <input
                        id="med-duration"
                        v-model="form.duration"
                        type="text"
                        :disabled="ai.loading"
                        class="form-control form-control-sm"
                        :placeholder="t.field_duration_ph"
                        maxlength="255"
                    />
                </div>
                <div class="col-12">
                    <label class="form-label small" for="med-instructions">{{ t.field_instructions }}</label>
                    <textarea
                        id="med-instructions"
                        v-model="form.instructions"
                        :disabled="ai.loading"
                        class="form-control form-control-sm"
                        rows="2"
                        maxlength="2000"
                    ></textarea>
                </div>
            </div>
            <div v-if="Object.keys(form.errors).length && !form.errors.name" class="text-danger small mt-2">
                {{ Object.values(form.errors)[0] }}
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t.cancel }}</button>
            <button
                type="submit"
                form="medicine-form"
                class="btn btn-primary"
                :disabled="form.processing || ai.loading"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ t.save }}
            </button>
        </template>
    </CenteredModal>
</template>
