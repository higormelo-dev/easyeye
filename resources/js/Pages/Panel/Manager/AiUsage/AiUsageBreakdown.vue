<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Ranking de uso de IA (por ação, clínica, usuário ou provedor/modelo):
 * nome + barra de participação no custo + colunas numéricas. Linha clicável
 * vira filtro da tela inteira (drill-down) quando `drillable`.
 */
const props = defineProps({
    title: { type: String, required: true },
    icon: { type: String, default: 'ti ti-list' },
    rows: { type: Array, default: () => [] },
    /** [{ key, label, type: 'number'|'money'|'percent'|'latency'|'tokens', hint?, class? }] */
    columns: { type: Array, default: () => [] },
    nameKey: { type: String, default: 'label' },
    nameLabel: { type: String, default: '' },
    subKey: { type: String, default: null },
    /** Custo total do recorte (base da barra de participação). */
    totalCost: { type: Number, default: 0 },
    /** Quantos grupos existem no total (rankings cortados no topo). */
    total: { type: Number, default: null },
    drillable: { type: Boolean, default: false },
    internalLabel: { type: String, default: '' },
    /** Textos de breakdown.* (showing_top, empty, drill_hint, share). */
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['select']);

const { tx } = useTrans(() => props.t);
const { locale, money, number } = useLocaleFormat();

const truncated = computed(() => props.total !== null && props.total > props.rows.length);

function share(row) {
    return props.totalCost > 0 ? Math.min(100, ((Number(row.cost_brl) || 0) / props.totalCost) * 100) : 0;
}

function format(column, row) {
    const value = row[column.key];

    switch (column.type) {
        case 'money':
            return money(value);
        case 'percent':
            return value === null || value === undefined ? '—' : `${number(value, 1)}%`;
        case 'latency':
            return value === null || value === undefined
                ? '—'
                : new Intl.NumberFormat(locale.value, {
                      style: 'unit',
                      unit: 'second',
                      maximumFractionDigits: 1,
                  }).format(Number(value) / 1000);
        case 'tokens': {
            const compact = new Intl.NumberFormat(locale.value, { notation: 'compact', maximumFractionDigits: 1 });
            return `${compact.format(Number(row.tokens_in) || 0)} / ${compact.format(Number(row.tokens_out) || 0)}`;
        }
        default:
            return number(value);
    }
}
</script>

<template>
    <div class="card h-100 mb-0 au-breakdown">
        <div class="card-header bg-transparent d-flex align-items-center gap-2">
            <i :class="icon" class="text-primary" aria-hidden="true"></i>
            <h2 class="h6 mb-0 fw-semibold">{{ title }}</h2>
        </div>

        <div v-if="!rows.length" class="card-body text-muted small text-center py-4">{{ tx('empty') }}</div>

        <div v-else class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ nameLabel }}</th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="text-end text-nowrap"
                            :class="column.class"
                            :title="column.hint"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="index" data-test="breakdown-row">
                        <td class="au-name">
                            <button
                                v-if="drillable"
                                type="button"
                                class="btn btn-link p-0 text-start text-body fw-medium au-drill"
                                :title="tx('drill_hint', { name: row[nameKey] })"
                                :aria-label="tx('drill_hint', { name: row[nameKey] })"
                                @click="emit('select', row)"
                            >
                                {{ row[nameKey] }}
                            </button>
                            <span v-else class="fw-medium">{{ row[nameKey] }}</span>
                            <span v-if="row.is_internal" class="badge badge-soft-info rounded ms-1 fs-11">{{
                                internalLabel
                            }}</span>
                            <div v-if="subKey && row[subKey]" class="text-muted small text-truncate">
                                {{ row[subKey] }}
                            </div>
                            <div
                                class="progress au-share mt-1"
                                role="img"
                                :aria-label="tx('share', { pct: number(share(row), 1) })"
                                :title="tx('share', { pct: number(share(row), 1) })"
                            >
                                <div class="progress-bar" :style="{ width: `${share(row)}%` }"></div>
                            </div>
                        </td>
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="text-end au-num"
                            :class="[
                                column.class,
                                column.type === 'percent' && Number(row[column.key]) > 0 ? 'text-danger' : '',
                            ]"
                        >
                            {{ format(column, row) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="truncated" class="card-footer bg-transparent text-muted small">
            {{ tx('showing_top', { shown: number(rows.length), total: number(total) }) }}
        </div>
    </div>
</template>

<style scoped>
.au-num {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.au-name {
    min-width: 160px;
    max-width: 280px;
}
.au-drill {
    font-size: inherit;
    text-decoration: none;
}
.au-drill:hover,
.au-drill:focus-visible {
    text-decoration: underline;
}
.au-share {
    height: 4px;
    background-color: var(--bs-secondary-bg);
}
</style>
