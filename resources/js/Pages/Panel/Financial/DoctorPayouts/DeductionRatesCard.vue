<script setup>
import { computed, useId } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { DEDUCTION_KINDS, useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Deduções antes de dividir (E4): cartão débito/crédito (só a parte paga com
 * cartão no balcão), imposto e taxa administrativa, cada uma com vigência —
 * vale a de maior "a partir de" ≤ data do recebimento. Corrigir = excluir a
 * vigência errada e cadastrar outra; fechamentos já feitos guardam o retrato.
 */
const props = defineProps({
    rates: { type: Array, default: () => [] }, // [{ id, kind, percentage, valid_from, notes }]
    routes: { type: Object, required: true }, // { deduction_rate_store, deduction_rate_destroy }
    today: { type: String, default: '' }, // Y-m-d; vazio = data local do navegador
    t: { type: Object, default: () => ({}) },
});

const { quantity, date, deductionKindLabel } = useDoctorPayoutFormat(() => props.t);

const uid = useId();
const ids = {
    title: `dp-deductions-title-${uid}`,
    kind: `dp-deduction-kind-${uid}`,
    percentage: `dp-deduction-percentage-${uid}`,
    validFrom: `dp-deduction-from-${uid}`,
    errors: `dp-deduction-errors-${uid}`,
};

const form = useForm({ kind: 'tax', percentage: '', valid_from: '', notes: '' });

function localToday() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Vigências por tipo (mais recente primeiro) e a que vale hoje. */
const groups = computed(() => {
    const today = props.today || localToday();

    return DEDUCTION_KINDS.map((kind) => {
        const rates = props.rates
            .filter((rate) => rate.kind === kind)
            .sort((a, b) => b.valid_from.localeCompare(a.valid_from));
        const current = rates.find((rate) => rate.valid_from <= today);

        return { kind, rates, currentId: current?.id ?? null };
    }).filter((group) => group.rates.length > 0);
});

const formError = computed(
    () => form.errors.valid_from || form.errors.percentage || form.errors.kind || form.errors.notes || '',
);

function submit() {
    if (form.processing) return;

    form.post(props.routes.deduction_rate_store, {
        preserveScroll: true,
        onSuccess: () => form.reset('percentage', 'valid_from', 'notes'),
    });
}

function remove(rate) {
    if (!window.confirm(`${props.t.deduction_delete_title}\n\n${props.t.deduction_delete_hint}`)) return;

    router.delete(props.routes.deduction_rate_destroy.replace('__ID__', rate.id), { preserveScroll: true });
}
</script>

<template>
    <section class="card mb-3" :aria-labelledby="ids.title" data-test="deduction-rates">
        <div class="card-body">
            <h2 :id="ids.title" class="h6 fw-bold mb-1">
                <i class="ti ti-receipt-tax me-1 text-primary" aria-hidden="true"></i>{{ t.deductions_title }}
            </h2>
            <p class="small text-muted">{{ t.deductions_intro }}</p>

            <p v-if="groups.length === 0" class="small text-muted mb-3" data-test="deductions-empty">
                {{ t.deductions_empty }}
            </p>

            <ul v-else class="list-unstyled d-grid gap-2 mb-3">
                <li v-for="group in groups" :key="group.kind" data-test="deduction-group" :data-kind="group.kind">
                    <div class="small fw-medium">{{ deductionKindLabel(group.kind) }}</div>
                    <ul class="list-unstyled small mb-0">
                        <li
                            v-for="rate in group.rates"
                            :key="rate.id"
                            class="d-flex flex-wrap align-items-center gap-2"
                            :class="{ 'text-muted': rate.id !== group.currentId }"
                            data-test="deduction-rate"
                        >
                            <span class="deduction-rates__value">{{ quantity(rate.percentage, 2) }}%</span>
                            <span>{{ t.deduction_valid_from }} {{ date(rate.valid_from) }}</span>
                            <span
                                v-if="rate.id === group.currentId"
                                class="badge badge-soft-success border border-success fs-11"
                                data-test="deduction-current"
                            >
                                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ t.active }}
                            </span>
                            <span v-if="rate.notes" class="text-muted text-break">· {{ rate.notes }}</span>
                            <button
                                type="button"
                                class="btn btn-link btn-sm text-danger p-0"
                                :title="t.deduction_delete"
                                :aria-label="`${t.deduction_delete}: ${deductionKindLabel(group.kind)} ${quantity(rate.percentage, 2)}% ${date(rate.valid_from)}`"
                                data-test="deduction-delete"
                                @click="remove(rate)"
                            >
                                <i class="ti ti-trash" aria-hidden="true"></i>
                            </button>
                        </li>
                    </ul>
                </li>
            </ul>

            <form class="d-flex flex-wrap align-items-end gap-2" data-test="deduction-form" @submit.prevent="submit">
                <div class="deduction-rates__field">
                    <label :for="ids.kind" class="form-label small mb-1">{{ t.deduction_kind }}</label>
                    <select
                        :id="ids.kind"
                        v-model="form.kind"
                        class="form-select form-select-sm"
                        :class="{ 'is-invalid': form.errors.kind }"
                        :disabled="form.processing"
                        data-test="deduction-kind"
                    >
                        <option v-for="kind in DEDUCTION_KINDS" :key="kind" :value="kind">
                            {{ deductionKindLabel(kind) }}
                        </option>
                    </select>
                </div>
                <div class="deduction-rates__percentage">
                    <label :for="ids.percentage" class="form-label small mb-1">{{ t.deduction_percentage }}</label>
                    <input
                        :id="ids.percentage"
                        v-model="form.percentage"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        inputmode="decimal"
                        class="form-control form-control-sm text-end"
                        :class="{ 'is-invalid': form.errors.percentage }"
                        :aria-invalid="form.errors.percentage ? 'true' : undefined"
                        :aria-describedby="formError ? ids.errors : undefined"
                        :disabled="form.processing"
                        required
                        data-test="deduction-percentage"
                    />
                </div>
                <div class="deduction-rates__field">
                    <label :for="ids.validFrom" class="form-label small mb-1">{{ t.deduction_valid_from }}</label>
                    <input
                        :id="ids.validFrom"
                        v-model="form.valid_from"
                        type="date"
                        class="form-control form-control-sm"
                        :class="{ 'is-invalid': form.errors.valid_from }"
                        :aria-invalid="form.errors.valid_from ? 'true' : undefined"
                        :aria-describedby="formError ? ids.errors : undefined"
                        :disabled="form.processing"
                        required
                        data-test="deduction-from"
                    />
                </div>
                <button
                    type="submit"
                    class="btn btn-outline-primary btn-sm"
                    :disabled="form.processing"
                    data-test="deduction-add"
                >
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.deduction_add }}
                </button>
                <div
                    v-if="formError"
                    :id="ids.errors"
                    class="w-100 small text-danger"
                    role="alert"
                    data-test="deduction-errors"
                >
                    {{ formError }}
                </div>
            </form>
        </div>
    </section>
</template>

<style scoped>
.deduction-rates__value {
    font-variant-numeric: tabular-nums;
    min-width: 4rem;
}

.deduction-rates__field {
    min-width: 11rem;
}

.deduction-rates__percentage {
    width: 7rem;
}

@media (max-width: 575.98px) {
    .deduction-rates__field,
    .deduction-rates__percentage {
        flex: 1 1 100%;
        width: auto;
        min-width: 0;
    }
}
</style>
