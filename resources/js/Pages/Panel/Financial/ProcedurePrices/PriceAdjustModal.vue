<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans.js';
import { parseMoneyInput } from '@/utils/money.js';
import {
    ADJUST_PERCENT_MIN,
    PREVIEW_EXAMPLES,
    adjustPercentLimit,
    isValidAdjustPercent,
    planAdjustment,
} from './pricesBulk.js';

/**
 * "Reajustar %" da Tabela de Preços: aumento ou redução percentual aplicado
 * SÓ na grade (nada é salvo até "Salvar preços"), nas linhas visíveis (busca
 * e filtro atuais) ou em todas — com prévia antes de aplicar. Linha sem preço
 * próprio (vazia ou no padrão do sistema) fica como está. Cada novo preço é
 * arredondado aos centavos (pricesBulk.adjustPrice).
 *
 * Emite `apply` com [{ index, to }] (índices da grade) e `close`.
 */
const props = defineProps({
    open:           { type: Boolean, default: false },
    /** Linhas na tela agora: [{ row, index }]. */
    visibleEntries: { type: Array,   default: () => [] },
    /** Todas as linhas da grade: [{ row, index }]. */
    allEntries:     { type: Array,   default: () => [] },
    t:              { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'apply']);

const { tx } = useTrans(() => props.t);
const { locale, money, number } = useLocaleFormat();

const direction    = ref('increase');
const percentText  = ref('');
const scope        = ref('all');
const percentInput = ref(null);

const filtered = computed(() => props.visibleEntries.length !== props.allEntries.length);

watch(() => props.open, (isOpen) => {
    if (!isOpen) return;

    direction.value   = 'increase';
    percentText.value = '';
    // Com busca/filtro ativo, o padrão é o que está na tela.
    scope.value = filtered.value ? 'visible' : 'all';
    nextTick(() => percentInput.value?.focus?.());
}, { immediate: true });

const percent      = computed(() => parseMoneyInput(percentText.value, locale.value));
const percentValid = computed(() => isValidAdjustPercent(percent.value, direction.value));
const showError    = computed(() => percentText.value.trim() !== '' && !percentValid.value);

const entries = computed(() => (scope.value === 'visible' ? props.visibleEntries : props.allEntries));

const plan = computed(() => {
    if (!percentValid.value) return null;

    const signed = direction.value === 'decrease' ? -percent.value : percent.value;

    return planAdjustment(entries.value, signed);
});

const examples = computed(() => plan.value?.changes.slice(0, PREVIEW_EXAMPLES) ?? []);
const canApply = computed(() => Boolean(plan.value?.changes.length));

function formatPercent(value) {
    return new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 2 }).format(value / 100);
}

const rangeMessage = computed(() => tx('adjust_percent_range', {
    min: formatPercent(ADJUST_PERCENT_MIN),
    max: formatPercent(adjustPercentLimit(direction.value)),
}));

const procedureLabel = (row) => `${row.code} ${row.name}`;

function apply() {
    if (!canApply.value) return;

    emit('apply', plan.value.changes.map(({ index, to }) => ({ index, to })));
}

/* Esc fecha (o CenteredModal não trata teclado). */
function onKeydown(event) {
    if (event.key === 'Escape') emit('close');
}

