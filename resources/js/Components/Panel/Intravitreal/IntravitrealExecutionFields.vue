<script setup>
import { computed } from 'vue';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Campos da EXECUÇÃO de uma injeção intravítrea (Fase 6): lote e validade (obrigatórios — rastreabilidade do
 * medicamento), autorização do convênio POR aplicação, o intervalo que o médico decide até a próxima aplicação (ou o
 * encerramento do curso) e, opcionalmente, o agendamento da próxima. O servidor confere tudo de novo; aqui a tela só
 * guia. O estado é do pai (v-model): emite um objeto novo, nunca muda o que recebe.
 */
const props = defineProps({
    modelValue: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    weeks: { type: Object, default: () => ({ min: 2, max: 16 }) },
    // Lotes de estoque disponíveis para a escolha (opcional): [{ id, lot_number, expiry_date }].
    lots: { type: Array, default: () => [] },
    sequence: { type: Number, default: null },
    errors: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['update:modelValue']);

const tx = makeTx(() => props.t);

const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value });

const ending = computed(() => props.modelValue.end_course !== '');

// Hoje no relógio do usuário (AAAA-MM-DD, igual ao valor do <input type="date">). Não usar toISOString(): é UTC e, à
// noite no Brasil, já é "amanhã".
const today = () => {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

// O servidor trata "aprovada" com a validade vencida como vencida (a autorização tem prazo) e exige a confirmação e a
// justificativa: sem mostrar os campos aqui o médico ficava com um erro que a tela não deixava resolver.
const expired = computed(
    () =>
        props.modelValue.authorization_status === 'approved' &&
        !!props.modelValue.authorization_valid_until &&
        props.modelValue.authorization_valid_until < today(),
);
const needsOverride = computed(
    () =>
        props.modelValue.authorization_required &&
        (props.modelValue.authorization_status !== 'approved' || expired.value),
);
</script>

<template>
    <fieldset class="border rounded p-2 mb-2" data-testid="ivt-execution">
        <legend class="small fw-semibold float-none w-auto px-1 mb-1">
            {{ sequence ? tx('exec.title_n', { n: sequence }) : t.exec.title }}
        </legend>

        <div class="row g-2 mb-2">
            <div v-if="lots.length" class="col-12">
                <label class="form-label small mb-1" for="ivt-stock-lot">{{ t.exec.stock_lot }}</label>
                <select
                    id="ivt-stock-lot"
                    class="form-select form-select-sm"
                    :value="modelValue.stock_lot_id"
                    @change="set('stock_lot_id', $event.target.value)"
                >
                    <option value="">{{ t.exec.stock_lot_none }}</option>
                    <option v-for="lot in lots" :key="lot.id" :value="lot.id">
                        {{ lot.lot_number }}<template v-if="lot.expiry_date"> · {{ lot.expiry_date }}</template>
                    </option>
                </select>
            </div>
            <template v-if="!modelValue.stock_lot_id">
                <div class="col-7">
                    <label class="form-label small mb-1" for="ivt-lot">{{ t.exec.lot }} *</label>
                    <input
                        id="ivt-lot"
                        type="text"
                        class="form-control form-control-sm"
                        maxlength="60"
                        :class="{ 'is-invalid': errors.lot_number }"
                        :value="modelValue.lot_number"
                        @input="set('lot_number', $event.target.value)"
                    />
                    <div v-if="errors.lot_number" class="invalid-feedback d-block">{{ errors.lot_number }}</div>
                </div>
                <div class="col-5">
                    <label class="form-label small mb-1" for="ivt-expiry">{{ t.exec.expiry }} *</label>
                    <input
                        id="ivt-expiry"
                        type="date"
                        class="form-control form-control-sm"
                        :class="{ 'is-invalid': errors.lot_expiry }"
                        :value="modelValue.lot_expiry"
                        @input="set('lot_expiry', $event.target.value)"
                    />
                    <div v-if="errors.lot_expiry" class="invalid-feedback d-block">{{ errors.lot_expiry }}</div>
                </div>
            </template>
        </div>

        <div class="form-check mb-2">
            <input
                id="ivt-auth-required"
                type="checkbox"
                class="form-check-input"
                :checked="modelValue.authorization_required"
                @change="set('authorization_required', $event.target.checked)"
            />
            <label class="form-check-label small" for="ivt-auth-required">{{ t.exec.auth_required }}</label>
        </div>

        <div v-if="modelValue.authorization_required" class="row g-2 mb-2" data-testid="ivt-authorization">
            <div class="col-12 col-sm-4">
                <label class="form-label small mb-1" for="ivt-auth-status">{{ t.exec.auth_status }}</label>
                <select
                    id="ivt-auth-status"
                    class="form-select form-select-sm"
                    :value="modelValue.authorization_status"
                    @change="set('authorization_status', $event.target.value)"
                >
                    <option v-for="status in statuses" :key="status.value" :value="status.value">
                        {{ status.label }}
                    </option>
                </select>
            </div>
            <div class="col-6 col-sm-4">
                <label class="form-label small mb-1" for="ivt-auth-number">{{ t.exec.auth_number }}</label>
                <input
                    id="ivt-auth-number"
                    type="text"
                    class="form-control form-control-sm"
                    maxlength="60"
                    :value="modelValue.authorization_number"
                    @input="set('authorization_number', $event.target.value)"
                />
            </div>
            <div class="col-6 col-sm-4">
                <label class="form-label small mb-1" for="ivt-auth-until">{{ t.exec.auth_until }}</label>
                <input
                    id="ivt-auth-until"
                    type="date"
                    class="form-control form-control-sm"
                    :value="modelValue.authorization_valid_until"
                    @input="set('authorization_valid_until', $event.target.value)"
                />
            </div>
            <div v-if="needsOverride" class="col-12" data-testid="ivt-override">
                <div class="form-check">
                    <input
                        id="ivt-auth-override"
                        type="checkbox"
                        class="form-check-input"
                        :checked="modelValue.authorization_override"
                        @change="set('authorization_override', $event.target.checked)"
                    />
                    <label class="form-check-label small" for="ivt-auth-override">{{ t.exec.auth_override }}</label>
                </div>
                <label class="form-label small mb-1 mt-1" for="ivt-auth-notes">{{ t.exec.auth_notes }}</label>
                <input
                    id="ivt-auth-notes"
                    type="text"
                    class="form-control form-control-sm"
                    maxlength="1000"
                    :class="{ 'is-invalid': errors.authorization_status }"
                    :value="modelValue.authorization_notes"
                    @input="set('authorization_notes', $event.target.value)"
                />
                <div v-if="errors.authorization_status" class="invalid-feedback d-block" role="alert">
                    {{ errors.authorization_status }}
                </div>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-12">
                <span class="form-label small d-block mb-1">{{ t.exec.end }}</span>
                <div class="d-flex flex-wrap gap-3">
                    <div v-for="option in ['', 'completed', 'discontinued']" :key="option" class="form-check">
                        <input
                            :id="`ivt-end-${option || 'none'}`"
                            type="radio"
                            class="form-check-input"
                            name="ivt-end"
                            :value="option"
                            :checked="modelValue.end_course === option"
                            @change="set('end_course', option)"
                        />
                        <label class="form-check-label small" :for="`ivt-end-${option || 'none'}`">{{
                            t.exec[`end_${option || 'none'}`]
                        }}</label>
                    </div>
                </div>
            </div>

            <template v-if="!ending">
                <div class="col-12 col-sm-5">
                    <label class="form-label small mb-1" for="ivt-interval">{{ t.exec.interval }} *</label>
                    <input
                        id="ivt-interval"
                        type="number"
                        class="form-control form-control-sm"
                        :min="weeks.min"
                        :max="weeks.max"
                        :class="{ 'is-invalid': errors.next_interval_weeks }"
                        aria-describedby="ivt-interval-hint"
                        :value="modelValue.next_interval_weeks"
                        @input="
                            set('next_interval_weeks', $event.target.value === '' ? '' : Number($event.target.value))
                        "
                    />
                    <div id="ivt-interval-hint" class="form-text">
                        {{ tx('exec.interval_hint', { min: weeks.min, max: weeks.max }) }}
                    </div>
                    <div v-if="errors.next_interval_weeks" class="invalid-feedback d-block">
                        {{ errors.next_interval_weeks }}
                    </div>
                </div>
                <div class="col-12 col-sm-7">
                    <label class="form-label small mb-1" for="ivt-schedule">{{ t.exec.schedule }}</label>
                    <input
                        id="ivt-schedule"
                        type="datetime-local"
                        class="form-control form-control-sm"
                        :value="modelValue.schedule_at"
                        @input="set('schedule_at', $event.target.value)"
                    />
                    <div class="form-text">{{ t.exec.schedule_hint }}</div>
                </div>
            </template>
            <div v-else class="col-12">
                <label class="form-label small mb-1" for="ivt-end-reason">{{ t.exec.end_reason }} *</label>
                <input
                    id="ivt-end-reason"
                    type="text"
                    class="form-control form-control-sm"
                    maxlength="500"
                    :class="{ 'is-invalid': errors.end_reason }"
                    :value="modelValue.end_reason"
                    @input="set('end_reason', $event.target.value)"
                />
                <div v-if="errors.end_reason" class="invalid-feedback d-block">{{ errors.end_reason }}</div>
            </div>
        </div>
    </fieldset>
</template>
