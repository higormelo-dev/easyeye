<script setup>
import { computed, useId } from 'vue';
import { documentTexts, td, fill, formatIsoDate, dateInRange } from '@/Support/clinicalDocuments.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Data do documento (data alternativa — Fase 2 / B3): receita, relatório,
 * pedido de exames e atestados. Em branco = hoje. A faixa (`config.min` /
 * `config.max`, padrão 30 dias antes e depois) é do servidor, que revalida;
 * o emitido de fato fica registrado.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    // { today, min, max } — DocumentDate::clientConfig()
    config: { type: Object, default: () => ({}) },
    error: { type: String, default: '' },
    t: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue']);

const texts = documentTexts(props.t);
const tt = (path, fallback) => td(texts, path, fallback);
const uid = useId();
const { locale } = useLocaleFormat();

const value = computed({
    get: () => props.modelValue ?? '',
    set: (v) => emit('update:modelValue', v ?? ''),
});

const outOfRange = computed(() => !dateInRange(value.value, props.config));
const isAlternative = computed(() => Boolean(value.value) && value.value !== props.config?.today);
const rangeHint = computed(() =>
    props.config?.min && props.config?.max
        ? fill(tt('date.range_hint', 'Permitido de :min a :max.'), {
              min: formatIsoDate(props.config.min, locale.value),
              max: formatIsoDate(props.config.max, locale.value),
          })
        : '',
);
const message = computed(
    () =>
        props.error ||
        (outOfRange.value
            ? fill(tt('date.range_error', 'A data do documento deve ficar entre :min e :max.'), {
                  min: formatIsoDate(props.config.min, locale.value),
                  max: formatIsoDate(props.config.max, locale.value),
              })
            : ''),
);
</script>

<template>
    <div class="ee-doc-date" data-testid="document-date-field">
        <label class="form-label small mb-1" :for="`${uid}-date`">{{ tt('date.label', 'Data do documento') }}</label>
        <div class="d-flex gap-2 align-items-center">
            <input
                :id="`${uid}-date`"
                v-model="value"
                type="date"
                class="form-control form-control-sm ee-doc-date__input"
                :class="{ 'is-invalid': message }"
                :min="config?.min || undefined"
                :max="config?.max || undefined"
                :aria-describedby="`${uid}-hint`"
                :aria-invalid="message ? 'true' : 'false'"
                data-testid="document-date-input"
            />
            <button
                v-if="isAlternative"
                type="button"
                class="btn btn-link btn-sm p-0 text-nowrap"
                data-testid="document-date-today"
                @click="value = ''"
            >
                {{ tt('date.today', 'Hoje') }}
            </button>
        </div>
        <div v-if="message" class="invalid-feedback d-block" role="alert">{{ message }}</div>
        <small :id="`${uid}-hint`" class="form-text d-block">
            <span v-if="isAlternative" class="badge bg-warning-subtle text-warning-emphasis me-1">
                <i class="ti ti-calendar-event me-1" aria-hidden="true"></i
                >{{ fill(tt('date.printed', 'Data impressa: :date'), { date: formatIsoDate(value, locale) }) }}
            </span>
            {{ tt('date.hint', 'Em branco = hoje. A data em que o documento foi emitido de fato fica registrada.') }}
            {{ rangeHint }}
        </small>
    </div>
</template>

<style scoped>
.ee-doc-date__input {
    max-width: 11rem;
}
</style>