watch(() => props.open, (isOpen) => {
    if (typeof document === 'undefined') return;
    if (isOpen) document.addEventListener('keydown', onKeydown);
    else document.removeEventListener('keydown', onKeydown);
}, { immediate: true });

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <CenteredModal :open="open" size="md" @close="emit('close')">
        <template #header>
            <h2 class="h5 mb-0"><i class="ti ti-percentage me-1 text-primary" aria-hidden="true"></i>{{ tx('adjust_title') }}</h2>
        </template>

        <form novalidate data-test="adjust-form" @submit.prevent="apply">
            <p class="small text-body-secondary mb-3">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ tx('bulk_local_hint') }}
            </p>

            <fieldset class="mb-3">
                <legend class="form-label fs-6 mb-2">{{ tx('adjust_direction') }}</legend>
                <div class="btn-group btn-group-sm" role="group">
                    <input id="pp-adjust-increase" v-model="direction" class="btn-check" type="radio" name="pp-adjust-direction" value="increase" data-test="adjust-increase">
                    <label class="btn btn-outline-primary" for="pp-adjust-increase">
                        <i class="ti ti-trending-up me-1" aria-hidden="true"></i>{{ tx('adjust_increase') }}
                    </label>
                    <input id="pp-adjust-decrease" v-model="direction" class="btn-check" type="radio" name="pp-adjust-direction" value="decrease" data-test="adjust-decrease">
                    <label class="btn btn-outline-primary" for="pp-adjust-decrease">
                        <i class="ti ti-trending-down me-1" aria-hidden="true"></i>{{ tx('adjust_decrease') }}
                    </label>
                </div>
            </fieldset>

            <div class="mb-3">
                <label for="pp-adjust-percent" class="form-label">{{ tx('adjust_percent') }}</label>
                <div class="input-group input-group-sm pp-adjust-percent">
                    <input
                        id="pp-adjust-percent"
                        ref="percentInput"
                        v-model="percentText"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        class="form-control text-end"
                        :class="{ 'is-invalid': showError }"
                        :aria-invalid="showError ? 'true' : 'false'"
                        :aria-describedby="showError ? 'pp-adjust-percent-error' : 'pp-adjust-percent-help'"
                        data-test="adjust-percent"
                    >
                    <span class="input-group-text" aria-hidden="true">%</span>
                </div>
                <div v-if="showError" id="pp-adjust-percent-error" class="invalid-feedback d-block" data-test="adjust-percent-error">{{ rangeMessage }}</div>
                <div v-else id="pp-adjust-percent-help" class="form-text">{{ tx('adjust_percent_help') }}</div>
            </div>

            <fieldset class="mb-3">
                <legend class="form-label fs-6 mb-2">{{ tx('adjust_scope') }}</legend>
                <div class="form-check">
                    <input id="pp-adjust-scope-visible" v-model="scope" class="form-check-input" type="radio" name="pp-adjust-scope" value="visible" data-test="adjust-scope-visible">
                    <label class="form-check-label" for="pp-adjust-scope-visible">{{ tx('adjust_scope_visible', { count: number(visibleEntries.length) }) }}</label>
                </div>
                <div class="form-check">
                    <input id="pp-adjust-scope-all" v-model="scope" class="form-check-input" type="radio" name="pp-adjust-scope" value="all" data-test="adjust-scope-all">
                    <label class="form-check-label" for="pp-adjust-scope-all">{{ tx('adjust_scope_all', { count: number(allEntries.length) }) }}</label>
                </div>
            </fieldset>

            <div class="pp-preview rounded border p-2 small" role="status" aria-live="polite" aria-atomic="true" data-test="adjust-preview">
                <p class="fw-semibold mb-1">{{ tx('preview_label') }}</p>
                <p v-if="!plan" class="mb-0 text-body-secondary">{{ tx('adjust_preview_empty') }}</p>
                <template v-else>
                    <p v-if="plan.changes.length === 0" class="mb-0" data-test="preview-none">{{ tx('preview_none') }}</p>
                    <template v-else>
                        <p class="mb-1" data-test="preview-count">{{ tx('preview_changes', { count: number(plan.changes.length) }) }}</p>
                        <p class="mb-1 text-body-secondary">{{ tx('preview_examples') }}</p>
                        <ul class="mb-1 ps-3">
                            <li v-for="change in examples" :key="change.index" data-test="preview-example">
                                {{ tx('preview_example', { procedure: procedureLabel(change.row), from: money(change.from), to: money(change.to) }) }}
                            </li>
                        </ul>
                    </template>
                    <p v-if="plan.skipped > 0" class="mb-0 text-body-secondary" data-test="preview-skipped">{{ tx('adjust_skipped', { count: number(plan.skipped) }) }}</p>
                </template>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-test="adjust-cancel" @click="emit('close')">{{ tx('bulk_cancel') }}</button>
            <button type="button" class="btn btn-primary btn-sm" data-test="adjust-apply" :disabled="!canApply" @click="apply">
                <i class="ti ti-check me-1" aria-hidden="true"></i>{{ tx('adjust_apply') }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.pp-adjust-percent {
    max-width: 12rem;
}

.pp-preview {
    background-color: var(--bs-tertiary-bg);
}
</style>
