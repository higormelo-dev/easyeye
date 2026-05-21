<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout    from '@/Layouts/PortalLayout.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';

defineProps({
    commissions: { type: Object, required: true },
    totals:      { type: Object, required: true },
});
</script>

<template>
    <Head title="Minhas Comissões — Portal de Parceiros" />

    <PortalLayout>
        <h4 class="fw-bold mb-3">
            <i class="ti ti-coin me-1 text-warning"></i>Minhas Comissões
        </h4>

        <!-- Totais -->
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card shadow-sm border-0 h-100 border-start border-warning border-3">
                    <div class="card-body py-3">
                        <small class="text-muted d-block">Total pendente</small>
                        <div class="fw-bold fs-3 text-warning">{{ totals.pending_fmt }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm border-0 h-100 border-start border-success border-3">
                    <div class="card-body py-3">
                        <small class="text-muted d-block">Total pago</small>
                        <div class="fw-bold fs-3 text-success">{{ totals.paid_fmt }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabela -->
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Criada em</th>
                            <th>Clínica</th>
                            <th>Período</th>
                            <th class="text-end">Taxa</th>
                            <th class="text-end">Valor</th>
                            <th class="text-center">Status</th>
                            <th>Vencimento</th>
                            <th>Pago em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="commissions.data.length === 0">
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="ti ti-receipt-off fs-1 d-block mb-2 opacity-25"></i>
                                Nenhuma comissão registrada ainda.
                            </td>
                        </tr>
                        <tr v-for="c in commissions.data" :key="c.id">
                            <td class="small text-muted">{{ c.created_at }}</td>
                            <td class="fw-medium">{{ c.entity_name }}</td>
                            <td class="small text-muted">{{ c.period || '—' }}</td>
                            <td class="text-end small text-muted">{{ c.rate ? `${c.rate}%` : '—' }}</td>
                            <td class="text-end fw-bold">{{ c.amount_fmt }}</td>
                            <td class="text-center">
                                <span :class="`badge ${c.status_badge}`">{{ c.status_label }}</span>
                            </td>
                            <td class="small text-muted">{{ c.due_at || '—' }}</td>
                            <td class="small">
                                <span v-if="c.paid_at" class="text-success">{{ c.paid_at }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <TablePagination :data="commissions" class="mt-3" />
    </PortalLayout>
</template>
