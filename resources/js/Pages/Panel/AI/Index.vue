<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import AiPaywallNotice from '@/Components/Panel/AiPaywallNotice.vue';
import AiCreditPackCheckout from '@/Components/Billing/AiCreditPackCheckout.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

const props = defineProps({
    balance: { type: Object, required: true },
    paywall: { type: Object, default: null },
    creditPackages: { type: Array, default: () => [] },
    recentCreditPurchases: { type: Array, default: () => [] },
    canPurchaseCredits: { type: Boolean, default: false },
    checkoutT: { type: Object, default: () => ({}) },
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

// Onda 3, P4 — formata segundos como "mm:ss" ou "Xs" para o dashboard.
function formatSeconds(s) {
    if (s === null || s === undefined) return '—';
    const n = Number(s);
    if (!Number.isFinite(n)) return '—';
    if (n < 60) return `${Math.round(n)}s`;
    const m = Math.floor(n / 60);
    const r = Math.round(n % 60);
    return `${m}m ${r}s`;
}
// Franquia mensal: lida da carteira (mesma fonte que bloqueia a execução).
const { date: formatDate, number: formatNumber } = useLocaleFormat();
const quota = computed(() => analytics.value?.quota ?? {});
const usagePercent = computed(() => quota.value?.usage_percent ?? null);
// Renovação só para quem ganha franquia (em atraso, condicionada ao
// pagamento); cota que sobrou (ex.: virou cortesia) mostra até quando vale,
// sem prometer renovação.
const quotaRenewsText = computed(() => {
    if (quota.value?.renews_on) return label('quota_renews_on', '').replace(':date', formatDate(quota.value.renews_on));
    if (quota.value?.renews_if_paid_on)
        return label('quota_renews_if_paid', '').replace(':date', formatDate(quota.value.renews_if_paid_on));
    if (quota.value?.expires_on)
        return label('quota_expires_on', '').replace(':date', formatDate(quota.value.expires_on));
    return '';
});
// Sem nenhum crédito liberado: mostra o paywall (comprar ou pedir ao admin).
const showPaywall = computed(() => !!props.paywall && Number(props.balance?.available ?? 0) <= 0);
const usageBarClass = computed(() => {
    const p = usagePercent.value ?? 0;
    if (p >= 90) return 'bg-danger';
    if (p >= 70) return 'bg-warning';
    return 'bg-success';
});

// Créditos avulsos (cortesia/comprados) — entram na carteira (balance.balance),
// NÃO na franquia. Com a franquia da janela esgotada, as execuções usam eles.
const purchasedCredits = computed(() => Number(props.balance?.balance ?? 0));
const quotaFullyUsed = computed(() => {
    const total = Number(quota.value?.monthly_quota ?? 0);
    return total > 0 && Number(quota.value?.consumed_credits ?? 0) >= total;
});
const coveredByPurchased = computed(() => quotaFullyUsed.value && purchasedCredits.value > 0);

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
const availableStatuses = computed(() =>
    props.statuses.length
        ? props.statuses
        : ['pending', 'reserved', 'running', 'waiting_approval', 'approved', 'rejected', 'failed', 'cancelled'],
);
const statusFilterOptions = computed(() =>
    availableStatuses.value.map((status) => ({ value: status, label: statusLabel(status) })),
);
const paginationLinks = computed(() => (Array.isArray(props.runs?.links) ? props.runs.links : []));

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
    return (
        {
            pending: 'badge bg-secondary-subtle text-secondary',
            reserved: 'badge bg-info-subtle text-info',
            running: 'badge bg-primary-subtle text-primary',
            waiting_approval: 'badge bg-warning-subtle text-warning',
            approved: 'badge bg-success-subtle text-success',
            rejected: 'badge bg-danger-subtle text-danger',
            failed: 'badge bg-danger-subtle text-danger',
            cancelled: 'badge bg-dark-subtle text-dark',
        }[status] ?? 'badge bg-light text-dark'
    );
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

// Pacote pago (cartão aprovado ou webhook): saldo e paywall atualizados.
function onCreditsPaid() {
    router.reload({ only: ['balance', 'paywall', 'recentCreditPurchases'] });
    showSuccess(label('credit_purchase_credited', 'Créditos adicionados.'));
}

// "Comprar créditos" do aviso: leva aos pacotes desta tela.
function goToPackages() {
    document.getElementById('ai-credit-packages')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.querySelector('#ai-credit-packages [data-test="ai-pack-buy"]:not([disabled])')?.focus();
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

            <div v-if="errorMessage" class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="ti ti-alert-circle me-1"></i>{{ errorMessage }}
                <button type="button" class="btn-close" aria-label="Close" @click="dismissMessage"></button>
            </div>
            <div v-if="successMessage" class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="ti ti-check me-1"></i>{{ successMessage }}
                <button type="button" class="btn-close" aria-label="Close" @click="dismissMessage"></button>
            </div>

            <AiPaywallNotice
                v-if="showPaywall"
                :paywall="paywall"
                class="mb-3"
                :on-buy="canPurchaseCredits ? goToPackages : null"
            />

            <div class="row g-3 mb-3">
                <div :class="canPurchaseCredits ? 'col-lg-4' : 'col-12'">
                    <div class="border rounded p-3 bg-white h-100">
                        <div class="d-flex flex-column gap-2">
                            <div>
                                <strong>{{ label('credits_available', 'Créditos disponíveis') }}:</strong>
                                <span data-test="ai-balance-available">{{ formatNumber(balance.available) }}</span>
                            </div>
                            <div>
                                <strong>{{ label('credits_reserved', 'Reservados') }}:</strong>
                                {{ formatNumber(balance.reserved) }}
                            </div>
                            <div>
                                <strong>{{ label('credits_total', 'Total') }}:</strong>
                                {{ formatNumber(balance.total) }}
                            </div>
                        </div>
                        <div class="mt-2 text-muted fs-13">{{ label('support_notice') }}</div>
                    </div>
                </div>

                <div v-if="canPurchaseCredits" id="ai-credit-packages" class="col-lg-8">
                    <div class="border rounded p-3 bg-white h-100">
                        <div class="mb-2">
                            <h6 class="fw-semibold mb-1">
                                {{ label('credit_packages_title', 'Pacotes de créditos IA') }}
                            </h6>
                            <div class="text-muted fs-13">
                                {{ label('credit_packages_subtitle', 'Créditos extras avulsos.') }}
                            </div>
                        </div>
                        <!-- Compra paga no checkout do sistema (Pix, boleto, cartão à vista); pedido pendente: continuar ou descartar. -->
                        <AiCreditPackCheckout ref="packCheckout" :t="checkoutT" show-pending @paid="onCreditsPaid" />
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

                <!-- Franquia mensal (carteira) + barra de progresso -->
                <div v-if="quota.monthly_quota > 0" class="mb-3" data-test="ai-quota-meter">
                    <div class="d-flex justify-content-between mb-1 fs-13 flex-wrap gap-1">
                        <span class="fw-semibold">{{ label('quota_title', 'Franquia mensal de IA') }}</span>
                        <span class="text-muted" data-test="ai-quota-text">
                            {{
                                label('quota_credits', ':used / :quota')
                                    .replace(':used', formatNumber(quota.consumed_credits ?? 0))
                                    .replace(':quota', formatNumber(quota.monthly_quota))
                            }}
                            <span v-if="usagePercent !== null" class="ms-1">({{ usagePercent }}%)</span>
                            <span v-if="quotaRenewsText" class="ms-1">· {{ quotaRenewsText }}</span>
                        </span>
                    </div>
                    <div class="progress" style="height: 12px">
                        <div
                            :class="['progress-bar', usageBarClass]"
                            role="progressbar"
                            :style="{ width: Math.min(usagePercent ?? 0, 100) + '%' }"
                            :aria-valuenow="usagePercent ?? 0"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        ></div>
                    </div>
                    <div
                        v-if="coveredByPurchased"
                        class="alert alert-info text-info-emphasis py-1 px-2 mt-2 mb-0 fs-13"
                        data-test="ai-quota-spillover"
                    >
                        <i class="ti ti-wallet me-1" aria-hidden="true"></i>
                        {{ label('quota_spillover', '').replace(':available', formatNumber(purchasedCredits)) }}
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
                                <template
                                    v-if="analytics.approval?.rate !== null && analytics.approval?.rate !== undefined"
                                >
                                    {{ analytics.approval.rate }}%
                                </template>
                                <template v-else>—</template>
                            </div>
                        </div>
                    </div>

                    <!-- Por workflow -->
                    <div class="col-md-6">
                        <h6 class="fw-semibold fs-13 mb-2">Por workflow</h6>
                        <div v-if="analytics.by_workflow?.length === 0" class="text-muted fs-13">
                            Sem execuções no período.
                        </div>
                        <ul v-else class="list-unstyled mb-0">
                            <li
                                v-for="row in analytics.by_workflow"
                                :key="row.workflow"
                                class="d-flex justify-content-between border-bottom py-1 fs-13"
                            >
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
                        <div v-if="analytics.by_mode?.length === 0" class="text-muted fs-13">
                            Sem execuções no período.
                        </div>
                        <ul v-else class="list-unstyled mb-0">
                            <li
                                v-for="row in analytics.by_mode"
                                :key="row.mode"
                                class="d-flex justify-content-between border-bottom py-1 fs-13"
                            >
                                <span>{{ modeDisplayLabel(row.mode) }}</span>
                                <span class="text-muted">{{ row.runs_count }} runs</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Onda 3, P4 — Métricas operacionais ─────────────── -->
                    <div
                        class="col-md-4"
                        v-if="
                            analytics.avg_approve_time_seconds !== null &&
                            analytics.avg_approve_time_seconds !== undefined
                        "
                    >
                        <div class="border rounded p-3 h-100 bg-white">
                            <div class="text-muted fs-13 mb-1">
                                {{ label('dashboard_avg_approve_time', 'Tempo médio para aprovar') }}
                            </div>
                            <div class="fs-3 fw-bold text-primary">
                                {{ formatSeconds(analytics.avg_approve_time_seconds) }}
                            </div>
                        </div>
                    </div>
                    <div
                        class="col-md-4"
                        v-if="analytics.avg_cost_per_record !== null && analytics.avg_cost_per_record !== undefined"
                    >
                        <div class="border rounded p-3 h-100 bg-white">
                            <div class="text-muted fs-13 mb-1">
                                {{ label('dashboard_avg_cost', 'Custo médio por consulta') }}
                            </div>
                            <div class="fs-3 fw-bold text-info">{{ analytics.avg_cost_per_record }} cr</div>
                        </div>
                    </div>
                    <div class="col-12" v-if="analytics.by_doctor?.length">
                        <h6 class="fw-semibold fs-13 mb-2">
                            {{ label('dashboard_by_doctor', 'Médicos mais ativos') }}
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ label('dashboard_doctor', 'Médico') }}</th>
                                        <th class="text-end">{{ label('dashboard_approved', 'Aprovados') }}</th>
                                        <th class="text-end">
                                            {{ label('dashboard_avg_credits', 'Créditos médios') }}
                                        </th>
                                        <th class="text-end">{{ label('dashboard_avg_time', 'Tempo médio') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in analytics.by_doctor" :key="r.doctor_id">
                                        <td class="fs-13">{{ r.doctor_name }}</td>
                                        <td class="text-end">{{ r.approved }}</td>
                                        <td class="text-end">{{ r.avg_credits }}</td>
                                        <td class="text-end fs-13 text-muted">
                                            {{ formatSeconds(r.avg_approve_seconds) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
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
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <h6 class="fw-semibold mb-0">{{ label('runs', 'Execuções') }}</h6>
                            <SearchSelect
                                v-model="statusFilter"
                                class="ai-status-filter"
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
                                        <td>
                                            {{ workflowLabel(run.workflow) }}
                                            <span
                                                v-if="run.is_escalation"
                                                class="badge bg-info-subtle text-info ms-1"
                                                title="Reanálise com modo superior"
                                            >
                                                <i class="ti ti-arrow-up"></i> Reanálise
                                            </span>
                                        </td>
                                        <td>{{ modeLabel(run.mode) }}</td>
                                        <td>
                                            <span :class="statusClass(run.status)">{{ statusLabel(run.status) }}</span>
                                        </td>
                                        <td>{{ run.consumed_credits }}/{{ run.reserved_credits }}</td>
                                        <td class="text-end">
                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                @click="loadRunDetail(run.id)"
                                            >
                                                <i class="ti ti-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="runs.data.length === 0">
                                        <td colspan="6" class="text-center text-muted py-3">
                                            {{ label('empty_runs', 'Nenhuma execução encontrada.') }}
                                        </td>
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
                    <div class="border rounded p-3 bg-white" style="min-height: 320px">
                        <h6 class="fw-semibold mb-2">{{ label('details', 'Detalhes') }}</h6>
                        <div v-if="detailLoading" class="text-muted">{{ label('loading', 'Carregando') }}...</div>
                        <div v-else-if="!selectedRun" class="text-muted">
                            {{ label('select_run', 'Selecione uma execução para visualizar.') }}
                        </div>
                        <template v-else>
                            <!-- Uma linha só: com quebra de linha entre </strong> e <span>,
                                 o Vue apaga o espaço ("Status:Concluído"). -->
                            <!-- prettier-ignore -->
                            <div class="mb-2"><strong>{{ label('status', 'Status') }}:</strong> <span :class="statusClass(selectedRun.status)">{{ statusLabel(selectedRun.status) }}</span></div>
                            <div class="mb-2">
                                <strong>{{ label('patient', 'Paciente') }}:</strong> {{ selectedRun.patient || '-' }}
                            </div>
                            <div class="mb-2">
                                <strong>{{ label('medical_record', 'Prontuário') }}:</strong>
                                {{ selectedRun.medical_record_code || '-' }}
                            </div>

                            <!-- MELHORIA — contexto clínico do exame analisado (só existe no
                                 fluxo de imagem ocular; runs de prontuário/texto não têm exame
                                 vinculado, então esta seção some nesse caso). -->
                            <template v-if="selectedRun.exam_context">
                                <div class="mb-2" v-if="selectedRun.exam_context.exam_types?.length">
                                    <strong>{{ label('exam_type', 'Tipo de exame') }}:</strong>
                                    {{ selectedRun.exam_context.exam_types.join(', ') }}
                                </div>
                                <div class="mb-2" v-if="selectedRun.exam_context.exam_date">
                                    <strong>{{ label('exam_date', 'Data do exame') }}:</strong>
                                    {{ selectedRun.exam_context.exam_date }}
                                </div>
                                <div class="mb-2" v-if="selectedRun.exam_context.doctors?.length">
                                    <strong>{{ label('exam_doctor', 'Médico responsável') }}:</strong>
                                    {{ selectedRun.exam_context.doctors.join(', ') }}
                                </div>
                                <div class="mb-2" v-if="selectedRun.exam_context.diagnoses?.length">
                                    <strong>{{ label('exam_diagnosis', 'Diagnóstico') }}:</strong>
                                    <span
                                        v-for="(d, i) in selectedRun.exam_context.diagnoses"
                                        :key="i"
                                        class="badge me-1"
                                        :class="
                                            d.is_primary
                                                ? 'bg-primary-subtle text-primary'
                                                : 'bg-secondary-subtle text-secondary'
                                        "
                                        :title="d.is_primary ? label('diagnosis_primary', 'Diagnóstico principal') : ''"
                                        >{{ d.description }}</span
                                    >
                                </div>
                            </template>

                            <div class="mb-2" v-if="selectedRun.analysis_summary">
                                <strong>{{ label('analysis_summary', 'Resumo da análise') }}:</strong>
                                <div class="text-muted fs-13" style="white-space: pre-wrap">
                                    {{ selectedRun.analysis_summary }}
                                </div>
                            </div>

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
                                <label class="form-label">{{
                                    label('rejection_reason_optional', 'Motivo da rejeição (opcional)')
                                }}</label>
                                <input v-model="rejectReason" type="text" class="form-control" />
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
/* MELHORIA — filtro de status estava "w-auto" (encolhe pro conteúdo atual),
   deixando o texto das opções (ex.: "Aguardando aprovação") espremido no
   dropdown. Largura mínima fixa resolve sem quebrar responsividade (o
   flex-wrap no header já cuida de telas estreitas). */
.ai-status-filter {
    min-width: 220px;
    max-width: 100%;
}
</style>
