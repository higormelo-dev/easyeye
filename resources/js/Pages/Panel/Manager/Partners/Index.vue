<script setup>
import { ref } from 'vue';
import { router }                   from '@inertiajs/vue3';
import AppLayout                    from '@/Layouts/AppLayout.vue';
import PartnerFormModal             from './PartnerFormModal.vue';
import LiveStatusBar                from '@/Components/Panel/LiveStatusBar.vue';
import ActionIconButton             from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup              from '@/Components/Panel/ActionIconGroup.vue';
import ActionDropdown               from '@/Components/Panel/ActionDropdown.vue';
import ConfirmationWithReasonModal  from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';
import { useDashboardPolling }      from '@/composables/useDashboardPolling.js';

const props = defineProps({
    partners:           { type: Array,  default: () => [] },
    funnel:             { type: Array,  default: () => [] },
    kpis:               { type: Object, default: () => ({}) },
    recentCommissions:  { type: Array,  default: () => [] },
    partnerTypes:       { type: Array,  default: () => [] },
    t:                  { type: Object, default: () => ({}) },
});

// ── Polling real-time (15s) ────────────────────────────────────────────────────
const { isRefreshing, lastUpdated, refresh } = useDashboardPolling(
    ['partners', 'kpis', 'funnel', 'recentCommissions'],
    15_000,
);

// ── Form modal ────────────────────────────────────────────────────────────────
const formOpen    = ref(false);
const editId      = ref(null);
const editDataUrl = ref('');
const updateUrl   = ref('');

function openCreate() {
    editId.value      = null;
    editDataUrl.value = '';
    updateUrl.value   = '';
    formOpen.value    = true;
}

function openEdit(partner) {
    editId.value      = partner.id;
    editDataUrl.value = partner.edit_data_url;
    updateUrl.value   = partner.update_url;
    formOpen.value    = true;
}

function closeForm() {
    formOpen.value = false;
    editId.value   = null;
}

// ── Confirmação destrutiva com reason (LGPD/CFM) ──────────────────────────────
const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();

