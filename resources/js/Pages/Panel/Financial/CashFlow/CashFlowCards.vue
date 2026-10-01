<script setup>
import CashEntryRowActions from './CashEntryRowActions.vue';
import CashFlowTotals from './CashFlowTotals.vue';
import { useCashEntryFormat } from './useCashEntryFormat.js';

/**
 * Lista em cards do Fluxo de Caixa (abaixo de md): mesmos dados e ações da
 * tabela, sem rolagem horizontal no celular do balcão.
 */
const props = defineProps({
    rows: { type: Array, default: () => [] },
    overview: { type: Object, default: () => ({}) },
    busyId: { type: String, default: null },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['edit', 'delete']);

const { date, typeLabel, statusLabel, originLabel, typeBadge, statusBadge, originIcon, entryAmount } =
    useCashEntryFormat(() => props.t);
</script>

<template>
    <div class="cash-flow-cards">
        <ul class="list-unstyled d-grid gap-2 mb-2" :aria-label="t.table_caption">
            <li
                v-for="entry in rows"
                :key="entry.id"
                class="card"
                :class="{ 'opacity-50': busyId === entry.id }"
                :aria-busy="busyId === entry.id ? 'true' : 'false'"
                :data-test="`card-${entry.id}`"
            >
                <div class="card-body p-3">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div class="cash-flow-card__main">
                            <p class="fw-semibold mb-0 text-break">{{ entry.description }}</p>
                            <p class="small text-muted mb-0">
                                {{ date(entry.entry_date) }}<template v-if="entry.code"> · {{ entry.code }}</template>
                            </p>
                        </div>
                        <span class="fw-bold text-body text-nowrap cash-flow-card__amount" data-test="amount">
                            <i
                                class="ti me-1"
                                :class="
                                    entry.type === 'expense'
                                        ? 'ti-arrow-up-right text-danger'
                                        : 'ti-arrow-down-left text-success'
                                "
                                aria-hidden="true"
                            ></i
                            >{{ entryAmount(entry) }}
                        </span>
                    </div>

                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <span class="badge rounded fs-11 fw-medium" :class="typeBadge(entry.type)">{{
                            typeLabel(entry.type)
                        }}</span>
                        <span class="badge rounded fs-11 fw-medium" :class="statusBadge(entry.status)">{{
                            statusLabel(entry.status)
                        }}</span>
                        <span class="badge rounded badge-soft-secondary border fs-11 fw-medium">
                            <i :class="originIcon(entry.origin)" class="me-1" aria-hidden="true"></i
                            >{{ originLabel(entry.origin) }}
                        </span>
                    </div>

                    <dl class="small mt-2 mb-2 cash-flow-card__details">
                        <div v-if="entry.patient_name" class="d-flex gap-1">
                            <dt class="fw-semibold text-muted">{{ t.col_patient }}:</dt>
                            <dd class="mb-0 text-break">{{ entry.patient_name }}</dd>
                        </div>
                        <div class="d-flex gap-1">
                            <dt class="fw-semibold text-muted">{{ t.col_payment_method }}:</dt>
                            <dd class="mb-0">{{ entry.payment_method_label || '—' }}</dd>
                        </div>
                        <div class="d-flex gap-1">
                            <dt class="fw-semibold text-muted">{{ t.col_category }}:</dt>
                            <dd class="mb-0">{{ entry.category_name || '—' }}</dd>
                        </div>
                    </dl>

                    <div class="d-flex justify-content-end">
                        <CashEntryRowActions
                            :entry="entry"
                            :busy="busyId === entry.id"
                            :t="t"
                            @edit="emit('edit', $event)"
                            @delete="emit('delete', $event)"
                        />
                    </div>
                </div>
            </li>
        </ul>

        <div class="card">
            <div class="card-body py-2">
                <CashFlowTotals :overview="overview" :t="t" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.cash-flow-card__main {
    min-width: 0;
}

.cash-flow-card__amount {
    font-variant-numeric: tabular-nums;
}
</style>
