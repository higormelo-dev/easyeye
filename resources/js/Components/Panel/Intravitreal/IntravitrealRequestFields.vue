<script setup>
/**
 * Campos do pedido de uma injeção intravítrea (Fase 6): a droga, o esquema e a indicação do curso do olho. Só aparece
 * quando o procedimento escolhido é do tipo intravítreo; o servidor abre (ou reaproveita) o curso do olho. O estado é do
 * pai (v-model): este componente nunca muda o objeto que recebe, emite um novo.
 */
const props = defineProps({
    modelValue: { type: Object, required: true },
    drugs: { type: Array, default: () => [] },
    regimens: { type: Array, default: () => [] },
    eye: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['update:modelValue']);

const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value });
</script>

<template>
    <fieldset class="border rounded p-2 mb-2 recall-form" data-testid="ivt-request">
        <legend class="small fw-semibold float-none w-auto px-1 mb-1">{{ t.request.title }}</legend>
        <p class="small text-muted mb-2">{{ t.request.hint }}</p>
        <p
            v-if="eye !== 'right' && eye !== 'left'"
            class="small text-danger mb-2"
            role="alert"
            data-testid="ivt-eye-required"
        >
            {{ t.request.eye_required }}
        </p>
        <div class="row g-2">
            <div class="col-12 col-sm-6">
                <label class="form-label small mb-1" for="ivt-drug">{{ t.request.drug }} *</label>
                <select
                    id="ivt-drug"
                    class="form-select form-select-sm"
                    :class="{ 'is-invalid': errors.drug_key }"
                    :value="modelValue.drug_key"
                    @change="set('drug_key', $event.target.value)"
                >
                    <option value="" disabled>—</option>
                    <option v-for="drug in drugs" :key="drug.value" :value="drug.value">{{ drug.label }}</option>
                </select>
                <div v-if="errors.drug_key" class="invalid-feedback d-block">{{ errors.drug_key }}</div>
            </div>
            <div v-if="modelValue.drug_key === 'other'" class="col-12 col-sm-6">
                <label class="form-label small mb-1" for="ivt-drug-label">{{ t.request.drug_other }} *</label>
                <input
                    id="ivt-drug-label"
                    type="text"
                    class="form-control form-control-sm"
                    maxlength="120"
                    :value="modelValue.drug_label"
                    @input="set('drug_label', $event.target.value)"
                />
            </div>
            <div class="col-12 col-sm-6">
                <label class="form-label small mb-1" for="ivt-regimen">{{ t.request.regimen }}</label>
                <select
                    id="ivt-regimen"
                    class="form-select form-select-sm"
                    :value="modelValue.regimen"
                    @change="set('regimen', $event.target.value)"
                >
                    <option v-for="regimen in regimens" :key="regimen.value" :value="regimen.value">
                        {{ regimen.label }}
                    </option>
                </select>
            </div>
            <div class="col-12 col-sm-6">
                <label class="form-label small mb-1" for="ivt-indication">{{ t.request.indication }}</label>
                <input
                    id="ivt-indication"
                    type="text"
                    class="form-control form-control-sm"
                    maxlength="120"
                    :value="modelValue.indication"
                    @input="set('indication', $event.target.value)"
                />
            </div>
        </div>
        <p class="form-text mb-0">{{ t.request.regimen_hint }}</p>
    </fieldset>
</template>
