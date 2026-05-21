<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout    from '@/Layouts/AppLayout.vue';
import PageHeader   from '@/Components/Panel/PageHeader.vue';

/**
 * Management Dashboard (BI). Read-only — backend (ClinicBiService) computa tudo.
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    entity:      { type: Object, required: true },
    filters:     { type: Object, required: true },
    summary:     { type: Object, required: true },
    trend:       { type: Array,  default: () => [] },
    t:           { type: Object, default: () => ({}) },
});

const fromDate = ref(props.filters.from);
const toDate   = ref(props.filters.to);
const k        = computed(() => props.summary?.kpis ?? {});

function applyFilter() {
    router.get(route('panel.financial.bi.index'), { from: fromDate.value, to: toDate.value },
        { preserveState: true, preserveScroll: true });
}
function resetFilter() { router.get(route('panel.financial.bi.index')); }

function brl(value) {
    return 'R$ ' + Number(value ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function pct(value, dec = 1) {
    return Number(value ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '%';
}

const kpis = computed(() => [
    { title: props.t.bi?.revenue          ?? 'Receita',             value: brl(k.value.revenue),         icon: 'ti-trending-up',   color: 'success' },
    { title: props.t.bi?.expenses         ?? 'Despesa',             value: brl(k.value.expenses),        icon: 'ti-trending-down', color: 'danger'  },
    { title: props.t.bi?.balance          ?? 'Saldo',               value: brl(k.value.balance),         icon: 'ti-wallet',        color: (k.value.balance ?? 0) >= 0 ? 'success' : 'danger' },
    { title: props.t.bi?.receipt_rate     ?? 'Taxa de recebimento', value: pct(k.value.receipt_rate),    icon: 'ti-receipt',       color: 'primary' },
    { title: props.t.bi?.attendance_rate  ?? 'Taxa de presença',    value: pct(k.value.attendance_rate), icon: 'ti-user-check',    color: 'primary' },
    { title: props.t.bi?.occupancy_rate   ?? 'Ocupação',            value: pct(k.value.occupancy_rate),  icon: 'ti-calendar-check',color: 'warning' },
]);
</script>

<template>
    <AppLayout :title="t.bi?.title ?? 'Dashboard gerencial'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.bi?.title ?? 'Dashboard gerencial'" :subtitle="entity.name" />

            <!-- Filtro de período -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body py-3">
                    <form @submit.prevent="applyFilter" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">{{ t.period_from ?? 'De' }}</label>
                            <input v-model="fromDate" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">{{ t.period_to ?? 'Até' }}</label>
                            <input v-model="toDate" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-auto ms-auto d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="ti ti-filter me-1"></i>{{ t.filter ?? 'Filtrar' }}
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="resetFilter">
                                <i class="ti ti-refresh me-1"></i>{{ t.current_month ?? 'Mês atual' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- KPIs -->
            <div class="row g-3 mb-3">
                <div v-for="kpi in kpis" :key="kpi.title" class="col-6 col-md-4 col-xl-2">
                    <div :class="`card border-0 shadow-sm h-100 border-start border-3 border-${kpi.color}`">
                        <div class="card-body px-3 py-3">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span :class="`avatar avatar-sm rounded-circle bg-${kpi.color}-subtle`">
                                    <i :class="`ti ${kpi.icon} text-${kpi.color}`"></i>
                                </span>
                                <span class="small text-muted">{{ kpi.title }}</span>
                            </div>
                            <div :class="`fw-bold fs-5 text-${kpi.color}`">{{ kpi.value }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tendência mensal -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-bottom">
                    <h6 class="mb-0 fw-semibold">
                        <i class="ti ti-chart-line me-1 text-primary"></i>
                        {{ t.bi?.monthly_trend ?? 'Tendência mensal' }}
                    </h6>
                </div>
                <div class="card-body">
                    <div v-if="trend.length === 0" class="text-center text-muted py-4">
                        {{ t.bi?.no_trend_data ?? 'Sem dados suficientes.' }}
                    </div>
                    <table v-else class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.bi?.month ?? 'Mês' }}</th>
                                <th class="text-end">{{ t.bi?.revenue ?? 'Receita' }}</th>
                                <th class="text-end">{{ t.bi?.expenses ?? 'Despesa' }}</th>
                                <th class="text-end">{{ t.bi?.balance ?? 'Saldo' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in trend" :key="idx">
                                <td class="fw-medium">{{ row.month }}</td>
                                <td class="text-end text-success">{{ brl(row.revenue) }}</td>
                                <td class="text-end text-danger">{{ brl(row.expenses) }}</td>
                                <td class="text-end fw-semibold" :class="(row.balance ?? 0) >= 0 ? 'text-success' : 'text-danger'">
                                    {{ brl(row.balance) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
