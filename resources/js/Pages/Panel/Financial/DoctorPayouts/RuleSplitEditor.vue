<script setup>
import { computed, useId } from 'vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Divisão do grupo numa regra percentual (E4): o % da regra é a parte dos
 * médicos sobre o recebido líquido — a clínica fica com o restante — e os
 * participantes (executor = médico do item; médicos fixos, ex.: líder)
 * dividem o grupo em % que somam 100%. Sem participantes, o executor fica
 * com o grupo inteiro. O servidor revalida (DoctorPayoutRuleRequest).
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] }, // [{ role, doctor_id, percentage }]
    groupPercentage: { type: [Number, String], default: '' },
    doctors: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const { tx, quantity, doctorLabel } = useDoctorPayoutFormat(() => props.t);

const uid = useId();
const legend = `dp-split-legend-${uid}`;

const clinicKeeps = computed(() => {
    const group = Number(props.groupPercentage);

    return props.groupPercentage === '' || Number.isNaN(group) ? null : Math.max(0, 100 - group);
});

const sum = computed(() =>
    props.modelValue.reduce((total, participant) => total + (Number(participant.percentage) || 0), 0),
);
const sumOk = computed(() => Math.abs(sum.value - 100) < 0.005);
const hasExecutor = computed(() => props.modelValue.some((participant) => participant.role === 'executor'));

function update(index, patch) {
    emit(
        'update:modelValue',
        props.modelValue.map((participant, i) => (i === index ? { ...participant, ...patch } : participant)),
    );
}

function add() {
    emit('update:modelValue', [
        ...props.modelValue,
        { role: hasExecutor.value ? 'doctor' : 'executor', doctor_id: '', percentage: '' },
    ]);
}

function remove(index) {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}

const errorFor = (index, field) => props.errors?.[`participants.${index}.${field}`] ?? '';
</script>

<template>
    <fieldset class="col-12" :aria-labelledby="legend" data-test="split-editor">
        <legend :id="legend" class="form-label fs-6 mb-1">{{ t.split_title }}</legend>
        <p class="small text-muted mb-2">{{ t.split_intro }}</p>
        <p v-if="clinicKeeps !== null" class="small fw-medium mb-2" data-test="split-clinic">
            {{ tx('split_clinic_keeps', { value: quantity(clinicKeeps, 2) }) }}
        </p>

        <ul v-if="modelValue.length" class="list-unstyled d-grid gap-2 mb-2">
            <li
                v-for="(participant, index) in modelValue"
                :key="index"
                class="d-flex flex-wrap align-items-start gap-2"
                data-test="split-row"
            >
                <select
                    class="form-select form-select-sm split-editor__role"
                    :value="participant.role"
                    :disabled="disabled"
                    :aria-label="`${t.split_title} ${index + 1}`"
                    data-test="split-role"
                    @change="update(index, { role: $event.target.value, doctor_id: '' })"
                >
                    <option value="executor">{{ t.split_executor }}</option>
                    <option value="doctor">{{ t.split_doctor }}</option>
                </select>

                <div v-if="participant.role === 'doctor'" class="split-editor__doctor">
                    <select
                        class="form-select form-select-sm"
                        :class="{ 'is-invalid': errorFor(index, 'doctor_id') }"
                        :value="participant.doctor_id ?? ''"
                        :disabled="disabled"
                        :aria-label="t.split_doctor"
                        :aria-invalid="errorFor(index, 'doctor_id') ? 'true' : undefined"
                        data-test="split-doctor"
                        @change="update(index, { doctor_id: $event.target.value })"
                    >
                        <option value="">{{ t.filter_doctor_placeholder }}</option>
                        <option v-for="doctor in doctors" :key="doctor.id" :value="doctor.id">
                            {{ doctorLabel(doctor) }}
                        </option>
                    </select>
                    <div v-if="errorFor(index, 'doctor_id')" class="invalid-feedback d-block">
                        {{ errorFor(index, 'doctor_id') }}
                    </div>
                </div>

                <div class="input-group input-group-sm split-editor__percentage">
                    <input
                        type="number"
                        min="0.01"
                        max="100"
                        step="0.01"
                        inputmode="decimal"
                        class="form-control text-end"
                        :class="{ 'is-invalid': errorFor(index, 'percentage') }"
                        :value="participant.percentage"
                        :disabled="disabled"
                        :aria-label="t.split_percentage"
                        data-test="split-percentage"
                        @input="update(index, { percentage: $event.target.value })"
                    />
                    <span class="input-group-text" aria-hidden="true">%</span>
                </div>

                <button
                    type="button"
                    class="btn btn-link btn-sm text-danger px-1"
                    :disabled="disabled"
                    :title="t.split_remove"
                    :aria-label="t.split_remove"
                    data-test="split-remove"
                    @click="remove(index)"
                >
                    <i class="ti ti-trash" aria-hidden="true"></i>
                </button>
            </li>
        </ul>

        <div class="d-flex flex-wrap align-items-center gap-3">
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                :disabled="disabled"
                data-test="split-add"
                @click="add"
            >
                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.split_add }}
            </button>
            <span
                v-if="modelValue.length"
                class="small fw-medium"
                :class="sumOk ? 'text-success' : 'text-danger'"
                role="status"
                data-test="split-sum"
                >{{ tx('split_sum', { value: quantity(sum, 2) }) }}</span
            >
        </div>

        <div v-if="errors.participants" class="invalid-feedback d-block" data-test="split-error">
            {{ errors.participants }}
        </div>
    </fieldset>
</template>

<style scoped>
.split-editor__role {
    width: 13rem;
}

.split-editor__doctor {
    min-width: 12rem;
    flex: 1 1 12rem;
}

.split-editor__percentage {
    width: 8rem;
}

@media (max-width: 575.98px) {
    .split-editor__role,
    .split-editor__percentage {
        width: 100%;
    }
}
</style>
