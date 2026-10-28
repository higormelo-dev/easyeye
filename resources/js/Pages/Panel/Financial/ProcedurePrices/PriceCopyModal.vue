<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans.js';
import { PREVIEW_EXAMPLES, planCopy } from './pricesBulk.js';

/**
 * "Copiar de outro convênio" da Tabela de Preços: escolhe o convênio de origem
 * (da clínica ou global — a lista vem do servidor), a página busca os preços
 * dele por recarga parcial (evento `load`) e a cópia é aplicada SÓ na grade,
 * com prévia. Sem "substituir", só as linhas sem preço recebem o preço.
 *
 * Emite `load` (id do convênio), `apply` com [{ index, to }] e `close`.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** Convênios de origem: [{ id, name }] (sem o convênio da grade). */
    sources: { type: Array, default: () => [] },
    /** Todas as linhas da grade: [{ row, index }]. */
    entries: { type: Array, default: () => [] },
    /** Preços do convênio escolhido (procedure_id → preço); null enquanto não carregou. */
    prices: { type: [Object, Array], default: null },
    loading: { type: Boolean, default: false },
    failed: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'load', 'apply']);

const { tx } = useTrans(() => props.t);
const { money, number } = useLocaleFormat();

const sourceId = ref('');
const overwrite = ref(false);
const sourceSelect = ref(null);

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;

        sourceId.value = '';
        overwrite.value = false;
        nextTick(() => sourceSelect.value?.focus?.());
    },
    { immediate: true },
);

function onSourceChange() {
    emit('load', sourceId.value);
}

const plan = computed(() =>
    sourceId.value && props.prices && !props.loading
        ? planCopy(props.entries, props.prices, { overwrite: overwrite.value })
        : null,
);

const examples = computed(() => plan.value?.changes.slice(0, PREVIEW_EXAMPLES) ?? []);
const canApply = computed(() => Boolean(plan.value?.changes.length));

const procedureLabel = (row) => `${row.code} ${row.name}`;

function exampleText(change) {
    const procedure = procedureLabel(change.row);

    return change.from === null
        ? tx('preview_example_new', { procedure, to: money(change.to) })
        : tx('preview_example', { procedure, from: money(change.from), to: money(change.to) });
}

function apply() {
    if (!canApply.value) return;

    emit(
        'apply',
        plan.value.changes.map(({ index, to }) => ({ index, to })),
    );
}

/* Esc fecha (o CenteredModal não trata teclado). */
function onKeydown(event) {
    if (event.key === 'Escape') emit('close');
}

watch(
    () => props.open,
    (isOpen) => {
        if (typeof document === 'undefined') return;
        if (isOpen) document.addEventListener('keydown', onKeydown);
        else document.removeEventListener('keydown', onKeydown);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <CenteredModal :open="open" size="md" @close="emit('close')">
        <template #header>
            <h2 class="h5 mb-0">
                <i class="ti ti-copy me-1 text-primary" aria-hidden="true"></i>{{ tx('copy_title') }}
            </h2>
        </template>

        <form novalidate data-test="copy-form" @submit.prevent="apply">
            <p class="small text-body-secondary mb-3">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ tx('bulk_local_hint') }}
                {{ tx('copy_scope_hint', { count: number(entries.length) }) }}
            </p>

            <p v-if="sources.length === 0" class="mb-3" data-test="copy-no-sources">{{ tx('copy_no_sources') }}</p>

            <div v-else class="mb-3">
                <label for="pp-copy-source" class="form-label">{{ tx('copy_source') }}</label>
                <select
                    id="pp-copy-source"
                    ref="sourceSelect"
                    v-model="sourceId"
                    class="form-select form-select-sm"
                    data-test="copy-source"
                    @change="onSourceChange"
                >
                    <option value="" disabled>{{ tx('copy_source_placeholder') }}</option>
                    <option v-for="source in sources" :key="source.id" :value="source.id">{{ source.name }}</option>
                </select>
            </div>

            <div class="form-check mb-3">
                <input
                    id="pp-copy-overwrite"
                    v-model="overwrite"
                    class="form-check-input"
                    type="checkbox"
                    aria-describedby="pp-copy-overwrite-help"
                    data-test="copy-overwrite"
                />
                <label class="form-check-label" for="pp-copy-overwrite">{{ tx('copy_overwrite') }}</label>
                <div id="pp-copy-overwrite-help" class="form-text">{{ tx('copy_overwrite_help') }}</div>
            </div>

            <div
                class="pp-preview rounded border p-2 small"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                data-test="copy-preview"
            >
                <p class="fw-semibold mb-1">{{ tx('preview_label') }}</p>
                <p v-if="loading" class="mb-0 d-flex align-items-center gap-2" data-test="copy-loading">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ tx('copy_loading') }}
                </p>
                <p v-else-if="failed" class="mb-0 text-danger-emphasis" data-test="copy-failed">
                    {{ tx('copy_load_error') }}
                </p>
                <p v-else-if="!plan" class="mb-0 text-body-secondary">{{ tx('copy_preview_empty') }}</p>
                <template v-else>
                    <p v-if="plan.changes.length === 0" class="mb-0" data-test="preview-none">
                        {{ tx('preview_none') }}
                    </p>
                    <template v-else>
                        <p class="mb-1" data-test="preview-count">
                            {{ tx('preview_changes', { count: number(plan.changes.length) }) }}
                        </p>
                        <p class="mb-1 text-body-secondary">{{ tx('preview_examples') }}</p>
                        <ul class="mb-1 ps-3">
                            <li v-for="change in examples" :key="change.index" data-test="preview-example">
                                {{ exampleText(change) }}
                            </li>
                        </ul>
                    </template>
                    <p v-if="plan.kept > 0" class="mb-0 text-body-secondary" data-test="preview-kept">
                        {{ tx('copy_kept', { count: number(plan.kept) }) }}
                    </p>
                    <p v-if="plan.missing > 0" class="mb-0 text-body-secondary" data-test="preview-missing">
                        {{ tx('copy_missing', { count: number(plan.missing) }) }}
                    </p>
                </template>
            </div>
        </form>

        <template #footer>
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                data-test="copy-cancel"
                @click="emit('close')"
            >
                {{ tx('bulk_cancel') }}
            </button>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                data-test="copy-apply"
                :disabled="!canApply"
                @click="apply"
            >
                <i class="ti ti-check me-1" aria-hidden="true"></i>{{ tx('copy_apply') }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.pp-preview {
    background-color: var(--bs-tertiary-bg);
}
</style>
