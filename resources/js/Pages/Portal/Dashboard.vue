<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    metrics:           { type: Object, required: true },
    recentLeads:       { type: Array,  default: () => [] },
    recentCommissions: { type: Array,  default: () => [] },
});

function brl(v) { return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }); }
</script>

<template>
    <Head title="Dashboard — Portal de Parceiros" />

    <PortalLayout>
        <h4 class="fw-bold mb-3">
            <i class="ti ti-dashboard me-1 text-primary"></i>Dashboard
        </h4>

        <!-- KPIs -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card shadow-sm border-0 h-100 border-start border-primary border-3">
                    <div class="card-body py-3">
                        <small class="text-muted d-block">Leads cadastrados</small>
                        <div class="fw-bold fs-4 text-primary">{{ metrics.total_leads ?? 0 }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm border-0 h-100 border-start border-success border-3">
                    <div class="card-body py-3">
                        <small class="text-muted d-block">Convertidos</small>
                        <div class="fw-bold fs-4 text-success">{{ metrics.converted_leads ?? 0 }}</div>
                        <small v-if="metrics.conversion_rate" class="text-muted">
                            {{ metrics.conversion_rate }}% de conversão
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm border-0 h-100 border-start border-warning border-3">
                    <div class="card-body py-3">
                        <small class="text-muted d-block">Comissões pendentes</small>
                        <div class="fw-bold fs-4 text-warning">{{ brl(metrics.pending_commissions) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm border-0 h-100 border-start border-info border-3">
                    <div class="card-body py-3">
                        <small class="text-muted d-block">Comissões pagas</small>
                        <div class="fw-bold fs-4 text-info">{{ brl(metrics.paid_commissions) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <!-- Leads recentes -->
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-transparent">
                        <h6 class="mb-0 fw-semibold">
                            <i class="ti ti-users me-1 text-primary"></i>Leads recentes
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div v-if="recentLeads.length === 0" class="text-center py-4 text-muted small">
                            Nenhum lead cadastrado ainda.
                        </div>
                        <table v-else class="table table-sm table-hover mb-0">
                            <tbody>
                                <tr v-for="l in recentLeads" :key="l.id">
                                    <td class="fw-medium">{{ l.name }}</td>
                                    <td class="text-muted small">{{ l.city_state || l.email }}</td>
                                    <td class="text-end">
                                        <span :class="`badge ${l.status_badge}`">{{ l.status_label }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Comissões recentes -->
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-transparent">
                        <h6 class="mb-0 fw-semibold">
                            <i class="ti ti-coin me-1 text-warning"></i>Comissões recentes
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div v-if="recentCommissions.length === 0" class="text-center py-4 text-muted small">
                            Nenhuma comissão ainda.
                        </div>
                        <table v-else class="table table-sm table-hover mb-0">
                            <tbody>
                                <tr v-for="c in recentCommissions" :key="c.id">
                                    <td class="fw-medium">{{ c.entity_name }}</td>
                                    <td class="text-muted small">{{ c.created_at }}</td>
                                    <td class="text-end">
                                        <strong>{{ c.amount_fmt }}</strong>
                                        <span :class="`badge ${c.status_badge} ms-1`">{{ c.status_label }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </PortalLayout>
</template>
