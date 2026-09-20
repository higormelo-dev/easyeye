<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
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

/* ───────────────────────── Recurso de glosa ───────────────────────── */
const appealOpen = ref(false);
const appealItem = ref(null);

const appealForm = useForm({ reason: '' });

function openAppeal(g) {
    appealForm.reset();
    appealForm.clearErrors();
    appealItem.value = g;
    appealOpen.value = true;
}

function submitAppeal() {
    if (appealForm.reason.trim().length < 10) {
        appealForm.setError('reason', props.t.glosas?.reason_required);
        return;
    }

    appealForm.post(appealItem.value.appeal_url, {
        preserveScroll: true,
        onSuccess: () => { appealOpen.value = false; },
    });
}

/* ───────────────── Enviar recurso (Aberto → Enviado) ───────────────── */
function activeAppeal(g) {
    return g.appeals?.find((a) => a.can_be_submitted || a.can_be_resolved) ?? null;
}

function submitAppealToOperator(appeal) {
    if (!window.confirm(props.t.glosas?.submit_appeal_btn + '?')) return;
    router.post(appeal.submit_url, {}, { preserveScroll: true });
}

/* ───────────────── Decisão do recurso (Enviado → Aceito/Rejeitado) ───────────────── */
const resolveOpen = ref(false);
const resolveItem = ref(null);

const resolveForm = useForm({
    decision: '',
    accepted_amount: '',
    result_notes: '',
});

function openResolve(appeal) {
    resolveForm.reset();
    resolveForm.clearErrors();
    resolveItem.value = appeal;
    resolveOpen.value = true;
}

function submitResolve() {
    resolveForm.post(resolveItem.value.resolve_url, {
        preserveScroll: true,
        onSuccess: () => { resolveOpen.value = false; },
    });
}
</script>

