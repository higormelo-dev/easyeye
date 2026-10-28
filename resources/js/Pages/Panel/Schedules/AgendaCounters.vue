<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Contadores do dia no topo da Agenda — cada um é também o filtro (chip) da
 * lista. Mesma conta do Dashboard (ClinicOperationsService::dayCounters no
 * servidor). "Agendados" = todos (limpa o filtro); clicar no chip ativo
 * também limpa.
 */
const props = defineProps({
    counters: { type: Object, default: () => ({}) },
    active: { type: String, default: null },
    // Pronto atendimento: fila de agora (`counters.urgent_care`, null fora de
    // hoje). Não filtra a lista — leva à tela da fila.
    urgentCareUrl: { type: String, default: null },
    t: { type: Object, required: true },
});

const emit = defineEmits(['select']);

const { number } = useLocaleFormat();

const CHIPS = [
    { key: 'total', icon: 'ti ti-calendar-event' },
    { key: 'confirmed', icon: 'ti ti-circle-check' },
    { key: 'unconfirmed', icon: 'ti ti-help-circle' },
    { key: 'not_arrived', icon: 'ti ti-clock-exclamation' },
    { key: 'waiting', icon: 'ti ti-armchair' },
    { key: 'in_care', icon: 'ti ti-stethoscope' },
    { key: 'attended', icon: 'ti ti-checks' },
    { key: 'no_show', icon: 'ti ti-user-x' },
    { key: 'cancelled', icon: 'ti ti-ban' },
    { key: 'fit_in', icon: 'ti ti-arrow-autofit-height' },
    { key: 'blocked', icon: 'ti ti-lock' },
];

const chips = computed(() =>
    CHIPS.map((chip) => ({
        ...chip,
        value: Number(props.counters?.[chip.key] ?? 0),
        label: props.t[`counter_${chip.key}`] ?? chip.key,
        hint: props.t[`counter_${chip.key}_hint`] ?? '',
        pressed: chip.key === 'total' ? !props.active : props.active === chip.key,
    })),
);

const urgentCare = computed(() => {
    const value = props.counters?.urgent_care;
    if (value === null || value === undefined || !props.urgentCareUrl) return null;
    return {
        value: Number(value),
        label: props.t.counter_urgent_care ?? 'Pronto atendimento',
        hint: props.t.counter_urgent_care_hint ?? '',
    };
});

function onClick(chip) {
    if (chip.key === 'total' || props.active === chip.key) {
        emit('select', null);
        return;
    }
    emit('select', chip.key);
}
</script>

<template>
    <div class="agenda-counters" role="group" :aria-label="t.counters_label ?? 'Situação dos agendamentos'">
        <button
            v-for="chip in chips"
            :key="chip.key"
            type="button"
            class="ac-chip"
            :class="[`ac-chip--${chip.key}`, { 'is-active': chip.pressed, 'is-zero': chip.value === 0 }]"
            :aria-pressed="chip.pressed ? 'true' : 'false'"
            :title="chip.hint || chip.label"
            :data-counter="chip.key"
            @click="onClick(chip)"
        >
            <i :class="chip.icon" class="ac-icon" aria-hidden="true"></i>
            <span class="ac-value">{{ number(chip.value) }}</span>
            <span class="ac-label">{{ chip.label }}</span>
        </button>
        <Link
            v-if="urgentCare"
            :href="urgentCareUrl"
            class="ac-chip ac-chip--urgent_care"
            :class="{ 'is-zero': urgentCare.value === 0 }"
            :title="urgentCare.hint || urgentCare.label"
            data-counter="urgent_care"
        >
            <i class="ti ti-urgent ac-icon" aria-hidden="true"></i>
            <span class="ac-value">{{ number(urgentCare.value) }}</span>
            <span class="ac-label">{{ urgentCare.label }}</span>
            <i class="ti ti-arrow-up-right ac-link-icon" aria-hidden="true"></i>
        </Link>
    </div>
</template>

<style scoped>
.agenda-counters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

/* Celular: uma linha só, rolando de lado (a lista aparece sem rolar a tela). */
@media (max-width: 767.98px) {
    .agenda-counters {
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: 0.25rem;
        scroll-snap-type: x proximity;
        -webkit-overflow-scrolling: touch;
    }

    .ac-chip {
        scroll-snap-align: start;
        flex-shrink: 0;
    }
}

.ac-chip {
    --ac-color: #6c757d;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.3rem 0.65rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-left: 3px solid var(--ac-color);
    border-radius: 0.5rem;
    background: var(--bs-body-bg, #fff);
    color: var(--bs-body-color, #212529);
    font-size: 0.78rem;
    line-height: 1.2;
    white-space: nowrap;
    cursor: pointer;
    transition:
        background 0.15s,
        border-color 0.15s;
}

.ac-chip:hover {
    background: var(--bs-tertiary-bg, #f8f9fa);
}

.ac-chip:focus-visible {
    outline: 2px solid var(--bs-primary, #0d6efd);
    outline-offset: 2px;
}

.ac-icon {
    color: var(--ac-color);
    font-size: 0.95rem;
}

.ac-value {
    font-weight: 700;
    font-size: 0.9rem;
    font-variant-numeric: tabular-nums;
}

.ac-label {
    color: var(--bs-secondary-color, #6c757d);
}

.ac-chip.is-zero .ac-value {
    color: var(--bs-secondary-color, #6c757d);
    font-weight: 600;
}

.ac-chip.is-active {
    background: var(--ac-color);
    border-color: var(--ac-color);
    color: #fff;
}

.ac-chip.is-active .ac-icon,
.ac-chip.is-active .ac-label,
.ac-chip.is-active .ac-value {
    color: #fff;
}

/* Cor de cada situação (mesma família dos selos da agenda). */
.ac-chip--total {
    --ac-color: #0d6efd;
}
.ac-chip--confirmed {
    --ac-color: #0aa2c0;
}
.ac-chip--unconfirmed {
    --ac-color: #6c757d;
}
.ac-chip--not_arrived {
    --ac-color: #c2410c;
}
.ac-chip--waiting {
    --ac-color: #b7791f;
}
.ac-chip--in_care {
    --ac-color: #0b5ed7;
}
.ac-chip--attended {
    --ac-color: #198754;
}
.ac-chip--no_show {
    --ac-color: #dc3545;
}
.ac-chip--cancelled {
    --ac-color: #343a40;
}
.ac-chip--fit_in {
    --ac-color: #7c3aed;
}
.ac-chip--blocked {
    --ac-color: #6b7280;
}
.ac-chip--urgent_care {
    --ac-color: #dc2626;
    text-decoration: none;
}
.ac-link-icon {
    font-size: 0.75rem;
    color: var(--bs-secondary-color, #6c757d);
}
[data-bs-theme='dark'] .ac-chip--urgent_care {
    --ac-color: #f87171;
}

[data-bs-theme='dark'] .ac-chip--cancelled {
    --ac-color: #adb5bd;
}
[data-bs-theme='dark'] .ac-chip--cancelled.is-active .ac-value,
[data-bs-theme='dark'] .ac-chip--cancelled.is-active .ac-label,
[data-bs-theme='dark'] .ac-chip--cancelled.is-active .ac-icon {
    color: #111827;
}
</style>
