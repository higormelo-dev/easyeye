<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { batchFilterParams, filterChips, usd } from './posologyBatch.js';

/**
 * Prévia/confirmação do lote "Gerar posologia com IA" com os filtros
 * APLICADOS na lista: quantos medicamentos sem posologia, quantos grupos de
 * itens iguais (= chamadas de IA), teto por lote, IA e custo estimado.
 * Nada é enviado à IA até confirmar.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    // Filtros já aplicados na lista (normalizados pelo servidor).
    filters: { type: Object, default: () => ({}) },
    // IAs configuradas ({code, label, model}); com mais de uma, o admin escolhe.
    aiProviders: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'started', 'running']);

const { number, money } = useLocaleFormat();
const page = usePage();
const locale = computed(() => String(page?.props?.locale ?? 'pt_BR').replace('_', '-'));

const preview = ref(null);
const loading = ref(false);
const loadError = ref('');
let requestId = 0;

// Mesma IA lembrada pelo botão "Gerar com IA" do formulário (conveniência).
const AI_PREF_KEY = 'mgr_medicines_ai_provider';

function readLastProvider() {
    try {
        return localStorage.getItem(AI_PREF_KEY) ?? '';
    } catch {
        return '';
    }
}

function rememberProvider(code) {
    try {
        localStorage.setItem(AI_PREF_KEY, code);
    } catch {
        // armazenamento bloqueado: vale só nesta visita
    }
}

const form = useForm({ provider: '' });
const chooseAi = computed(() => props.aiProviders.length > 1);

function defaultProvider() {
    const last = readLastProvider();
    return props.aiProviders.some((p) => p.code === last) ? last : (props.aiProviders[0]?.code ?? '');
}

async function loadPreview() {
    const request = ++requestId;
    loading.value = true;
    loadError.value = '';
    preview.value = null;

    try {
        const { data } = await axios.get(route('manager.medicines.posology-batches.preview'), {
            params: batchFilterParams(props.filters),
        });
        if (request !== requestId) return;

        // Outro lote já está rodando: a tela mostra o progresso dele.
        if (data.running && !data.running.is_done) {
            emit('running', data.running);
            emit('close');
            return;
        }
        preview.value = data;
    } catch (error) {
        if (request !== requestId) return;
        loadError.value = error.response?.status === 429 ? props.t.ai_rate_limited : props.t.batch_preview_failed;
    } finally {
        if (request === requestId) loading.value = false;
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            requestId++;
            return;
        }
        form.clearErrors();
        form.provider = defaultProvider();
        loadPreview();
    },
    { immediate: true },
);

const chips = computed(() => filterChips(props.filters, props.t));
const selected = computed(() => props.aiProviders.find((p) => p.code === form.provider) ?? null);
const estimate = computed(() => preview.value?.estimates?.[form.provider] ?? null);
const hasWork = computed(() => (preview.value?.batch_groups ?? 0) > 0);
const canConfirm = computed(() => hasWork.value && !!form.provider && !form.processing && !loading.value);

const costText = computed(() => {
    if (!preview.value || !hasWork.value) return '';
    if (estimate.value?.usd == null) {
        return (props.t.batch_cost_unavailable ?? '').replace(':calls', number(preview.value.batch_groups));
    }
    return (props.t.batch_cost_value ?? ':usd (≈ :brl)')
        .replace(':usd', usd(estimate.value.usd, locale.value))
        .replace(':brl', money(estimate.value.brl));
});

const capText = computed(() =>
    (props.t.batch_cap_notice ?? '')
        .replace(':cap', number(preview.value?.cap ?? 0))
        .replace(':remaining', number(preview.value?.remaining_groups ?? 0)),
);

function providerText(p) {
    return p.model ? `${p.label} · ${p.model}` : p.label;
}

function confirm() {
    if (!canConfirm.value) return;

    rememberProvider(form.provider);
    form.transform((data) => ({ ...batchFilterParams(props.filters), provider: data.provider })).post(
        route('manager.medicines.posology-batches.store'),
        {
            preserveScroll: true,
            onSuccess: () => emit('started'),
        },
    );
}

const errorText = computed(() => form.errors.batch ?? form.errors.provider ?? Object.values(form.errors)[0] ?? '');
</script>

<template>
    <CenteredModal :open="open" size="md" :close-label="t.close" @close="emit('close')">
        <template #header>
            <h5 class="mb-0">
                <i class="ti ti-sparkles me-1 text-primary" aria-hidden="true"></i>{{ t.batch_modal_title }}
            </h5>
        </template>

        <p class="small text-muted mb-2">{{ t.batch_scope_hint }}</p>

        <div class="mb-3">
            <div class="small fw-semibold mb-1">{{ t.batch_filters_applied }}</div>
            <div class="d-flex flex-wrap gap-1">
                <span v-for="chip in chips" :key="chip" class="badge badge-soft-secondary rounded fs-12 batch-chip">{{
                    chip
                }}</span>
                <span v-if="!chips.length" class="small text-muted">{{ t.batch_filters_none }}</span>
            </div>
        </div>

        <div aria-live="polite">
            <div v-if="loading" class="text-center text-muted py-4 small" role="status">
                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                >{{ t.batch_preview_loading }}
            </div>
            <div v-else-if="loadError" class="alert alert-danger py-2 small" role="alert">
                {{ loadError }}
                <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" @click="loadPreview">
                    {{ t.import_refresh_status }}
                </button>
            </div>
            <template v-else-if="preview">
                <div v-if="!hasWork" class="alert alert-light border small mb-0" data-test="nothing">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.batch_nothing_to_do }}
                </div>
                <template v-else>
                    <dl class="row g-2 mb-2 batch-stats">
                        <div class="col-6">
                            <dt class="small text-muted fw-normal">{{ t.batch_stat_medicines }}</dt>
                            <dd class="fs-5 fw-semibold mb-0" data-stat="medicines">{{ number(preview.medicines) }}</dd>
                        </div>
                        <div class="col-6">
                            <dt class="small text-muted fw-normal">{{ t.batch_stat_groups }}</dt>
                            <dd class="fs-5 fw-semibold mb-0" data-stat="groups">{{ number(preview.groups) }}</dd>
                        </div>
                        <div class="col-6">
                            <dt class="small text-muted fw-normal">{{ t.batch_stat_calls }}</dt>
                            <dd class="fs-5 fw-semibold mb-0 text-primary" data-stat="calls">
                                {{ number(preview.batch_groups) }}
                            </dd>
                        </div>
                        <div class="col-6">
                            <dt class="small text-muted fw-normal">{{ t.batch_stat_batch_medicines }}</dt>
                            <dd class="fs-5 fw-semibold mb-0" data-stat="batch-medicines">
                                {{ number(preview.batch_medicines) }}
                            </dd>
                        </div>
                    </dl>
                    <p class="small text-muted mb-2">{{ t.batch_grouping_hint }}</p>
                    <div v-if="preview.remaining_groups > 0" class="alert alert-warning py-2 small" data-test="cap">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ capText }}
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1" for="batch-provider">{{
                            t.batch_provider
                        }}</label>
                        <select
                            v-if="chooseAi"
                            id="batch-provider"
                            v-model="form.provider"
                            class="form-select form-select-sm"
                            :class="{ 'is-invalid': form.errors.provider }"
                        >
                            <option v-for="p in aiProviders" :key="p.code" :value="p.code">
                                {{ providerText(p) }}
                            </option>
                        </select>
                        <div v-else id="batch-provider" class="small">
                            {{ selected ? providerText(selected) : '—' }}
                        </div>
                    </div>

                    <div class="small mb-2">
                        <span class="fw-semibold me-1">{{ t.batch_cost }}:</span>
                        <span data-test="cost">{{ costText }}</span>
                        <div v-if="estimate?.usd != null && preview.usd_brl?.is_fallback" class="text-muted">
                            {{ t.batch_cost_fallback_rate }}
                        </div>
                    </div>

                    <div class="alert alert-info py-2 small mb-0">
                        <i class="ti ti-sparkles me-1" aria-hidden="true"></i>{{ t.batch_review_hint }}
                    </div>
                </template>
            </template>

            <div v-if="errorText" class="alert alert-danger py-2 small mt-2 mb-0" role="alert">{{ errorText }}</div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t.cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="!canConfirm" data-test="confirm" @click="confirm">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-sparkles me-1" aria-hidden="true"></i>{{ t.batch_confirm }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.batch-chip {
    max-width: 100%;
    white-space: normal;
    text-align: start;
}
</style>
