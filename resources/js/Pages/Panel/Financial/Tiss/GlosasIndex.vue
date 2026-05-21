<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    filters:     { type: Object, required: true },
    summary:     { type: Object, required: true },
    glosas:      { type: Array,  default: () => [] },
    byOperator:  { type: Array,  default: () => [] },
    t:           { type: Object, default: () => ({}) },
});

const from = ref(props.filters.from);
const to   = ref(props.filters.to);

function brl(v) {
    return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}

function applyFilter() {
    router.get(route('panel.financial.tiss.glosas.index'), { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true });
}

const statusBadge = (s) => {
    if (s === 'reversed')         return 'bg-success';
    if (s === 'partial_reversed') return 'bg-info text-dark';
    if (s === 'appealed')         return 'bg-warning text-dark';
    if (s === 'rejected')         return 'bg-danger';
    if (s === 'open')             return 'bg-secondary';
    return 'bg-light text-dark';
};

// Recurso modal
const appealOpen = ref(false);
const appealItem = ref(null);
const appealReason = ref('');
const appealSaving = ref(false);

function openAppeal(g) {
    appealItem.value = g;
    appealReason.value = '';
    appealOpen.value = true;
}

async function submitAppeal() {
    if (appealReason.value.trim().length < 10) {
        if (window.showErrorToast) window.showErrorToast('Justifique o recurso (mínimo 10 caracteres).');
        return;
    }
    appealSaving.value = true;
    try {
        const res = await fetch(appealItem.value.appeal_url, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ reason: appealReason.value }),
        });
        if (res.ok || res.status === 302) {
            if (window.showSuccessToast) window.showSuccessToast('Recurso aberto.');
            appealOpen.value = false;
            router.reload({ only: ['glosas', 'summary', 'byOperator'] });
        } else if (window.showErrorToast) {
            window.showErrorToast('Erro ao abrir recurso.');
        }
    } finally {
        appealSaving.value = false;
    }
}
</script>

<template>
    <AppLayout title="Glosas TISS" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Conciliação de Glosas TISS" />

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

            <!-- Summary cards -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-info border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Total glosado</small>
                            <div class="fw-bold fs-5">{{ brl(summary.total) }}</div>
                            <small class="text-muted">{{ summary.count }} glosas</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-warning border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Em aberto</small>
                            <div class="fw-bold fs-5 text-warning">{{ brl(summary.open) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-primary border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Recorrida</small>
                            <div class="fw-bold fs-5 text-primary">{{ brl(summary.appealed) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-success border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Recuperada</small>
                            <div class="fw-bold fs-5 text-success">{{ brl(summary.recovered) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Por operadora -->
            <div v-if="byOperator.length > 0" class="card mb-3">
                <div class="card-header bg-transparent border-bottom">
                    <h6 class="mb-0 fw-semibold"><i class="ti ti-chart-pie me-1 text-primary"></i>Por operadora</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Operadora</th>
                                <th class="text-center">Qtd</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Em aberto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(op, i) in byOperator" :key="i">
                                <td class="fw-medium">{{ op.name }}</td>
                                <td class="text-center">{{ op.count }}</td>
                                <td class="text-end">{{ brl(op.total) }}</td>
                                <td class="text-end text-warning fw-semibold">{{ brl(op.open) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Lista de glosas -->
            <div class="card">
                <div class="card-header bg-transparent border-bottom">
                    <h6 class="mb-0 fw-semibold"><i class="ti ti-gavel me-1 text-primary"></i>Glosas</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Operadora</th>
                                <th>Guia</th>
                                <th>Motivo</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Valor</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="glosas.length === 0">
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="ti ti-checks fs-1 d-block mb-2"></i>
                                    Nenhuma glosa no período.
                                </td>
                            </tr>
                            <tr v-for="g in glosas" :key="g.id">
                                <td class="text-muted small">{{ g.identified_at }}</td>
                                <td>{{ g.operator_name || '—' }}</td>
                                <td><code class="small">{{ g.guide_number || '—' }}</code></td>
                                <td class="small">
                                    <span class="badge badge-soft-secondary me-1">{{ g.reason_code }}</span>
                                    {{ g.reason_text }}
                                </td>
                                <td class="text-center">
                                    <span :class="`badge ${statusBadge(g.status)} fs-11`">{{ g.status_label }}</span>
                                    <span v-if="g.appeals_count > 0" class="badge badge-soft-info ms-1 fs-11">
                                        {{ g.appeals_count }} recursos
                                    </span>
                                </td>
                                <td class="text-end fw-bold">{{ brl(g.amount) }}</td>
                                <td class="text-end">
                                    <button
                                        v-if="g.is_actionable"
                                        class="btn btn-sm btn-outline-warning"
                                        title="Recorrer"
                                        @click="openAppeal(g)"
                                    >
                                        <i class="ti ti-message-circle-up me-1"></i>Recorrer
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal de recurso -->
            <div
                v-if="appealOpen"
                class="modal d-block"
                tabindex="-1"
                style="background:rgba(0,0,0,.45);"
                @click.self="appealOpen = false"
            >
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="ti ti-message-circle-up me-1 text-warning"></i>
                                Abrir recurso de glosa
                            </h5>
                            <button type="button" class="btn-close" @click="appealOpen = false"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning small mb-3">
                                <strong>Glosa:</strong> {{ appealItem?.reason_text }}
                                <br><strong>Valor:</strong> {{ brl(appealItem?.amount) }}
                            </div>
                            <label class="form-label">Justificativa do recurso <span class="text-danger">*</span></label>
                            <textarea v-model="appealReason" rows="4" class="form-control" maxlength="1000"
                                      placeholder="Argumente por que a glosa deve ser revertida..."></textarea>
                            <small class="text-muted">Mínimo 10 caracteres. Será registrado no audit log.</small>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="appealOpen = false">
                                Cancelar
                            </button>
                            <button type="button" class="btn btn-warning btn-sm" :disabled="appealSaving" @click="submitAppeal">
                                <span v-if="appealSaving" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-send me-1"></i>
                                Enviar recurso
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
