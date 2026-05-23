<script setup>
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';
import AiCreditPurchaseDetailDrawer from './AiCreditPurchaseDetailDrawer.vue';

/**
 * Manager: gestão de pedidos de compra de créditos IA das clínicas.
 *
 * Autorização granular (props.permissions vinda do backend):
 *   - credit: Admin + Financial
 *   - cancel: Admin + Support
 *   - fail:   Admin + Financial
 *   - refund: Admin only
 *
 * Toda ação destrutiva passa por ConfirmationWithReasonModal — reason vai
 * direto para o audit_log (CFM/LGPD).
 */
const props = defineProps({
    breadcrumbs:   { type: Array,  default: () => [] },
    purchases:     { type: Object, default: () => ({ data: [], meta: {}, links: {} }) },
    kpis:          { type: Object, default: () => ({}) },
    topConsumers:  { type: Array,  default: () => [] },
    filters:       { type: Object, default: () => ({}) },
    statusOptions: { type: Array,  default: () => [] },
    entities:      { type: Array,  default: () => [] },
    permissions:   { type: Object, default: () => ({}) },
    t:             { type: Object, default: () => ({}) },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const filterForm = reactive({
    status:    props.filters.status    ?? '',
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
        only: ['purchases', 'kpis', 'topConsumers', 'filters'],
    });
}

function clearFilters() {
    Object.keys(filterForm).forEach(k => filterForm[k] = '');
    applyFilters();
}

// ── Drawer de detalhe ────────────────────────────────────────────────────────
const drawerOpen = ref(false);
const drawerPurchase = ref(null);

async function openDetail(purchase) {
    drawerPurchase.value = purchase;
    drawerOpen.value = true;
}

function closeDetail() {
    drawerOpen.value = false;
    drawerPurchase.value = null;
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
    // Toast padrão do projeto: aqui usamos alert simples + reload.
    // O projeto tem um sistema de flash; mantemos coerente.
    if (type === 'error') alert(message);
    // success vai aparecer após o reload via flash
}

function reload() {
    router.reload({ only: ['purchases', 'kpis', 'topConsumers'] });
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

// ── Helpers ──────────────────────────────────────────────────────────────────
const hasResults = computed(() => (props.purchases?.data ?? []).length > 0);

function goToPage(url) {
    if (!url) return;
    router.get(url, {}, { preserveScroll: true, preserveState: true, only: ['purchases'] });
}
</script>

<template>
    <AppLayout :title="t?.title ?? 'Compras de créditos IA'" :breadcrumbs="breadcrumbs">
        <div class="ai-credit-purchases-screen">

            <PageHeader
                :title="t?.title ?? 'Compras de créditos IA'"
                :subtitle="t?.subtitle"
                :total="purchases?.meta?.total > 0 ? purchases.meta.total : null" />

            <!-- ─── KPIs ─────────────────────────────────────────────────── -->
            <div class="row g-2 mb-3">
                <!-- Pendentes -->
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card h-100">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted small">{{ t?.kpi?.pending ?? 'Pendentes' }}</span>
                                <i class="ti ti-clock text-warning"></i>
                            </div>
                            <h4 class="mb-0 mt-1">{{ kpis?.pending?.count ?? 0 }}</h4>
                            <small class="text-warning fw-semibold">
                                {{ kpis?.pending?.amount_formatted ?? 'R$ 0,00' }}
                            </small>
                            <div class="small text-muted mt-1">{{ t?.kpi?.pending_help }}</div>
                        </div>
                    </div>
                </div>

                <!-- Creditados 30d -->
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card h-100">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted small">{{ t?.kpi?.credited_30d ?? 'Creditados (30d)' }}</span>
                                <i class="ti ti-coin text-success"></i>
                            </div>
                            <h4 class="mb-0 mt-1">{{ kpis?.credited_30d?.amount_formatted ?? 'R$ 0,00' }}</h4>
                            <small class="text-success fw-semibold">
                                {{ kpis?.credited_30d?.credits_sold ?? 0 }} {{ t?.kpi?.credits_sold ?? 'créditos vendidos' }}
                            </small>
                            <div class="small text-muted mt-1">
                                {{ kpis?.credited_30d?.count ?? 0 }} pedidos
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Conversão -->
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card h-100">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted small">{{ t?.kpi?.conversion ?? 'Conversão (30d)' }}</span>
                                <i class="ti ti-trending-up text-info"></i>
                            </div>
                            <h4 class="mb-0 mt-1">{{ kpis?.funnel_30d?.conversion_pct ?? 0 }}%</h4>
                            <small class="text-info fw-semibold">
                                {{ kpis?.funnel_30d?.credited ?? 0 }} de {{ kpis?.funnel_30d?.total ?? 0 }}
                            </small>
                            <div class="small text-muted mt-1">{{ t?.kpi?.conversion_help }}</div>
                        </div>
                    </div>
                </div>

                <!-- Cancelados/Falhados -->
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card h-100">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted small">{{ t?.kpi?.abandonment ?? 'Abandono (30d)' }}</span>
                                <i class="ti ti-x text-danger"></i>
                            </div>
                            <h4 class="mb-0 mt-1">{{ kpis?.funnel_30d?.abandonment_pct ?? 0 }}%</h4>
                            <small class="text-danger fw-semibold">
                                {{ kpis?.funnel_30d?.cancelled ?? 0 }} cancelados / {{ kpis?.funnel_30d?.failed ?? 0 }} falhas
                            </small>
                            <div class="small text-muted mt-1">do funil dos últimos 30 dias</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Top consumers + Filtros ──────────────────────────────── -->
            <div class="row g-2 mb-3">
                <!-- Top 5 clínicas -->
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
                                            <div class="fw-semibold small">{{ c.entity_name }}</div>
                                            <small class="text-muted">{{ c.purchases_total }} compras</small>
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

                <!-- Filtros -->
                <div class="col-12 col-xl-8">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex align-items-center">
                            <i class="ti ti-filter text-secondary me-2"></i>
                            <strong class="small">Filtros</strong>
                        </div>
                        <div class="card-body p-3">
                            <form @submit.prevent="applyFilters" class="row g-2">
                                <div class="col-12 col-md-3">
                                    <label class="form-label small mb-1">{{ t?.filters?.status ?? 'Status' }}</label>
                                    <select v-model="filterForm.status" class="form-select form-select-sm">
                                        <option value="">{{ t?.filters?.all ?? 'Todos' }}</option>
                                        <option v-for="s in statusOptions" :key="s.value" :value="s.value">
                                            {{ s.label }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small mb-1">{{ t?.filters?.entity ?? 'Clínica' }}</label>
                                    <select v-model="filterForm.entity_id" class="form-select form-select-sm">
                                        <option value="">{{ t?.filters?.all ?? 'Todas' }}</option>
                                        <option v-for="e in entities" :key="e.id" :value="e.id">{{ e.name }}</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small mb-1">{{ t?.filters?.date_from ?? 'De' }}</label>
                                    <input v-model="filterForm.date_from" type="date" class="form-control form-control-sm">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small mb-1">{{ t?.filters?.date_to ?? 'Até' }}</label>
                                    <input v-model="filterForm.date_to" type="date" class="form-control form-control-sm">
                                </div>
                                <div class="col-12 col-md-1 d-flex align-items-end gap-1">
                                    <button type="submit" class="btn btn-sm btn-primary w-100" title="Filtrar">
                                        <i class="ti ti-search"></i>
                                    </button>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="button" class="btn btn-sm btn-link text-muted" @click="clearFilters">
                                        {{ t?.filters?.clear ?? 'Limpar filtros' }}
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
                                <th>{{ t?.columns?.entity ?? 'Clínica' }}</th>
                                <th>{{ t?.columns?.package ?? 'Pacote' }}</th>
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
                                    {{ t?.empty ?? 'Nenhum pedido encontrado.' }}
                                </td>
                            </tr>
                            <tr v-for="p in purchases.data" :key="p.id" @click="openDetail(p)" style="cursor: pointer;">
                                <td class="ps-3">
                                    <div class="small">{{ p.created_at }}</div>
                                </td>
                                <td>
                                    <div class="fw-semibold small">{{ p.entity_name ?? '—' }}</div>
                                </td>
                                <td>
                                    <div class="small">{{ p.package_name }}</div>
                                    <small class="text-muted">{{ p.package_code }}</small>
                                </td>
                                <td class="text-end fw-semibold">{{ p.credits.toLocaleString('pt-BR') }}</td>
                                <td class="text-end fw-semibold">{{ p.amount_formatted }}</td>
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

                <!-- Paginação -->
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
    </AppLayout>
</template>

<style scoped>
.ai-credit-purchases-screen {
    padding: 0.5rem 0;
}
</style>