<template>
    <AppLayout :title="t.glosas?.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.glosas?.title" />

            <!-- Filtro -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <form class="row g-2 align-items-end" @submit.prevent="applyFilter">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">{{ t.period_from }}</label>
                            <input v-model="from" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">{{ t.period_to }}</label>
                            <input v-model="to" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="ti ti-filter me-1"></i>{{ t.filter }}
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
                            <small class="text-muted d-block">{{ t.glosas?.total_glosa }}</small>
                            <div class="fw-bold fs-5">{{ brl(summary.total) }}</div>
                            <small class="text-muted">{{ summary.count }} {{ t.glosas?.glosa_count }}</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-warning border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">{{ t.glosas?.open_amount }}</small>
                            <div class="fw-bold fs-5 text-warning">{{ brl(summary.open) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-primary border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">{{ t.glosas?.appealed }}</small>
                            <div class="fw-bold fs-5 text-primary">{{ brl(summary.appealed) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-success border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">{{ t.glosas?.recovered }}</small>
                            <div class="fw-bold fs-5 text-success">{{ brl(summary.recovered) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div
                        class="card border-0 shadow-sm border-start border-3 h-100"
                        :class="summary.due_soon_count > 0 ? 'border-danger' : 'border-secondary-subtle'"
                    >
                        <div class="card-body py-3">
                            <small class="text-muted d-block">
                                {{ t.glosas?.due_soon_title?.replace(':days', summary.due_soon_days) }}
                            </small>
                            <div class="fw-bold fs-5" :class="summary.due_soon_count > 0 ? 'text-danger' : ''">
                                {{ brl(summary.due_soon) }}
                            </div>
                            <small class="text-muted">{{ summary.due_soon_count }} {{ t.glosas?.due_soon_count_suffix }}</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Por operadora -->
            <div v-if="byOperator.length > 0" class="card mb-3">
                <div class="card-header bg-transparent border-bottom">
                    <h6 class="mb-0 fw-semibold"><i class="ti ti-chart-pie me-1 text-primary"></i>{{ t.glosas?.by_covenant }}</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.glosas?.col_covenant }}</th>
                                <th class="text-center">{{ t.glosas?.col_count }}</th>
                                <th class="text-end">{{ t.glosas?.col_total }}</th>
                                <th class="text-end">{{ t.glosas?.col_open }}</th>
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
                    <h6 class="mb-0 fw-semibold"><i class="ti ti-gavel me-1 text-primary"></i>{{ t.glosas?.period_glosas }}</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.glosas?.col_date }}</th>
                                <th>{{ t.glosas?.col_covenant }}</th>
                                <th>{{ t.glosas?.col_guide }}</th>
                                <th>{{ t.glosas?.col_reason }}</th>
                                <th class="text-center">{{ t.glosas?.col_status }}</th>
                                <th class="text-end">{{ t.glosas?.col_value }}</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="glosas.length === 0">
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="ti ti-checks fs-1 d-block mb-2"></i>
                                    {{ t.glosas?.empty }}
                                </td>
                            </tr>
                            <tr v-for="g in glosas" :key="g.id">
                                <td class="text-muted small">{{ g.identified_at }}</td>
                                <td>{{ g.operator_name || t.glosas?.no_covenant }}</td>
                                <td><code class="small">{{ g.guide_number || '—' }}</code></td>
                                <td class="small">
                                    <span class="badge badge-soft-secondary me-1">{{ g.reason_code }}</span>
                                    {{ g.reason_text }}
                                </td>
                                <td class="text-center">
                                    <span :class="`badge ${statusBadge(g.status)} fs-11`">{{ g.status_label }}</span>
                                    <span v-if="g.appeals_count > 0" class="badge badge-soft-info ms-1 fs-11">
                                        {{ g.appeals_count }} {{ t.glosas?.appeals_suffix }}
                                    </span>
                                </td>
                                <td class="text-end fw-bold">{{ brl(g.amount) }}</td>
                                <td class="text-end">
                                    <button
                                        v-if="g.is_actionable"
                                        class="btn btn-sm btn-outline-warning"
                                        :title="t.glosas?.appeal_btn"
                                        @click="openAppeal(g)"
                                    >
                                        <i class="ti ti-message-circle-up me-1"></i>{{ t.glosas?.appeal_btn }}
                                    </button>
                                    <button
                                        v-if="activeAppeal(g)?.can_be_submitted"
                                        class="btn btn-sm btn-outline-info"
                                        :title="t.glosas?.submit_appeal_btn"
                                        @click="submitAppealToOperator(activeAppeal(g))"
                                    >
                                        <i class="ti ti-send me-1"></i>{{ t.glosas?.submit_appeal_btn }}
                                    </button>
                                    <button
                                        v-if="activeAppeal(g)?.can_be_resolved"
                                        class="btn btn-sm btn-outline-primary"
                                        :title="t.glosas?.resolve_appeal_btn"
                                        @click="openResolve(activeAppeal(g))"
                                    >
                                        <i class="ti ti-gavel me-1"></i>{{ t.glosas?.resolve_appeal_btn }}
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
                                {{ t.glosas?.appeal_title }}
                            </h5>
                            <button type="button" class="btn-close" @click="appealOpen = false"></button>
                        </div>
                        <form @submit.prevent="submitAppeal">
                            <div class="modal-body">
                                <div class="alert alert-warning small mb-3">
                                    <strong>{{ t.glosas?.modal_glosa_label }}</strong> {{ appealItem?.reason_text }}
                                    <br><strong>{{ t.glosas?.modal_value_label }}</strong> {{ brl(appealItem?.amount) }}
                                </div>
                                <label class="form-label">
                                    {{ t.glosas?.justification_label }} <span class="text-danger">*</span>
                                </label>
                                <textarea
                                    v-model="appealForm.reason"
                                    rows="4"
                                    maxlength="1000"
                                    class="form-control"
                                    :class="{ 'is-invalid': appealForm.errors.reason }"
                                    :placeholder="t.glosas?.justification_placeholder"
                                ></textarea>
                                <div v-if="appealForm.errors.reason" class="invalid-feedback d-block">
                                    {{ appealForm.errors.reason }}
                                </div>
                                <small class="text-muted">{{ t.glosas?.min_chars_audit_hint }}</small>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="appealOpen = false">
                                    {{ t.glosas?.cancel_btn }}
                                </button>
                                <button type="submit" class="btn btn-warning btn-sm" :disabled="appealForm.processing">
                                    <span v-if="appealForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ti ti-send me-1"></i>
                                    {{ t.glosas?.submit_appeal }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Modal de decisão do recurso -->
            <div
                v-if="resolveOpen"
                class="modal d-block"
                tabindex="-1"
                style="background:rgba(0,0,0,.45);"
                @click.self="resolveOpen = false"
            >
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="ti ti-gavel me-1 text-primary"></i>
                                {{ t.glosas?.resolve_title }}
                            </h5>
                            <button type="button" class="btn-close" @click="resolveOpen = false"></button>
                        </div>
                        <form @submit.prevent="submitResolve">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">{{ t.glosas?.decision_label }} <span class="text-danger">*</span></label>
                                    <select
                                        v-model="resolveForm.decision"
                                        class="form-select"
                                        :class="{ 'is-invalid': resolveForm.errors.decision }"
                                    >
                                        <option value="">{{ t.glosas?.decision_select }}</option>
                                        <option value="accepted">{{ t.glosas?.decision_accepted }}</option>
                                        <option value="rejected">{{ t.glosas?.decision_rejected }}</option>
                                    </select>
                                    <div v-if="resolveForm.errors.decision" class="invalid-feedback">{{ resolveForm.errors.decision }}</div>
                                </div>
                                <div v-if="resolveForm.decision === 'accepted'" class="mb-3">
                                    <label class="form-label">{{ t.glosas?.accepted_amount_label }}</label>
                                    <input
                                        v-model.number="resolveForm.accepted_amount"
                                        type="number" step="0.01" min="0"
                                        class="form-control"
                                        :class="{ 'is-invalid': resolveForm.errors.accepted_amount }"
                                    >
                                    <div v-if="resolveForm.errors.accepted_amount" class="invalid-feedback">{{ resolveForm.errors.accepted_amount }}</div>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">{{ t.glosas?.result_notes_label }}</label>
                                    <textarea
                                        v-model="resolveForm.result_notes"
                                        rows="3" maxlength="1000"
                                        class="form-control"
                                        :class="{ 'is-invalid': resolveForm.errors.result_notes }"
                                    ></textarea>
                                    <div v-if="resolveForm.errors.result_notes" class="invalid-feedback d-block">{{ resolveForm.errors.result_notes }}</div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="resolveOpen = false">
                                    {{ t.glosas?.cancel_btn }}
                                </button>
                                <button type="submit" class="btn btn-primary btn-sm" :disabled="resolveForm.processing">
                                    <span v-if="resolveForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ti ti-check me-1"></i>
                                    {{ t.glosas?.resolve_submit_btn }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
