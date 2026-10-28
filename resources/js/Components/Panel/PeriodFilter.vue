<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import {
    CUSTOM_PRESET,
    MIN_DATE,
    PERIOD_PRESETS,
    detectPreset,
    isPartialYear,
    localToday,
    presetRange,
    rangeError,
} from '@/utils/periodPresets.js';

/**
 * PeriodFilter — período De/Até com atalhos (Hoje, Ontem, Últimos 7 dias, Mês
 * atual, Mês anterior, Ano atual) para as barras de filtro.
 *
 * - Atalho escolhido aplica na hora; datas digitadas aplicam no `change` do
 *   campo, só se o intervalo for válido (senão: aviso com role=alert e
 *   aria-invalid nos campos, nada é emitido).
 * - "Hoje" vem do servidor (`today`, fuso da clínica) — nunca do navegador.
 * - Textos pelo chamador (`labels`, ex.: t.shared.period); fallback pt-BR.
 *
 * Emits:
 *   update:from / update:to – v-model:from / v-model:to
 *   change                  – { from, to, preset } quando um período válido é aplicado
 *   invalid                 – { reason, from, to } quando o período digitado é recusado
 *                             (reason: invalid_date | invalid_range | after_max) — ex.:
 *                             desabilitar um botão que usaria o último período válido
 */
const props = defineProps({
    from: { type: String, default: '' },
    to: { type: String, default: '' },
    today: { type: String, default: '' },
    presets: { type: Array, default: () => PERIOD_PRESETS },
    /** Maior data aceita (ex.: fechamento de caixa não aceita futuro). */
    max: { type: String, default: '' },
    labels: { type: Object, default: () => ({}) },
    /** Rótulos visualmente ocultos (barra de filtros compacta). */
    compact: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:from', 'update:to', 'change', 'invalid']);

const { date } = useLocaleFormat();

const uid = useId();
const ids = {
    preset: `period-preset-${uid}`,
    from: `period-from-${uid}`,
    to: `period-to-${uid}`,
    error: `period-error-${uid}`,
};

const FALLBACK = {
    label: 'Período',
    from: 'De',
    to: 'Até',
    invalid_range: 'A data inicial deve ser anterior ou igual à final.',
    invalid_date: 'Informe uma data válida.',
    after_max: 'A data não pode ser posterior a :date.',
    presets: {
        today: 'Hoje',
        yesterday: 'Ontem',
        last7: 'Últimos 7 dias',
        month: 'Mês atual',
        last_month: 'Mês anterior',
        year: 'Ano atual',
        custom: 'Personalizado',
    },
};

const text = (key) => props.labels?.[key] ?? FALLBACK[key];
const presetLabel = (preset) => props.labels?.presets?.[preset] ?? FALLBACK.presets[preset] ?? preset;

const reference = computed(() => props.today || localToday());

const localFrom = ref(props.from);
const localTo = ref(props.to);
const error = ref('');

watch(
    () => [props.from, props.to],
    ([from, to]) => {
        localFrom.value = from;
        localTo.value = to;
        error.value = '';
    },
);

const selectedPreset = computed(() => detectPreset(localFrom.value, localTo.value, reference.value, props.presets));

function apply(from, to, preset) {
    const reason = rangeError(from, to, props.max);

    if (reason) {
        error.value =
            reason === 'after_max' ? String(text('after_max')).replaceAll(':date', date(props.max)) : text(reason);
        emit('invalid', { reason, from, to });

        return;
    }

    error.value = '';
    emit('update:from', from);
    emit('update:to', to);
    emit('change', { from, to, preset });
}

function onPreset(event) {
    const preset = event.target.value;

    // "Personalizado": mantém as datas e leva o foco para o "De".
    if (preset === CUSTOM_PRESET) {
        document.getElementById(ids.from)?.focus();

        return;
    }

    const range = presetRange(preset, reference.value);
    if (!range) return;

    localFrom.value = range.from;
    localTo.value = range.to;
    apply(range.from, range.to, preset);
}

// Ano ainda sendo digitado (Chrome dispara `change` a cada dígito): não aplica
// nem mostra erro até terminar — senão cada dígito virava uma consulta e o
// aviso piscava. Ao sair do campo, se continuar incompleto, aí sim é erro.
const typing = ref(false);

function onDate(field, value) {
    if (field === 'from') localFrom.value = value;
    else localTo.value = value;

    if (isPartialYear(localFrom.value) || isPartialYear(localTo.value)) {
        typing.value = true;
        error.value = '';

        return;
    }

    typing.value = false;
    apply(localFrom.value, localTo.value, selectedPreset.value);
}

function onDateBlur() {
    if (!typing.value) return;

    typing.value = false;
    apply(localFrom.value, localTo.value, selectedPreset.value);
}

const labelClass = computed(() => (props.compact ? 'visually-hidden' : 'form-label small mb-1'));
</script>

<template>
    <div class="period-filter" role="group" :aria-label="text('label')">
        <div class="d-flex flex-wrap align-items-end gap-2">
            <div class="period-filter__field">
                <label :for="ids.preset" :class="labelClass">{{ text('label') }}</label>
                <select
                    :id="ids.preset"
                    class="form-select form-select-sm period-filter__preset"
                    :value="selectedPreset"
                    :disabled="disabled"
                    data-test="period-preset"
                    @change="onPreset"
                >
                    <option v-for="preset in presets" :key="preset" :value="preset">{{ presetLabel(preset) }}</option>
                    <option value="custom">{{ presetLabel('custom') }}</option>
                </select>
            </div>
            <div class="period-filter__field">
                <label :for="ids.from" :class="labelClass">{{ text('from') }}</label>
                <input
                    :id="ids.from"
                    type="date"
                    class="form-control form-control-sm period-filter__date"
                    :class="{ 'is-invalid': error }"
                    :value="localFrom"
                    :min="MIN_DATE"
                    :max="max || undefined"
                    :disabled="disabled"
                    :aria-invalid="error ? 'true' : 'false'"
                    :aria-describedby="error ? ids.error : undefined"
                    data-test="period-from"
                    @change="onDate('from', $event.target.value)"
                    @blur="onDateBlur"
                />
            </div>
            <div class="period-filter__field">
                <label :for="ids.to" :class="labelClass">{{ text('to') }}</label>
                <input
                    :id="ids.to"
                    type="date"
                    class="form-control form-control-sm period-filter__date"
                    :class="{ 'is-invalid': error }"
                    :value="localTo"
                    :min="MIN_DATE"
                    :max="max || undefined"
                    :disabled="disabled"
                    :aria-invalid="error ? 'true' : 'false'"
                    :aria-describedby="error ? ids.error : undefined"
                    data-test="period-to"
                    @change="onDate('to', $event.target.value)"
                    @blur="onDateBlur"
                />
            </div>
        </div>
        <div v-if="error" :id="ids.error" class="small text-danger mt-1" role="alert" data-test="period-error">
            <i class="ti ti-alert-circle me-1" aria-hidden="true"></i>{{ error }}
        </div>
    </div>
</template>

<style scoped>
.period-filter__preset {
    min-width: 10.5rem;
}

.period-filter__date {
    min-width: 9.5rem;
}

/* Celular: atalho em linha inteira, De/Até lado a lado. */
@media (max-width: 575.98px) {
    .period-filter__field {
        flex: 1 1 calc(50% - 0.25rem);
    }

    .period-filter__field:first-child {
        flex-basis: 100%;
    }

    .period-filter__preset,
    .period-filter__date {
        min-width: 0;
        width: 100%;
    }
}
</style>
