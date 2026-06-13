<script setup>
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';
import AiCreditPurchaseDetailDrawer from './AiCreditPurchaseDetailDrawer.vue';
import ManualCreditModal from './ManualCreditModal.vue';
import ConsumptionByProviderChart from './ConsumptionByProviderChart.vue';
import InternalWalletCard from './InternalWalletCard.vue';
import ProviderCostsCard from './ProviderCostsCard.vue';
import ProviderTopupModal from './ProviderTopupModal.vue';

/**
 * Manager: dois trabalhos do dono do SaaS, separados em abas claras —
 *   1. Recargas nos provedores (o que você gasta, em USD).
 *   2. Créditos das clínicas (o que você distribui: cortesia ou compra paga).
 *
 * Uma faixa de resumo no topo liga os dois (saldo nos provedores × distribuído ×
 * margem). O antigo funil de aprovação de pedido (pouco usado) virou um aviso
 * discreto que só aparece quando há pedidos de cliente pendentes.
 *
 * RBAC (props.permissions):
 *   - credit, fail                 : Admin + Financial
 *   - cancel                       : Admin + Support
 *   - refund                       : Admin only
 *   - create_manual                : Admin + Financial + Support (limite diário p/ Support)
 *   - create_manual_unlimited      : Admin + Financial
 *   - create_manual_for_internal   : Admin only (entity interna do SaaS)
 *   - create_topup / delete_topup  : Financial / Admin
 *
 * Toda ação destrutiva passa por ConfirmationWithReasonModal — reason vai para audit_log.
 */
