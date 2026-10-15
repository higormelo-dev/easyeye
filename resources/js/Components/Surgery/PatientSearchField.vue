<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Busca de paciente (Pacientes › search) com lista de resultados acessível.
 * v-model = paciente escolhido ({ id, full_name, code, covenant_id }) ou null.
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
    searchUrl: { type: String, required: true },
    label: { type: String, required: true },
    placeholder: { type: String, default: '' },
    noResults: { type: String, default: '' },
    changeLabel: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { date } = useLocaleFormat();

const inputId = `surgery-patient-${useId()}`;
const term = ref('');
const results = ref([]);
const searching = ref(false);
const searched = ref(false);

let timer = null;
let ticket = 0;

const showEmpty = computed(() => searched.value && !searching.value && results.value.length === 0);

function onInput() {
    clearTimeout(timer);
    searched.value = false;

    if (term.value.trim().length < 2) {
        results.value = [];
        return;
    }

    timer = setTimeout(run, 300);
}

async function run() {
    const current = ++ticket;
    searching.value = true;

    try {
        const res = await fetch(`${props.searchUrl}?q=${encodeURIComponent(term.value.trim())}`, {
            headers: { Accept: 'application/json' },
        });
        const json = res.ok ? await res.json() : [];

        if (current === ticket) results.value = Array.isArray(json) ? json : [];
    } catch {
        if (current === ticket) results.value = [];
    } finally {
        if (current === ticket) {
            searching.value = false;
            searched.value = true;
        }
    }
}

function choose(patient) {
    emit('update:modelValue', patient);
    term.value = '';
    results.value = [];
    searched.value = false;
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div>
        <label class="form-label fw-medium" :for="inputId">{{ label }}</label>

        <div v-if="modelValue" class="d-flex align-items-center gap-2 border rounded px-3 py-2">
            <i class="ti ti-user" aria-hidden="true"></i>
            <strong class="me-auto">{{ modelValue.full_name }}</strong>
            <span v-if="modelValue.birth_date" class="text-muted small">{{ date(modelValue.birth_date) }}</span>
            <span v-if="modelValue.code" class="text-muted small">{{ modelValue.code }}</span>
            <button
                v-if="!disabled"
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-testid="surgery-patient-change"
                @click="emit('update:modelValue', null)"
            >
                {{ changeLabel }}
            </button>
        </div>

        <div v-else class="position-relative">
            <input
                :id="inputId"
                v-model="term"
                type="search"
                class="form-control"
                :class="{ 'is-invalid': invalid }"
                :placeholder="placeholder"
                autocomplete="off"
                :disabled="disabled"
                data-testid="surgery-patient-search"
                @input="onInput"
            />
            <ul
                v-if="results.length"
                class="list-group position-absolute w-100 shadow-sm"
                style="z-index: 5; max-height: 240px; overflow-y: auto"
            >
                <li v-for="patient in results" :key="patient.id" class="list-group-item p-0">
                    <button
                        type="button"
                        class="btn btn-link text-start text-decoration-none w-100 px-3 py-2"
                        data-testid="surgery-patient-option"
                        @click="choose(patient)"
                    >
                        <span class="fw-medium">{{ patient.full_name }}</span>
                        <span v-if="patient.birth_date" class="text-muted small ms-2">{{
                            date(patient.birth_date)
                        }}</span>
                        <span class="text-muted small ms-2">{{ patient.code }}</span>
                    </button>
                </li>
            </ul>
            <div v-if="showEmpty" class="form-text">{{ noResults }}</div>
            <span class="visually-hidden" aria-live="polite">{{ searching ? '…' : '' }}</span>
        </div>
    </div>
</template>