// ── Delete ────────────────────────────────────────────────────────────────────
function onDelete(partner) {
    openReasonModal({
        title: props.t.confirm_delete_title ?? 'Excluir parceiro',
        message: props.t.confirm_delete ?? '',
        confirmVariant: 'danger',
        async onConfirm(reason) {
            const res = await fetch(partner.destroy_url, {
                method: 'DELETE',
                headers: {
                    'Accept':       'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ reason }),
            });
            const json = await res.json();
            showToast(json.message, res.ok ? 'success' : 'error');
            if (res.ok) router.reload({ only: ['partners', 'kpis', 'funnel', 'recentCommissions'] });
        },
    });
}

// ── Pay commission ────────────────────────────────────────────────────────────
function onPay(commission) {
    openReasonModal({
        title: props.t.confirm_pay_title ?? 'Pagar comissão',
        message: props.t.confirm_pay ?? '',
        confirmVariant: 'primary',
        async onConfirm(reason) {
            const res = await fetch(commission.pay_url, {
                method: 'PATCH',
                headers: {
                    'Accept':       'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ reason }),
            });
            const json = await res.json();
            showToast(json.message, res.ok ? 'success' : 'error');
            if (res.ok) router.reload({ only: ['recentCommissions', 'kpis'] });
        },
    });
}

function showToast(msg, type = 'success') {
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
    alert(msg);
}

function fmtBrl(val) {
    return 'R$ ' + Number(val ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 0 });
}

const breadcrumbs = [
    { label: props.t.breadcrumb_home    ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_current ?? 'Parceiros', url: '#', active: true },
];

// ── Export PDF / Excel ───────────────────────────────────────────────────────
// Filtros opcionais (período + status) refletem na URL como query string.
// Backend ignora silenciosamente datas inválidas e exporta tudo se vazias.
const exportFrom = ref('');
const exportTo = ref('');
const exportStatus = ref('');

function exportUrl(format) {
    const base = format === 'pdf'
        ? route('manager.partners.export.pdf')
        : route('manager.partners.export.excel');

    const params = new URLSearchParams();
    if (exportFrom.value)   params.set('from', exportFrom.value);
    if (exportTo.value)     params.set('to', exportTo.value);
    if (exportStatus.value) params.set('status', exportStatus.value);

    const qs = params.toString();
    return qs ? `${base}?${qs}` : base;
}
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div>

            <!-- ── Cabeçalho da página ─────────────────────────────────────── -->
            <div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom flex-wrap">
                <h4 class="mb-0 fw-bold me-auto">{{ t.page_title }}</h4>

                <!-- Dropdown de Exportar (PDF / Excel) com filtros de período + status -->
                <ActionDropdown
                    btn-class="btn btn-outline-primary btn-md fs-13 d-inline-flex align-items-center"
                    :min-width="280"
                    icon="ti ti-download"
                    :title="t.export ?? 'Exportar'"
                >
                    <li class="px-2 pb-2">
                        <div class="small text-muted fw-semibold mb-1">{{ t.export_filters ?? 'Filtros do export' }}</div>
                        <label class="form-label small mb-1">{{ t.export_from ?? 'De' }}</label>
                        <input v-model="exportFrom" type="date" class="form-control form-control-sm mb-2">
                        <label class="form-label small mb-1">{{ t.export_to ?? 'Até' }}</label>
                        <input v-model="exportTo" type="date" class="form-control form-control-sm mb-2">
                        <label class="form-label small mb-1">{{ t.export_status ?? 'Status (comissões)' }}</label>
                        <select v-model="exportStatus" class="form-select form-select-sm">
                            <option value="">{{ t.export_status_all ?? 'Todos' }}</option>
                            <option value="pending">{{ t.export_status_pending ?? 'Pendentes' }}</option>
                            <option value="paid">{{ t.export_status_paid ?? 'Pagas' }}</option>
                            <option value="cancelled">{{ t.export_status_cancelled ?? 'Canceladas' }}</option>
                        </select>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a :href="exportUrl('pdf')" target="_blank" class="dropdown-item rounded-1">
                            <i class="ti ti-file-type-pdf me-1 text-danger"></i> {{ t.export_pdf ?? 'Exportar PDF' }}
                        </a>
                    </li>
                    <li>
                        <a :href="exportUrl('excel')" class="dropdown-item rounded-1">
                            <i class="ti ti-file-type-xls me-1 text-success"></i> {{ t.export_excel ?? 'Exportar Excel' }}
                        </a>
                    </li>
                </ActionDropdown>

                <button type="button" class="btn btn-primary btn-md fs-13" @click="openCreate">
                    <i class="ti ti-plus me-1"></i>{{ t.new }}
                </button>
            </div>

            <!-- ── Live status bar ────────────────────────────────────────── -->
            <LiveStatusBar
                :is-refreshing="isRefreshing"
                :last-updated="lastUpdated"
                :t="t"
                @refresh="refresh"
            />

            <!-- ── KPI Cards ───────────────────────────────────────────────── -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card card-body h-100 border-start border-primary border-3 ps-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-primary-subtle" style="width:42px;height:42px;">
                                <i class="ti ti-affiliate fs-18 text-primary"></i>
                            </div>
                            <div>
                                <div class="fs-22 fw-bold lh-1">{{ kpis.partners ?? 0 }}</div>
                                <div class="text-muted small">{{ t.kpi_partners }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-body h-100 border-start border-info border-3 ps-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-info-subtle" style="width:42px;height:42px;">
                                <i class="ti ti-target-arrow fs-18 text-info"></i>
                            </div>
                            <div>
                                <div class="fs-22 fw-bold lh-1">{{ kpis.leads ?? 0 }}</div>
                                <div class="text-muted small">{{ t.kpi_leads }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-body h-100 border-start border-warning border-3 ps-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-warning-subtle" style="width:42px;height:42px;">
                                <i class="ti ti-cash fs-18 text-warning"></i>
                            </div>
                            <div>
                                <div class="fs-18 fw-bold lh-1">{{ fmtBrl(kpis.pending) }}</div>
                                <div class="text-muted small">{{ t.kpi_pending }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-body h-100 border-start border-success border-3 ps-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-success-subtle" style="width:42px;height:42px;">
                                <i class="ti ti-circle-check fs-18 text-success"></i>
                            </div>
                            <div>
                                <div class="fs-18 fw-bold lh-1">{{ fmtBrl(kpis.paid) }}</div>
                                <div class="text-muted small">{{ t.kpi_paid }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Funil de Leads ──────────────────────────────────────────── -->
            <div class="card mb-4">
                <div class="card-header fw-semibold py-2">
                    <i class="ti ti-filter me-2 text-muted"></i>{{ t.lead_funnel }}
                </div>
                <div class="card-body">
                    <div class="row g-3 text-center">
                        <div v-for="stage in funnel" :key="stage.value" class="col">
                            <div class="p-3 rounded border">
                                <div class="fw-bold mb-2" style="font-size:1.75rem;">{{ stage.count }}</div>
                                <span class="badge" :class="stage.badge">{{ stage.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Tabela de Parceiros ─────────────────────────────────────── -->
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between py-2">
                    <span class="fw-semibold"><i class="ti ti-affiliate me-2 text-muted"></i>{{ t.page_title }}</span>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>{{ t.new }}
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.col_partner }}</th>
                                <th>{{ t.col_type }}</th>
                                <th class="text-center">{{ t.col_leads }}</th>
                                <th class="text-center">{{ t.col_commissions }}</th>
                                <th class="text-end">{{ t.col_pending }}</th>
                                <th class="text-end">{{ t.col_paid }}</th>
                                <th class="text-end" style="min-width:110px;">{{ t.col_actions }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="partners.length === 0">
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="ti ti-affiliate fs-1 d-block mb-2 opacity-25"></i>
                                    {{ t.empty }}
                                    <button type="button" class="btn btn-link p-0 ms-1" @click="openCreate">
                                        {{ t.empty_cta }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-for="p in partners" :key="p.id">
                                <td>
                                    <div class="fw-semibold">{{ p.name }}</div>
                                    <div class="text-muted small">{{ p.email }}</div>
                                </td>
                                <td>
                                    <span class="badge badge-soft-secondary rounded fs-12">{{ p.type_label }}</span>
                                </td>
                                <td class="text-center">{{ p.leads_count }}</td>
                                <td class="text-center">{{ p.commissions_count }}</td>
                                <td class="text-end fw-semibold text-warning">{{ p.pending_fmt }}</td>
                                <td class="text-end fw-semibold text-success">{{ p.paid_fmt }}</td>
                                <td class="text-end">
                                    <ActionIconGroup align="end" gap="tight">
                                        <ActionIconButton
                                            icon="ti ti-eye"
                                            :title="t.view"
                                            variant="info"
                                            :href="p.show_url"
                                        />
                                        <ActionIconButton
                                            icon="ti ti-edit"
                                            :title="t.edit"
                                            @click="openEdit(p)"
                                        />
                                        <ActionIconButton
                                            icon="ti ti-trash"
                                            :title="t.delete"
                                            variant="danger"
                                            @click="onDelete(p)"
                                        />
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Últimas Comissões ───────────────────────────────────────── -->
            <div v-if="recentCommissions.length > 0" class="card mb-4">
                <div class="card-header fw-semibold py-2">
                    <i class="ti ti-cash me-2 text-muted"></i>{{ t.recent_commissions }}
                </div>
                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.col_partner }}</th>
                                <th>{{ t.col_clinic }}</th>
                                <th class="text-end">{{ t.col_value }}</th>
                                <th class="text-center">{{ t.col_status }}</th>
                                <th class="text-center">{{ t.col_due }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in recentCommissions" :key="c.id">
                                <td class="fw-semibold">{{ c.partner_name }}</td>
                                <td class="text-muted">{{ c.entity_name }}</td>
                                <td class="text-end fw-semibold">{{ c.amount_fmt }}</td>
                                <td class="text-center">
                                    <span class="badge" :class="c.status_badge">{{ c.status_label }}</span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex align-items-center justify-content-center gap-2">
                                        <span>{{ c.due_at ?? '—' }}</span>
                                        <button
                                            v-if="c.is_pending"
                                            class="btn btn-xs btn-success"
                                            @click="onPay(c)"
                                        >{{ t.pay }}</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Form offcanvas -->
        <PartnerFormModal
            :open="formOpen"
            :partner-id="editId"
            :edit-data-url="editDataUrl"
            :update-url="updateUrl"
            :partner-types="partnerTypes"
            :t="t"
            @close="closeForm"
            @saved="closeForm"
        />

        <!-- Confirmação destrutiva com justificativa (LGPD/CFM) -->
        <ConfirmationWithReasonModal
            :open="reasonModal.open"
            :title="reasonModal.title"
            :message="reasonModal.message"
            :confirm-variant="reasonModal.confirmVariant"
            :saving="reasonModal.saving"
            @close="closeReasonModal"
            @confirm="handleReasonConfirm"
        />

    </AppLayout>
</template>
