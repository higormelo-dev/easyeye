<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    balance: { type: Object, required: true },
    creditPackages: { type: Array, default: () => [] },
    recentCreditPurchases: { type: Array, default: () => [] },
    creditPurchaseAutoCredit: { type: Boolean, default: false },
    canPurchaseCredits: { type: Boolean, default: false },
    runs: { type: Object, required: true },
    analytics: { type: Object, default: () => ({}) },
    patients: { type: Array, default: () => [] },
    medicalRecords: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    modes: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    risks: { type: Array, default: () => [] },
    workflows: { type: Array, default: () => [] },
    defaultMode: { type: String, default: 'validated' },
    canConsensus: { type: Boolean, default: false },
    labels: { type: Object, default: () => ({}) },
    prefill: { type: Object, default: () => ({}) },
});

// ── Helpers para a seção analítica ─────────────────────────────────────────
const analytics = computed(() => props.analytics ?? {});
const usagePercent = computed(() => analytics.value?.consumed?.usage_percent ?? null);
const usageBarClass = computed(() => {
    const p = usagePercent.value ?? 0;
    if (p >= 90) return 'bg-danger';
    if (p >= 70) return 'bg-warning';
    return 'bg-success';
});

function workflowDisplayLabel(workflow) {
    return label(`workflow_${workflow}`, workflow);
}
function modeDisplayLabel(mode) {
    return label(`mode_${mode}`, mode);
}

const label = (key, fallback = '') => props.labels?.[key] ?? fallback;

const breadcrumbs = [
    { label: label('dashboard', 'Dashboard'), url: route('panel.dashboard'), active: false },
    { label: label('title', 'AI'), url: '#', active: true },
];

// Form de execução manual de IA removido — agora as execuções são iniciadas
// apenas via contexto clínico (botão "Assistente de IA" no prontuário /
// eye-images). Esta página é puramente para monitoramento, aprovação/rejeição
// e gestão de créditos.

const detailLoading = ref(false);
const selectedRun = ref(null);
const draftOutput = ref('');
const rejectReason = ref('');
const statusFilter = ref(props.filters.status ?? '');
const purchasingPackage = ref('');

// Mensagens de feedback inline (substituem window.alert): exibidas em um alert
// Bootstrap dismissible no topo da página. Auto-clear após 6s para erros e 4s
// para sucesso — assim ações encadeadas (aprovar → recarregar) não acumulam toasts.
const errorMessage = ref('');
const successMessage = ref('');
let messageTimer = null;

function showError(message) {
    errorMessage.value = message;
    successMessage.value = '';
    if (messageTimer) clearTimeout(messageTimer);
    messageTimer = setTimeout(() => (errorMessage.value = ''), 6000);
}

function showSuccess(message) {
    successMessage.value = message;
    errorMessage.value = '';
    if (messageTimer) clearTimeout(messageTimer);
    messageTimer = setTimeout(() => (successMessage.value = ''), 4000);
}

function dismissMessage() {
    errorMessage.value = '';
    successMessage.value = '';
    if (messageTimer) clearTimeout(messageTimer);
}

const canApproveOrReject = computed(() => selectedRun.value?.status === 'waiting_approval');
const availableStatuses = computed(() => props.statuses.length
    ? props.statuses
    : ['pending', 'reserved', 'running', 'waiting_approval', 'approved', 'rejected', 'failed', 'cancelled']);
const statusFilterOptions = computed(() =>
    availableStatuses.value.map((status) => ({ value: status, label: statusLabel(status) })));
const paginationLinks = computed(() => Array.isArray(props.runs?.links) ? props.runs.links : []);

const workflowLabel = (workflow) => {
    const key = `workflow_${workflow}`;
    return label(key, workflow);
};

const modeLabel = (mode) => {
    const key = `mode_${mode}`;
    return label(key, mode);
};

const statusLabel = (status) => {
    const key = `status_${status}`;
    return label(key, status);
};

