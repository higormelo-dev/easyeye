<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    filters:     { type: Object, required: true },
    summary:     { type: Object, default: () => ({}) },
    byCategory:  { type: Array,  default: () => [] },
    byDay:       { type: Array,  default: () => [] },
    entries:     { type: Array,  default: () => [] },
    export_url:  { type: String, default: '' },
    t:           { type: Object, default: () => ({}) },
});

const from = ref(props.filters.from);
const to   = ref(props.filters.to);

function brl(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }); }

function applyFilter() {
    router.get(route('panel.financial.reports.cash-flow'), { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Relatório Fluxo de Caixa" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Relatório de Fluxo de Caixa">
                <template #actions>
                    <a :href="`${export_url}?from=${filters.from}&to=${filters.to}`" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-download me-1"></i>Exportar CSV
                    </a>
                </template>
            </PageHeader>

            <!-- Filtro -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <form @submit.prevent="applyFilter" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">De</label>
                            <input v-model="from" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Até</label>
                            <input v-model="to" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="ti ti-filter me-1"></i>Filtrar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- KPIs -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Receitas</small>
                            <div class="fw-bold fs-5 text-success">{{ brl(summary.revenue) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Despesas</small>
                            <div class="fw-bold fs-5 text-danger">{{ brl(summary.expenses) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Saldo</small>
                            <div class="fw-bold fs-5" :class="(summary.balance ?? 0) >= 0 ? 'text-success' : 'text-danger'">
                                {{ brl(summary.balance) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">A receber</small>
                            <div class="fw-bold fs-5 text-warning">{{ brl(summary.pending) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Por categoria -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-transparent">
                            <h6 class="mb-0 fw-semibold"><i class="ti ti-tag me-1 text-primary"></i>Por categoria</h6>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Categoria</th>
                                        <th>Tipo</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, i) in byCategory" :key="i">
                                        <td class="fw-medium">{{ row.category }}</td>
                                        <td>
                                            <span :class="row.type === 'income' ? 'text-success' : 'text-danger'">
                                                {{ row.type === 'income' ? 'Receita' : 'Despesa' }}
                                            </span>
                                        </td>
                                        <td class="text-end">{{ brl(row.total) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Por dia -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-transparent">
                            <h6 class="mb-0 fw-semibold"><i class="ti ti-calendar me-1 text-primary"></i>Por dia</h6>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Dia</th>
                                        <th class="text-end">Receita</th>
                                        <th class="text-end">Despesa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, i) in byDay" :key="i">
                                        <td class="fw-medium">{{ row.day }}</td>
                                        <td class="text-end text-success">{{ brl(row.income) }}</td>
                                        <td class="text-end text-danger">{{ brl(row.expense) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lançamentos -->
            <div class="card">
                <div class="card-header bg-transparent">
                    <h6 class="mb-0 fw-semibold"><i class="ti ti-list me-1 text-primary"></i>Lançamentos</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Descrição</th>
                                <th>Categoria</th>
                                <th>Convênio</th>
                                <th class="text-center">Tipo</th>
                                <th class="text-end">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="entries.length === 0">
                                <td colspan="6" class="text-center text-muted py-5">Nenhum lançamento.</td>
                            </tr>
                            <tr v-for="(e, i) in entries" :key="i">
                                <td class="text-muted small">{{ e.entry_date }}</td>
                                <td>{{ e.description }}</td>
                                <td class="text-muted small">{{ e.category_name || '—' }}</td>
                                <td class="text-muted small">{{ e.covenant_name || '—' }}</td>
                                <td class="text-center">
                                    <span :class="`badge ${e.type === 'income' ? 'badge-soft-success text-success' : 'badge-soft-danger text-danger'} fs-11`">
                                        {{ e.type === 'income' ? 'R' : 'D' }}
                                    </span>
                                </td>
                                <td class="text-end fw-bold" :class="e.type === 'income' ? 'text-success' : 'text-danger'">
                                    {{ brl(e.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
