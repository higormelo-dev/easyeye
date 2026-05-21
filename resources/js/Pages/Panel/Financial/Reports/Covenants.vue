<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    filters:     { type: Object, required: true },
    summary:     { type: Object, default: () => ({}) },
    byCovenant:  { type: Array,  default: () => [] },
    export_url:  { type: String, default: '' },
    t:           { type: Object, default: () => ({}) },
});

const from = ref(props.filters.from);
const to   = ref(props.filters.to);

function brl(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }); }

function applyFilter() {
    router.get(route('panel.financial.reports.covenants'), { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Relatório por Convênio" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Relatório de Faturamento por Convênio">
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
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Guias</small>
                            <div class="fw-bold fs-5">{{ summary.total_claims ?? 0 }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Total faturado</small>
                            <div class="fw-bold fs-5 text-primary">{{ brl(summary.total_amount) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Total pago</small>
                            <div class="fw-bold fs-5 text-success">{{ brl(summary.total_paid) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Glosado</small>
                            <div class="fw-bold fs-5 text-danger">{{ brl(summary.total_denied) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Por convênio -->
            <div class="card">
                <div class="card-header bg-transparent">
                    <h6 class="mb-0 fw-semibold"><i class="ti ti-medical-cross me-1 text-primary"></i>Por convênio</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Convênio</th>
                                <th class="text-center">Guias</th>
                                <th class="text-end">Faturado</th>
                                <th class="text-end">Pago</th>
                                <th class="text-end">Glosado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="byCovenant.length === 0">
                                <td colspan="5" class="text-center text-muted py-5">Sem dados no período.</td>
                            </tr>
                            <tr v-for="(row, i) in byCovenant" :key="i">
                                <td class="fw-medium">{{ row.covenant }}</td>
                                <td class="text-center">{{ row.claims }}</td>
                                <td class="text-end">{{ brl(row.amount) }}</td>
                                <td class="text-end text-success">{{ brl(row.paid) }}</td>
                                <td class="text-end text-danger">{{ brl(row.denied) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