const statusClass = (status) => {
    return {
        pending: 'badge bg-secondary-subtle text-secondary',
        reserved: 'badge bg-info-subtle text-info',
        running: 'badge bg-primary-subtle text-primary',
        waiting_approval: 'badge bg-warning-subtle text-warning',
        approved: 'badge bg-success-subtle text-success',
        rejected: 'badge bg-danger-subtle text-danger',
        failed: 'badge bg-danger-subtle text-danger',
        cancelled: 'badge bg-dark-subtle text-dark',
    }[status] ?? 'badge bg-light text-dark';
};

const purchaseStatusClass = (status) => {
    return {
        pending_payment: 'badge bg-warning-subtle text-warning',
        credited: 'badge bg-success-subtle text-success',
        cancelled: 'badge bg-secondary-subtle text-secondary',
        failed: 'badge bg-danger-subtle text-danger',
        refunded: 'badge bg-info-subtle text-info',
    }[status] ?? 'badge bg-light text-dark';
};

function filterByStatus() {
    router.get(
        route('panel.ai-runs.index'),
        { status: statusFilter.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function goToPage(url) {
    if (!url) return;

    router.visit(url, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

async function purchaseCredits(packageCode) {
    if (!packageCode || purchasingPackage.value) return;

    purchasingPackage.value = packageCode;
    try {
        const { data } = await window.axios.post(route('panel.ai-credit-purchases.store'), {
            package_code: packageCode,
        });
        router.reload({ only: ['balance', 'recentCreditPurchases'] });
        showSuccess(data?.message ?? label('credit_purchase_pending', 'Compra registrada.'));
    } catch (error) {
        showError(error?.response?.data?.message ?? label('credit_purchase_unavailable', 'Falha ao registrar compra.'));
    } finally {
        purchasingPackage.value = '';
    }
}

async function loadRunDetail(runId) {
    detailLoading.value = true;
    try {
        const { data } = await window.axios.get(route('panel.ai-runs.show', runId));
        selectedRun.value = data.data;
        draftOutput.value = data.data.final_output ?? '';
        rejectReason.value = '';
    } catch (error) {
        showError(error?.response?.data?.message ?? label('error_load_detail', 'Falha ao carregar detalhes.'));
    } finally {
        detailLoading.value = false;
    }
}

async function approveRun() {
    if (!selectedRun.value?.id) return;

    try {
        await window.axios.post(route('panel.ai-runs.approve', selectedRun.value.id), {
            final_output: draftOutput.value,
        });
        await loadRunDetail(selectedRun.value.id);
        router.reload({ only: ['runs'] });
        showSuccess(label('run_approved', 'Execução aprovada.'));
    } catch (error) {
        showError(error?.response?.data?.message ?? label('error_approve', 'Falha ao aprovar execução.'));
    }
}

async function rejectRun() {
    if (!selectedRun.value?.id) return;

    try {
        await window.axios.post(route('panel.ai-runs.reject', selectedRun.value.id), {
            reason: rejectReason.value,
        });
        await loadRunDetail(selectedRun.value.id);
        router.reload({ only: ['runs'] });
        showSuccess(label('run_rejected', 'Execução rejeitada.'));
    } catch (error) {
        showError(error?.response?.data?.message ?? label('error_reject', 'Falha ao rejeitar execução.'));
    }
}
</script>

<template>
    <AppLayout :title="label('title', 'AI')" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="label('title', 'AI')" :total="runs.total" />

            <div
                v-if="errorMessage"
                class="alert alert-danger alert-dismissible fade show"
                role="alert"
            >
                <i class="ti ti-alert-circle me-1"></i>{{ errorMessage }}
                <button type="button" class="btn-close" aria-label="Close" @click="dismissMessage"></button>
            </div>
            <div
                v-if="successMessage"
                class="alert alert-success alert-dismissible fade show"
                role="alert"
            >
                <i class="ti ti-check me-1"></i>{{ successMessage }}
                <button type="button" class="btn-close" aria-label="Close" @click="dismissMessage"></button>
            </div>

            <div class="row g-3 mb-3">
                <div :class="canPurchaseCredits ? 'col-lg-4' : 'col-12'">
                    <div class="border rounded p-3 bg-white h-100">
                        <div class="d-flex flex-column gap-2">
                            <div><strong>{{ label('credits_available', 'Créditos disponíveis') }}:</strong> {{ balance.available }}</div>
                            <div><strong>{{ label('credits_reserved', 'Reservados') }}:</strong> {{ balance.reserved }}</div>
                            <div><strong>{{ label('credits_total', 'Total') }}:</strong> {{ balance.total }}</div>
                        </div>
                        <div class="mt-2 text-muted fs-13">{{ label('support_notice') }}</div>
                    </div>
                </div>

                <div v-if="canPurchaseCredits" class="col-lg-8">
                    <div class="border rounded p-3 bg-white h-100">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <div>
                                <h6 class="fw-semibold mb-1">{{ label('credit_packages_title', 'Pacotes de créditos IA') }}</h6>
                                <div class="text-muted fs-13">{{ label('credit_packages_subtitle', 'Créditos extras avulsos.') }}</div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ label('credits', 'Créditos') }}</th>
                                        <th>{{ label('credit_package', 'Pacote') }}</th>
                                        <th>{{ label('amount', 'Valor') }}</th>
                                        <th class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="pkg in creditPackages" :key="pkg.code">
                                        <td class="fw-semibold">{{ pkg.credits }}</td>
                                        <td>
                                            <div class="fw-medium">{{ pkg.name }}</div>
                                            <div class="text-muted fs-13">{{ pkg.unit_price_formatted }} {{ label('credit_package_unit', 'por crédito') }}</div>
                                        </td>
                                        <td>{{ pkg.price_formatted }}</td>
                                        <td class="text-end">
                                            <button
                                                class="btn btn-sm"
                                                :class="pkg.featured ? 'btn-primary' : 'btn-outline-primary'"
                                                :disabled="!!purchasingPackage"
                                                @click="purchaseCredits(pkg.code)"
                                            >
                                                <i class="ti ti-credit-card me-1"></i>
                                                {{ creditPurchaseAutoCredit ? label('credit_package_buy', 'Comprar agora') : label('credit_package_request', 'Solicitar compra') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3" v-if="recentCreditPurchases.length">
                            <div class="fw-semibold fs-13 mb-1">{{ label('credit_purchase_history', 'Compras recentes') }}</div>
                            <div class="d-flex flex-wrap gap-2">
                                <span
                                    v-for="purchase in recentCreditPurchases"
                                    :key="purchase.id"
                                    class="d-inline-flex align-items-center gap-2 border rounded px-2 py-1 fs-13"
                                >
                                    <span>{{ purchase.credits }} · {{ purchase.amount_formatted }}</span>
                                    <span :class="purchaseStatusClass(purchase.status)">{{ purchase.status_label }}</span>
                                </span>
                            </div>
                        </div>
                        <div v-else class="mt-3 text-muted fs-13">{{ label('credit_purchase_empty', 'Nenhuma compra recente.') }}</div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════ Analítico do mês corrente ═══════════════════ -->
            <div v-if="analytics?.period" class="border rounded p-3 bg-white mb-3">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-semibold mb-0">
                        <i class="ti ti-chart-donut me-1 text-info"></i>
                        Consumo de {{ analytics.period.label }}
                    </h6>
                    <span class="text-muted fs-13">{{ analytics.period.start }} — {{ analytics.period.end }}</span>
                </div>

                <!-- Cota mensal + barra de progresso -->
                <div v-if="analytics.plan_quota > 0" class="mb-3">
                    <div class="d-flex justify-content-between mb-1 fs-13">
                        <span class="fw-semibold">Cota do plano</span>
                        <span class="text-muted">
                            <strong>{{ analytics.consumed?.credits ?? 0 }}</strong>
                            / {{ analytics.plan_quota }} créditos
                            <span v-if="usagePercent !== null" class="ms-1">({{ usagePercent }}%)</span>
                        </span>
                    </div>
                    <div class="progress" style="height: 12px;">
                        <div :class="['progress-bar', usageBarClass]"
                             role="progressbar"
                             :style="{ width: Math.min(usagePercent ?? 0, 100) + '%' }"
                             :aria-valuenow="usagePercent ?? 0"
                             aria-valuemin="0"
                             aria-valuemax="100"></div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Mini-cards de resumo -->
                    <div class="col-md-3">
                        <div class="border rounded p-2 text-center h-100">
                            <div class="text-muted fs-13">Execuções</div>
                            <div class="fs-3 fw-bold text-primary">{{ analytics.consumed?.runs ?? 0 }}</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-2 text-center h-100">
                            <div class="text-muted fs-13">Créditos consumidos</div>
                            <div class="fs-3 fw-bold text-info">{{ analytics.consumed?.credits ?? 0 }}</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-2 text-center h-100">
                            <div class="text-muted fs-13">Aprovados</div>
                            <div class="fs-3 fw-bold text-success">{{ analytics.approval?.approved ?? 0 }}</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-2 text-center h-100">
                            <div class="text-muted fs-13">Taxa de aprovação</div>
                            <div class="fs-3 fw-bold text-warning">
                                <template v-if="analytics.approval?.rate !== null && analytics.approval?.rate !== undefined">
                                    {{ analytics.approval.rate }}%
                                </template>
                                <template v-else>—</template>
                            </div>
                        </div>
                    </div>

                    <!-- Por workflow -->
                    <div class="col-md-6">
                        <h6 class="fw-semibold fs-13 mb-2">Por workflow</h6>
                        <div v-if="analytics.by_workflow?.length === 0" class="text-muted fs-13">Sem execuções no período.</div>
                        <ul v-else class="list-unstyled mb-0">
                            <li v-for="row in analytics.by_workflow" :key="row.workflow"
                                class="d-flex justify-content-between border-bottom py-1 fs-13">
                                <span>{{ workflowDisplayLabel(row.workflow) }}</span>
                                <span class="text-muted">
                                    {{ row.runs_count }} runs · <strong>{{ row.credits_total }}</strong> créditos
                                </span>
                            </li>
                        </ul>
                    </div>

                    <!-- Por modo -->
                    <div class="col-md-6">
                        <h6 class="fw-semibold fs-13 mb-2">Por modo de revisão</h6>
                        <div v-if="analytics.by_mode?.length === 0" class="text-muted fs-13">Sem execuções no período.</div>
                        <ul v-else class="list-unstyled mb-0">
                            <li v-for="row in analytics.by_mode" :key="row.mode"
                                class="d-flex justify-content-between border-bottom py-1 fs-13">
                                <span>{{ modeDisplayLabel(row.mode) }}</span>
                                <span class="text-muted">{{ row.runs_count }} runs</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Top runs por custo -->
                    <div class="col-12" v-if="analytics.top_runs?.length">
                        <h6 class="fw-semibold fs-13 mb-2">Top 5 execuções por custo</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Data</th>
                                        <th>Workflow</th>
                                        <th>Paciente</th>
                                        <th>Solicitante</th>
                                        <th class="text-end">Créditos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="run in analytics.top_runs" :key="run.id">
                                        <td class="fs-13 text-muted">{{ run.created_at }}</td>
                                        <td class="fs-13">{{ workflowDisplayLabel(run.workflow) }}</td>
                                        <td class="fs-13">{{ run.patient ?? '—' }}</td>
                                        <td class="fs-13">{{ run.requested_by ?? '—' }}</td>
                                        <td class="text-end fw-bold">{{ run.consumed_credits }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <!-- ═══════════════════ /Analítico ═══════════════════ -->

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="border rounded p-3 bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-semibold mb-0">{{ label('runs', 'Execuções') }}</h6>
                            <SearchSelect
                                v-model="statusFilter"
                                class="w-auto"
                                :options="statusFilterOptions"
                                :value-key="'value'"
                                :label-key="'label'"
                                :placeholder="label('all_statuses', 'Todos status')"
                                @change="filterByStatus"
                            />
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>{{ label('date', 'Data') }}</th>
                                        <th>{{ label('workflow', 'Workflow') }}</th>
                                        <th>{{ label('mode', 'Modo') }}</th>
                                        <th>{{ label('status', 'Status') }}</th>
                                        <th>{{ label('credits', 'Créditos') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="run in runs.data" :key="run.id">
                                        <td>{{ run.created_at }}</td>
                                        <td>{{ workflowLabel(run.workflow) }}</td>
                                        <td>{{ modeLabel(run.mode) }}</td>
                                        <td><span :class="statusClass(run.status)">{{ statusLabel(run.status) }}</span></td>
                                        <td>{{ run.consumed_credits }}/{{ run.reserved_credits }}</td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary" @click="loadRunDetail(run.id)">
                                                <i class="ti ti-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="runs.data.length === 0">
                                        <td colspan="6" class="text-center text-muted py-3">{{ label('empty_runs', 'Nenhuma execução encontrada.') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <nav v-if="paginationLinks.length > 3" class="mt-2" aria-label="Paginação de execuções">
                            <ul class="pagination pagination-sm mb-0">
                                <li
                                    v-for="(page, index) in paginationLinks"
                                    :key="`${index}-${page.label}`"
                                    class="page-item"
                                    :class="{ active: page.active, disabled: !page.url }"
                                >
                                    <button
                                        type="button"
                                        class="page-link"
                                        :disabled="!page.url || page.active"
                                        @click="goToPage(page.url)"
                                        v-html="page.label"
                                    ></button>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="border rounded p-3 bg-white" style="min-height: 320px;">
                        <h6 class="fw-semibold mb-2">{{ label('details', 'Detalhes') }}</h6>
                        <div v-if="detailLoading" class="text-muted">{{ label('loading', 'Carregando') }}...</div>
                        <div v-else-if="!selectedRun" class="text-muted">{{ label('select_run', 'Selecione uma execução para visualizar.') }}</div>
                        <template v-else>
                            <div class="mb-2"><strong>{{ label('status', 'Status') }}:</strong> <span :class="statusClass(selectedRun.status)">{{ statusLabel(selectedRun.status) }}</span></div>
                            <div class="mb-2"><strong>{{ label('patient', 'Paciente') }}:</strong> {{ selectedRun.patient || '-' }}</div>
                            <div class="mb-2"><strong>{{ label('medical_record', 'Prontuário') }}:</strong> {{ selectedRun.medical_record_code || '-' }}</div>

                            <label class="form-label mt-2">{{ label('editable_draft', 'Rascunho editável') }}</label>
                            <textarea v-model="draftOutput" rows="8" class="form-control"></textarea>

                            <div class="mt-3 d-flex gap-2" v-if="canApproveOrReject">
                                <button class="btn btn-success btn-sm" @click="approveRun">
                                    <i class="ti ti-check me-1"></i>{{ label('approve') }}
                                </button>
                                <button class="btn btn-danger btn-sm" @click="rejectRun">
                                    <i class="ti ti-x me-1"></i>{{ label('reject') }}
                                </button>
                            </div>

                            <div class="mt-2" v-if="canApproveOrReject">
                                <label class="form-label">{{ label('rejection_reason_optional', 'Motivo da rejeição (opcional)') }}</label>
                                <input v-model="rejectReason" type="text" class="form-control">
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