const props = defineProps({
    breadcrumbs:           { type: Array,  default: () => [] },
    purchases:             { type: Object, default: () => ({ data: [], meta: {}, links: {} }) },
    kpis:                  { type: Object, default: () => ({}) },
    providerCosts:         { type: Object, default: () => null },
    consumptionByProvider: { type: Object, default: () => ({}) },
    internalWallet:        { type: Object, default: () => null },
    topConsumers:          { type: Array,  default: () => [] },
    filters:               { type: Object, default: () => ({}) },
    statusOptions:         { type: Array,  default: () => [] },
    providerOptions:       { type: Array,  default: () => [] },
    entities:              { type: Array,  default: () => [] },
    permissions:           { type: Object, default: () => ({}) },
    t:                     { type: Object, default: () => ({}) },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const filterForm = reactive({
    status:    props.filters.status    ?? '',
    provider:  props.filters.provider  ?? '',
    entity_id: props.filters.entity_id ?? '',
    date_from: props.filters.date_from ?? '',
    date_to:   props.filters.date_to   ?? '',
    q:         props.filters.q         ?? '',
});

function applyFilters() {
    const params = {};
    Object.entries(filterForm).forEach(([k, v]) => { if (v) params[k] = v; });
    router.get(route('manager.ai-credit-purchases.index'), params, {
        preserveScroll: true,
        preserveState: true,
        only: ['purchases', 'kpis', 'consumptionByProvider', 'topConsumers', 'filters'],
    });
}

function clearFilters() {
    Object.keys(filterForm).forEach(k => filterForm[k] = '');
    applyFilters();
}

// ── Abas: separa os dois trabalhos (clínicas x provedores) ────────────────────
const activeTab = ref('clients');

// ── Faixa de resumo (liga os dois trabalhos) ──────────────────────────────────
function fmtUsd(v) {
    return '$' + Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtNum(n) {
    return Number(n ?? 0).toLocaleString('pt-BR');
}

const ALERT_ORDER   = { ok: 0, warning: 1, critical: 2, exhausted: 3 };
const ALERT_VARIANT = { ok: 'success', warning: 'warning', critical: 'danger', exhausted: 'danger' };

function alertLabel(level) {
    const fallback = { ok: 'OK', warning: 'Atenção', critical: 'Crítico', exhausted: 'Esgotado' };
    return props.t?.summary?.alert?.[level] ?? fallback[level] ?? '';
}

// Soma o saldo estimado restante de todos os provedores que têm recarga registrada.
const providersBalance = computed(() => {
    const byProvider = props.providerCosts?.by_provider ?? {};
    let total = 0;
    let hasAny = false;
    let worst = 'ok';
    for (const info of Object.values(byProvider)) {
        const eb = info?.estimated_balance;
        if (eb?.has_topups) {
            hasAny = true;
            total += eb.remaining_usd ?? 0;
            if ((ALERT_ORDER[eb.alert_level] ?? -1) > (ALERT_ORDER[worst] ?? -1)) worst = eb.alert_level;
        }
    }
    return { total, hasAny, worst, variant: ALERT_VARIANT[worst] ?? 'secondary' };
});

const margin = computed(() => props.providerCosts?.margin ?? {});
const marginVariant = computed(() => {
    const pct = margin.value?.gross_margin_pct ?? 0;
    if (pct >= 40) return 'success';
    if (pct >= 20) return 'warning';
    return 'danger';
});

const distributed  = computed(() => props.kpis?.distributed_mtd ?? {});
const pendingCount  = computed(() => props.kpis?.pending?.count ?? 0);

// Mostra os pedidos de cliente pendentes (fluxo raro) na aba de clínicas.
function viewPending() {
    activeTab.value = 'clients';
    filterForm.status = 'pending_payment';
    applyFilters();
}

// ── Drawer de detalhe ────────────────────────────────────────────────────────
const drawerOpen = ref(false);
const drawerPurchase = ref(null);

function openDetail(purchase) {
    drawerPurchase.value = purchase;
    drawerOpen.value = true;
}

function closeDetail() {
    drawerOpen.value = false;
    drawerPurchase.value = null;
}

// ── Crédito manual ───────────────────────────────────────────────────────────
const manualModalOpen = ref(false);
const manualModalRef = ref(null);
const presetEntityId = ref(null);

// ── Topup de provedor ────────────────────────────────────────────────────────
const topupModalOpen = ref(false);
const topupModalRef = ref(null);
const presetTopupProvider = ref('');

function openTopupModal(provider = '') {
    presetTopupProvider.value = provider;
    topupModalOpen.value = true;
}

function closeTopupModal() {
    topupModalOpen.value = false;
    presetTopupProvider.value = '';
}

async function submitTopup(payload) {
    topupModalRef.value?.setSaving(true);
    topupModalRef.value?.setError('');
    try {
        const res = await fetch(route('manager.ai-provider-topups.store'), {
            method: 'POST',
            headers: {
                'Accept':       'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payload),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            topupModalRef.value?.setError(json.message ?? `Erro ${res.status}`);
            return;
        }
        closeTopupModal();
        reload();
    } catch (e) {
        topupModalRef.value?.setError(e.message);
    } finally {
        topupModalRef.value?.setSaving(false);
    }
}

function openManualModal(entityId = null) {
    presetEntityId.value = entityId;
    manualModalOpen.value = true;
}

function closeManualModal() {
    manualModalOpen.value = false;
    presetEntityId.value = null;
}

async function submitManual(payload) {
    manualModalRef.value?.setSaving(true);
    manualModalRef.value?.setError('');
    try {
        const res = await fetch(route('manager.ai-credit-purchases.manual'), {
            method: 'POST',
            headers: {
                'Accept':       'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payload),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            manualModalRef.value?.setError(json.message ?? `Erro ${res.status}`);
            return;
        }
        closeManualModal();
        reload();
    } catch (e) {
        manualModalRef.value?.setError(e.message);
    } finally {
        manualModalRef.value?.setSaving(false);
    }
}

// ── Ações com confirmação ────────────────────────────────────────────────────
const {
    state: reasonModal,
    open: openReasonModal,
    close: closeReasonModal,
    handle: handleReasonConfirm,
} = useConfirmationWithReason();

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function callAction(url, payload = {}) {
    const res = await fetch(url, {
        method: 'PATCH',
        headers: {
            'Accept':       'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message ?? `Erro ${res.status}`);
    return json;
}

function showToast(message, type = 'success') {
    if (type === 'error') alert(message);
}

function reload() {
    router.reload({ only: ['purchases', 'kpis', 'providerCosts', 'consumptionByProvider', 'topConsumers', 'internalWallet'] });
}

function onCredit(purchase) {
    if (!props.permissions.credit) return;
    openReasonModal({
        title: props.t?.confirm?.credit_title ?? 'Aprovar e creditar?',
        message: (props.t?.confirm?.credit_body ?? '').replace(':credits', purchase.credits),
        confirmVariant: 'primary',
        async onConfirm(reason) {
            try {
                const json = await callAction(purchase.actions_url.credit, { reason });
                showToast(json.message, 'success');
                reload();
            } catch (e) {
                showToast(e.message, 'error');
            }
        },
    });
}

function onCancel(purchase) {
    if (!props.permissions.cancel) return;
    openReasonModal({
        title: props.t?.confirm?.cancel_title ?? 'Cancelar pedido pendente?',
        message: props.t?.confirm?.cancel_body ?? '',
        confirmVariant: 'warning',
        async onConfirm(reason) {
            try {
                const json = await callAction(purchase.actions_url.cancel, { reason });
                showToast(json.message, 'success');
                reload();
            } catch (e) {
                showToast(e.message, 'error');
            }
        },
    });
}

function onMarkFailed(purchase) {
    if (!props.permissions.fail) return;
    openReasonModal({
        title: props.t?.confirm?.fail_title ?? 'Marcar como falha?',
        message: props.t?.confirm?.fail_body ?? '',
        confirmVariant: 'danger',
        async onConfirm(reason) {
            try {
                const json = await callAction(purchase.actions_url.fail, { reason });
                showToast(json.message, 'success');
                reload();
            } catch (e) {
                showToast(e.message, 'error');
            }
        },
    });
}

function onRefund(purchase) {
    if (!props.permissions.refund) return;
    openReasonModal({
        title: props.t?.confirm?.refund_title ?? 'Reembolsar e estornar?',
        message: (props.t?.confirm?.refund_body ?? '').replace(':credits', purchase.credits),
        confirmVariant: 'danger',
        async onConfirm(reason) {
            try {
                const json = await callAction(purchase.actions_url.refund, { reason });
                showToast(json.message, 'success');
                reload();
            } catch (e) {
                showToast(e.message, 'error');
            }
        },
    });
}

// Classe do badge de tipo (kind vem pronto do backend: courtesy | purchase | client).
function kindClass(kind) {
    switch (kind) {
        case 'courtesy': return 'bg-info-subtle text-info';
        case 'purchase': return 'bg-success-subtle text-success';
        case 'client':   return 'bg-primary-subtle text-primary';
        default:         return 'bg-secondary-subtle text-secondary';
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
const hasResults = computed(() => (props.purchases?.data ?? []).length > 0);

// Preserva o marcador ★ das entidades internas (não-cliente) no rótulo.
const entityOptions = computed(() =>
    (props.entities ?? []).map((e) => ({ id: e.id, name: `${e.name}${!e.is_client ? ' ★' : ''}` })),
);

function goToPage(url) {
    if (!url) return;
    router.get(url, {}, { preserveScroll: true, preserveState: true, only: ['purchases'] });
}

function providerBadgeClass(provider) {
    switch (provider) {
        case 'openai':    return 'badge bg-success-subtle text-success border border-success border-opacity-25';
        case 'anthropic': return 'badge bg-warning-subtle text-warning border border-warning border-opacity-25';
        case 'gemini':    return 'badge bg-primary-subtle text-primary border border-primary border-opacity-25';
        default:          return 'badge bg-light text-muted';
    }
}

function providerIcon(provider) {
    switch (provider) {
        case 'openai':    return 'ti ti-brand-openai';
        case 'anthropic': return 'ti ti-message-chatbot';
        case 'gemini':    return 'ti ti-brand-google';
        default:          return 'ti ti-minus';
    }
}
</script>

<template>
    <AppLayout :title="t?.title ?? 'Créditos de IA'" :breadcrumbs="breadcrumbs">
        <div class="ai-credit-purchases-screen">

            <PageHeader
                :title="t?.title ?? 'Créditos de IA'"
                :subtitle="t?.subtitle ?? 'Recargas nos provedores e distribuição de créditos às clínicas.'"
            />

            <!-- ─── Faixa de resumo: liga os dois trabalhos ────────────────── -->
            <div class="row g-2 mb-3">
                <!-- Saldo nos provedores (lado oferta) -->
                <div class="col-12 col-md-4">
                    <div class="card h-100 summary-card summary-card--providers" role="button" @click="activeTab = 'providers'">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted small">
                                    <i class="ti ti-server-bolt text-primary me-1"></i>
                                    {{ t?.summary?.providers_balance ?? 'Saldo nos provedores' }}
                                </span>
                                <span
                                    v-if="providersBalance.hasAny"
                                    class="badge"
                                    :class="`bg-${providersBalance.variant}-subtle text-${providersBalance.variant}`"
                                >{{ alertLabel(providersBalance.worst) }}</span>
                            </div>
                            <h4 v-if="providersBalance.hasAny" class="mb-0 mt-1 fw-bold">{{ fmtUsd(providersBalance.total) }}</h4>
                            <h4 v-else class="mb-0 mt-1 text-muted">—</h4>
                            <div class="small text-muted mt-1">
                                {{ providersBalance.hasAny
                                    ? (t?.summary?.providers_balance_help ?? 'estimado restante (USD)')
                                    : (t?.summary?.providers_no_data ?? 'sem recarga registrada') }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Distribuído no mês (lado demanda) -->
                <div class="col-12 col-md-4">
                    <div class="card h-100 summary-card summary-card--clients" role="button" @click="activeTab = 'clients'">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted small">
                                    <i class="ti ti-coins text-success me-1"></i>
                                    {{ t?.summary?.distributed ?? 'Distribuído no mês' }}
                                </span>
                            </div>
                            <h4 class="mb-0 mt-1 fw-bold">
                                {{ fmtNum(distributed.credits) }}
                                <small class="text-muted fw-normal" style="font-size: .7rem;">{{ t?.summary?.distributed_help ?? 'créditos' }}</small>
                            </h4>
                            <div class="small mt-1">
                                <span class="text-info">
                                    <i class="ti ti-gift"></i> {{ fmtNum(distributed.courtesy_credits) }} {{ t?.summary?.courtesy ?? 'cortesia' }}
                                </span>
                                <span class="text-muted mx-1">·</span>
                                <span class="text-success">
                                    <i class="ti ti-cash"></i> {{ fmtNum(distributed.paid_credits) }} {{ t?.summary?.paid ?? 'compra' }}
                                </span>
                            </div>
                            <div class="small text-muted mt-1">{{ distributed.amount_formatted ?? 'R$ 0,00' }}</div>
                        </div>
                    </div>
                </div>

                <!-- Margem bruta (elo entre os dois) -->
                <div class="col-12 col-md-4">
                    <div class="card h-100 summary-card">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted small">
                                    <i class="ti ti-trending-up me-1" :class="`text-${marginVariant}`"></i>
                                    {{ t?.summary?.margin ?? 'Margem bruta (mês)' }}
                                </span>
                            </div>
                            <h4 class="mb-0 mt-1 fw-bold" :class="`text-${marginVariant}`">{{ margin.gross_margin_pct ?? 0 }}%</h4>
                            <div class="small fw-semibold mt-1" :class="`text-${marginVariant}`">
                                {{ fmtUsd(margin.gross_margin_usd) }}
                            </div>
                            <div class="small text-muted mt-1">{{ t?.summary?.margin_help ?? 'receita estimada − custo dos provedores' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Aviso discreto: pedidos de cliente pendentes ───────────── -->
            <div
                v-if="pendingCount > 0"
                class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 small mb-3"
            >
                <div class="d-flex align-items-center gap-2">
                    <i class="ti ti-clock-exclamation"></i>
                    <div>
                        <strong>{{ (t?.pending_alert?.title ?? ':count pedido(s) de cliente aguardando aprovação').replace(':count', pendingCount) }}</strong>
                        <div class="text-muted">{{ t?.pending_alert?.body ?? '' }}</div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-warning" @click="viewPending">
                    <i class="ti ti-arrow-right me-1"></i>{{ t?.pending_alert?.action ?? 'Ver pendentes' }}
                </button>
            </div>

            <!-- ─── Abas: separam os dois trabalhos do dono do SaaS ────────── -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{ active: activeTab === 'clients' }"
                        @click="activeTab = 'clients'"
                    >
                        <i class="ti ti-building-hospital me-1"></i>
                        {{ t?.tabs?.clients ?? 'Créditos das clínicas' }}
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{ active: activeTab === 'providers' }"
                        @click="activeTab = 'providers'"
                    >
                        <i class="ti ti-server-bolt me-1"></i>
                        {{ t?.tabs?.providers ?? 'Recargas nos provedores' }}
                    </button>
                </li>
            </ul>

            <!-- ════════════ ABA: CRÉDITOS DAS CLÍNICAS ════════════ -->
            <div v-show="activeTab === 'clients'">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <p class="text-muted small mb-0 flex-grow-1">
                        <i class="ti ti-info-circle me-1"></i>
                        {{ t?.tab_help?.clients ?? 'Conceda cortesia ou registre uma compra paga direto na carteira de uma clínica.' }}
                    </p>
                    <button
                        v-if="permissions?.create_manual"
                        type="button"
                        class="btn btn-success btn-sm flex-shrink-0"
                        @click="openManualModal()"
                    >
                        <i class="ti ti-coin-plus me-1"></i>
                        {{ t?.actions?.create_manual ?? 'Conceder crédito à clínica' }}
                    </button>
                </div>

                <!-- ─── Card destaque: Sua empresa ─────────────────────────────── -->
                <InternalWalletCard
                    v-if="internalWallet"
                    :wallet="internalWallet"
                    :permissions="permissions"
                    :t="t"
                    @add-credit="openManualModal"
                />

            <!-- ─── Consumo por provedor + Top consumers + Filtros ─────────── -->
            <div class="row g-2 mb-3">
                <div class="col-12 col-xl-4">
                    <ConsumptionByProviderChart :consumption="consumptionByProvider" :t="t" />
                </div>

                <div class="col-12 col-xl-4">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex align-items-center">
                            <i class="ti ti-trophy text-warning me-2"></i>
                            <strong class="small">{{ t?.kpi?.top_consumers ?? 'Top 5 clínicas (30 dias)' }}</strong>
                        </div>
                        <div class="card-body p-2">
                            <div v-if="topConsumers.length === 0" class="text-muted text-center small py-3">
                                {{ t?.kpi?.no_consumers ?? 'Nenhum consumo no período.' }}
                            </div>
                            <table v-else class="table table-sm table-hover mb-0">
                                <tbody>
                                    <tr v-for="(c, i) in topConsumers" :key="c.entity_id">
                                        <td class="ps-2" style="width: 24px;">
                                            <span class="badge bg-light text-dark">{{ i + 1 }}</span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold small d-flex align-items-center gap-1">
                                                {{ c.entity_name }}
                                                <span v-if="c.is_internal" class="badge bg-primary-subtle text-primary small ms-1">
                                                    <i class="ti ti-building"></i>
                                                </span>
                                            </div>
                                            <small class="text-muted">{{ c.purchases_total }} lançamentos</small>
                                        </td>
                                        <td class="text-end">
                                            <div class="fw-semibold small">{{ c.credits_total.toLocaleString('pt-BR') }}</div>
                                            <small class="text-muted">{{ c.amount_formatted }}</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-4">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex align-items-center">
                            <i class="ti ti-filter text-secondary me-2"></i>
                            <strong class="small">{{ t?.filters?.title ?? 'Filtros' }}</strong>
                        </div>
                        <div class="card-body p-3">
                            <form @submit.prevent="applyFilters" class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-1">{{ t?.filters?.status ?? 'Status' }}</label>
                                    <SearchSelect
                                        v-model="filterForm.status"
                                        :options="statusOptions"
                                        :value-key="'value'"
                                        :label-key="'label'"
                                        :placeholder="t?.filters?.all ?? 'Todos'"
                                    />
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">{{ t?.filters?.provider ?? 'Provedor' }}</label>
                                    <SearchSelect
                                        v-model="filterForm.provider"
                                        :options="providerOptions"
                                        :value-key="'value'"
                                        :label-key="'label'"
                                        :placeholder="t?.filters?.all ?? 'Todos'"
                                    />
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">{{ t?.filters?.entity ?? 'Empresa' }}</label>
                                    <SearchSelect
                                        v-model="filterForm.entity_id"
                                        :options="entityOptions"
                                        :label-key="'name'"
                                        :placeholder="t?.filters?.all ?? 'Todas'"
                                    />
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">{{ t?.filters?.date_from ?? 'De' }}</label>
                                    <input v-model="filterForm.date_from" type="date" class="form-control form-control-sm">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">{{ t?.filters?.date_to ?? 'Até' }}</label>
                                    <input v-model="filterForm.date_to" type="date" class="form-control form-control-sm">
                                </div>
                                <div class="col-12 d-flex justify-content-between mt-1">
                                    <button type="button" class="btn btn-sm btn-link text-muted p-0" @click="clearFilters">
                                        {{ t?.filters?.clear ?? 'Limpar' }}
                                    </button>
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="ti ti-search me-1"></i>{{ t?.filters?.apply ?? 'Filtrar' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Tabela ───────────────────────────────────────────────── -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">{{ t?.columns?.created_at ?? 'Solicitado em' }}</th>
                                <th>{{ t?.columns?.entity ?? 'Empresa' }}</th>
                                <th>{{ t?.columns?.package ?? 'Tipo' }}</th>
                                <th class="text-end">{{ t?.columns?.credits ?? 'Créditos' }}</th>
                                <th class="text-end">{{ t?.columns?.amount ?? 'Valor' }}</th>
                                <th>{{ t?.columns?.requested_by ?? 'Solicitante' }}</th>
                                <th>{{ t?.columns?.status ?? 'Status' }}</th>
                                <th class="text-end pe-3">{{ t?.columns?.actions ?? 'Ações' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!hasResults">
                                <td colspan="8" class="text-center text-muted small py-4">
                                    {{ t?.empty ?? 'Nenhum lançamento encontrado.' }}
                                </td>
                            </tr>
                            <tr v-for="p in purchases.data" :key="p.id" @click="openDetail(p)" style="cursor: pointer;">
                                <td class="ps-3">
                                    <div class="small">{{ p.created_at }}</div>
                                </td>
                                <td>
                                    <div class="fw-semibold small d-flex align-items-center gap-1">
                                        {{ p.entity_name ?? '—' }}
                                        <span v-if="p.is_internal" class="badge bg-primary-subtle text-primary small ms-1">
                                            <i class="ti ti-building"></i>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" :class="kindClass(p.kind)">{{ p.kind_label }}</span>
                                    <div v-if="p.provider" class="mt-1">
                                        <span :class="providerBadgeClass(p.provider)" style="font-size: .65rem;">
                                            <i :class="providerIcon(p.provider)" class="me-1"></i>{{ p.provider_label }}
                                        </span>
                                    </div>
                                </td>
                                <td class="text-end fw-semibold">{{ p.credits.toLocaleString('pt-BR') }}</td>
                                <td class="text-end fw-semibold">
                                    <span v-if="p.amount_cents > 0">{{ p.amount_formatted }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <div class="small">{{ p.requested_by ?? '—' }}</div>
                                    <small v-if="p.requested_email" class="text-muted">{{ p.requested_email }}</small>
                                </td>
                                <td>
                                    <span class="badge" :class="p.status_badge">{{ p.status_label }}</span>
                                </td>
                                <td class="text-end pe-3" @click.stop>
                                    <ActionIconGroup>
                                        <ActionIconButton
                                            icon="ti ti-eye"
                                            variant="info"
                                            :title="t?.actions?.view ?? 'Detalhes'"
                                            @click="openDetail(p)" />
                                        <ActionIconButton
                                            v-if="permissions.credit && p.allowed.credit"
                                            icon="ti ti-check"
                                            variant="success"
                                            :title="t?.actions?.credit ?? 'Aprovar e creditar'"
                                            @click="onCredit(p)" />
                                        <ActionIconButton
                                            v-if="permissions.cancel && p.allowed.cancel"
                                            icon="ti ti-x"
                                            variant="secondary"
                                            :title="t?.actions?.cancel ?? 'Cancelar'"
                                            @click="onCancel(p)" />
                                        <ActionIconButton
                                            v-if="permissions.fail && p.allowed.fail"
                                            icon="ti ti-alert-triangle"
                                            variant="warning"
                                            :title="t?.actions?.fail ?? 'Marcar como falha'"
                                            @click="onMarkFailed(p)" />
                                        <ActionIconButton
                                            v-if="permissions.refund && p.allowed.refund"
                                            icon="ti ti-arrow-back-up"
                                            variant="danger"
                                            :title="t?.actions?.refund ?? 'Reembolsar'"
                                            @click="onRefund(p)" />
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="hasResults && purchases.meta?.last_page > 1" class="card-footer py-2 d-flex justify-content-between align-items-center">
                    <small class="text-muted">
                        {{ purchases.meta.from }}–{{ purchases.meta.to }} de {{ purchases.meta.total }}
                    </small>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary"
                                :disabled="!purchases.links.prev"
                                @click="goToPage(purchases.links.prev)">
                            <i class="ti ti-chevron-left"></i>
                        </button>
                        <span class="btn btn-outline-secondary disabled">
                            {{ purchases.meta.current_page }} / {{ purchases.meta.last_page }}
                        </span>
                        <button class="btn btn-outline-secondary"
                                :disabled="!purchases.links.next"
                                @click="goToPage(purchases.links.next)">
                            <i class="ti ti-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            </div><!-- /aba: créditos das clínicas -->

            <!-- ════════════ ABA: RECARGAS NOS PROVEDORES (saldo / USD) ════════════ -->
            <div v-show="activeTab === 'providers'">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <p class="text-muted small mb-0 flex-grow-1">
                        <i class="ti ti-info-circle me-1"></i>
                        {{ t?.tab_help?.providers ?? 'Registre quanto você carregou em cada provedor de IA para acompanhar o saldo restante.' }}
                    </p>
                    <button
                        v-if="permissions?.create_topup"
                        type="button"
                        class="btn btn-primary btn-sm flex-shrink-0"
                        @click="openTopupModal()"
                    >
                        <i class="ti ti-plus me-1"></i>
                        {{ t?.actions?.create_topup ?? 'Registrar recarga no provedor' }}
                    </button>
                </div>

                <ProviderCostsCard
                    v-if="providerCosts"
                    :costs="providerCosts"
                    :permissions="permissions"
                    :t="t"
                    @add-topup="openTopupModal"
                />
                <div v-else class="alert alert-info small mb-0">
                    {{ t?.providers_empty ?? 'Registre uma recarga para acompanhar o saldo dos provedores.' }}
                </div>
            </div>
        </div>

        <!-- ─── Drawer de detalhe ─────────────────────────────────────────── -->
        <AiCreditPurchaseDetailDrawer
            v-if="drawerOpen"
            :open="drawerOpen"
            :purchase="drawerPurchase"
            :permissions="permissions"
            :t="t"
            @close="closeDetail"
            @credit="onCredit"
            @cancel="onCancel"
            @fail="onMarkFailed"
            @refund="onRefund" />

        <!-- ─── Modal de confirmação com motivo (audit) ──────────────────── -->
        <ConfirmationWithReasonModal
            :open="reasonModal.open"
            :title="reasonModal.title"
            :message="reasonModal.message"
            :confirm-variant="reasonModal.confirmVariant"
            :saving="reasonModal.saving"
            @close="closeReasonModal"
            @confirm="handleReasonConfirm" />

        <!-- ─── Modal de concessão de crédito à clínica ──────────────────── -->
        <ManualCreditModal
            ref="manualModalRef"
            :open="manualModalOpen"
            :entities="entities"
            :permissions="permissions"
            :preset-entity-id="presetEntityId"
            :t="t"
            @close="closeManualModal"
            @submit="submitManual" />

        <!-- ─── Modal de recarga de provedor (lado oferta) ───────────────── -->
        <ProviderTopupModal
            ref="topupModalRef"
            :open="topupModalOpen"
            :preset-provider="presetTopupProvider"
            :t="t"
            @close="closeTopupModal"
            @submit="submitTopup" />
    </AppLayout>
</template>

<style scoped>
.ai-credit-purchases-screen {
    padding: 0.5rem 0;
}
.summary-card {
    border: 1px solid #e2e8f0;
    transition: transform .15s ease, box-shadow .15s ease;
}
.summary-card[role="button"]:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}
.summary-card--providers { border-left: 3px solid var(--bs-primary); }
.summary-card--clients   { border-left: 3px solid var(--bs-success); }
</style>
