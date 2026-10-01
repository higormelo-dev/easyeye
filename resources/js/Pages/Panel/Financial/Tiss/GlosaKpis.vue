<script setup>
import KpiCard from '@/Components/Panel/KpiCard.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * KPIs da Conciliação: cada card é um botão de filtro (aria-pressed) — Em
 * aberto, Recorridas, Vencidas e Vencendo filtram a fila (qualquer data);
 * Recuperado (período) abre as resolvidas recuperadas. Clicar de novo tira o
 * filtro. Números do servidor (TissGlosasController::summary).
 */
const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    /** open | appealed | overdue | soon | recovered | null */
    active: { type: String, default: null },
    loading: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['toggle']);

const { tx } = useTrans(() => props.t);
const { money } = useLocaleFormat();

function count(value) {
    return tx('glosa_count', { count: Number(value ?? 0) });
}
</script>

<template>
    <section class="mb-3" :aria-label="t.kpis_label" data-test="glosa-kpis">
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-5 g-3">
            <div class="col">
                <KpiCard
                    :label="t.open_amount"
                    :value="money(summary.open ?? 0)"
                    icon="ti ti-alert-circle"
                    tone="danger"
                    :hint="t.open_hint"
                    :subtitle="count(summary.open_count)"
                    :loading="loading"
                    toggle
                    :active="active === 'open'"
                    test-id="open"
                    data-test="kpi-toggle-open"
                    @click="emit('toggle', 'open')"
                />
            </div>
            <div class="col">
                <KpiCard
                    :label="t.appealed"
                    :value="money(summary.appealed ?? 0)"
                    icon="ti ti-message-circle-up"
                    tone="info"
                    :hint="t.appealed_hint"
                    :subtitle="count(summary.appealed_count)"
                    :loading="loading"
                    toggle
                    :active="active === 'appealed'"
                    test-id="appealed"
                    data-test="kpi-toggle-appealed"
                    @click="emit('toggle', 'appealed')"
                />
            </div>
            <div class="col">
                <KpiCard
                    :label="t.overdue_title"
                    :value="money(summary.overdue ?? 0)"
                    icon="ti ti-alert-triangle"
                    tone="danger"
                    :hint="t.overdue_hint"
                    :subtitle="count(summary.overdue_count)"
                    :loading="loading"
                    toggle
                    :active="active === 'overdue'"
                    test-id="overdue"
                    data-test="kpi-toggle-overdue"
                    @click="emit('toggle', 'overdue')"
                />
            </div>
            <div class="col">
                <KpiCard
                    :label="tx('due_soon_title', { days: summary.due_soon_days ?? 5 })"
                    :value="money(summary.due_soon ?? 0)"
                    icon="ti ti-alarm"
                    tone="warning"
                    :hint="tx('due_soon_hint', { days: summary.due_soon_days ?? 5 })"
                    :subtitle="count(summary.due_soon_count)"
                    :loading="loading"
                    toggle
                    :active="active === 'soon'"
                    test-id="due-soon"
                    data-test="kpi-toggle-soon"
                    @click="emit('toggle', 'soon')"
                />
            </div>
            <div class="col">
                <KpiCard
                    :label="t.recovered"
                    :value="money(summary.recovered ?? 0)"
                    icon="ti ti-circle-check"
                    tone="success"
                    :hint="t.recovered_hint"
                    :subtitle="tx('recovered_of_total', { total: money(summary.total ?? 0) })"
                    :loading="loading"
                    toggle
                    :active="active === 'recovered'"
                    test-id="recovered"
                    data-test="kpi-toggle-recovered"
                    @click="emit('toggle', 'recovered')"
                />
            </div>
        </div>
    </section>
</template>
