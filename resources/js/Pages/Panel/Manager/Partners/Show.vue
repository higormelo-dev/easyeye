<script setup>
import { ref, computed } from 'vue';
import { router, Link }             from '@inertiajs/vue3';
import AppLayout                    from '@/Layouts/AppLayout.vue';
import PartnerFormModal             from './PartnerFormModal.vue';
import LiveStatusBar                from '@/Components/Panel/LiveStatusBar.vue';
import { useDashboardPolling }      from '@/composables/useDashboardPolling.js';

const props = defineProps({
    partner:      { type: Object, required: true },
    leads:        { type: Object, required: true },
    commissions:  { type: Object, required: true },
    leadStatuses: { type: Array,  default: () => [] },
    partnerTypes: { type: Array,  default: () => [] },
    t:            { type: Object, default: () => ({}) },
});

// ── Polling real-time (15s) ────────────────────────────────────────────────────
const { isRefreshing, lastUpdated, refresh } = useDashboardPolling(
    ['leads', 'commissions', 'partner'],
    15_000,
);

// ── Tabs ──────────────────────────────────────────────────────────────────────
const activeTab = ref('leads');

// ── Edit modal ────────────────────────────────────────────────────────────────
const formOpen = ref(false);

// ── Advance lead ──────────────────────────────────────────────────────────────
const advancingId = ref(null);

async function advanceLead(lead, newStatus) {
    if (!newStatus || !confirm(props.t.confirm_advance)) return;

    advancingId.value = lead.id;
    try {
        const res = await fetch(lead.advance_url, {
            method: 'PATCH',
            headers: {
                'Content-Type':  'application/json',
                'Accept':        'application/json',
                'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ status: newStatus }),
        });
        const json = await res.json();
        showToast(json.message, res.ok ? 'success' : 'error');
        if (res.ok) router.reload({ only: ['leads', 'partner'] });
    } finally {
        advancingId.value = null;
    }
}

// ── Pay commission ────────────────────────────────────────────────────────────
const payingId = ref(null);

async function payCommission(commission) {
    if (!confirm(props.t.confirm_pay)) return;

    payingId.value = commission.id;
    try {
        const res = await fetch(commission.pay_url, {
            method: 'PATCH',
            headers: {
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
        const json = await res.json();
        showToast(json.message, res.ok ? 'success' : 'error');
        if (res.ok) router.reload({ only: ['commissions', 'partner'] });
    } finally {
        payingId.value = null;
    }
}

// ── Pagination ────────────────────────────────────────────────────────────────
function goLeadsPage(page) {
    router.get(
        route('panel.manager.partners.show', props.partner.id),
        { leads_page: page, commissions_page: props.commissions.meta?.current_page ?? 1 },
        { preserveState: true, preserveScroll: true },
    );
    activeTab.value = 'leads';
}

function goCommissionsPage(page) {
    router.get(
        route('panel.manager.partners.show', props.partner.id),
        { leads_page: props.leads.meta?.current_page ?? 1, commissions_page: page },
        { preserveState: true, preserveScroll: true },
    );
    activeTab.value = 'commissions';
}

// ── Copy token ────────────────────────────────────────────────────────────────
const tokenCopied = ref(false);
function copyToken() {
    navigator.clipboard.writeText(props.partner.token).then(() => {
        tokenCopied.value = true;
        setTimeout(() => { tokenCopied.value = false; }, 2000);
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
    alert(msg);
}

const breadcrumbs = computed(() => [
    { label: props.t.breadcrumb_home    ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_current ?? 'Parceiros', url: route('panel.manager.partners.index'), active: false },
    { label: props.partner.name,                        url: '#', active: true },
]);
</script>

<template>
    <AppLayout :title="partner.name" :breadcrumbs="breadcrumbs">
        <div>

            <!-- ── Live status bar ────────────────────────────────────────── -->
            <LiveStatusBar
                :is-refreshing="isRefreshing"
                :last-updated="lastUpdated"
                :t="t"
                @refresh="refresh"
            />

            <!-- ── Cabeçalho ───────────────────────────────────────────────── -->
            <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-4 border-bottom">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h4 class="fw-bold mb-0">{{ partner.name }}</h4>
                        <span class="badge" :class="partner.status_badge">{{ partner.status_label }}</span>
                    </div>
                    <div class="text-muted small mt-1">{{ partner.email }}</div>
                </div>
                <div class="d-flex gap-2">
                    <a :href="route('panel.manager.partners.index')" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-arrow-left me-1"></i>{{ t.back }}
                    </a>
                    <button type="button" class="btn btn-primary btn-sm" @click="formOpen = true">
                        <i class="ti ti-edit me-1"></i>{{ t.edit }}
                    </button>
                </div>
            </div>

            <div class="row g-4">

                <!-- ── Coluna lateral: informações ─────────────────────────── -->
                <div class="col-12 col-md-4">
                    <div class="card h-100">
                        <div class="card-header fw-semibold py-2">
                            <i class="ti ti-info-circle me-2 text-muted"></i>{{ t.sidebar_info }}
                        </div>
                        <div class="card-body">

                            <!-- Mini KPIs -->
                            <div class="row g-2 mb-4">
                                <div class="col-6">
                                    <div class="p-3 rounded border text-center">
                                        <div class="fw-bold fs-20 lh-1 mb-1">{{ partner.leads_total }}</div>
                                        <div class="text-muted small">{{ t.label_leads }}</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="p-3 rounded border text-center">
                                        <div class="fw-bold fs-20 lh-1 mb-1">{{ partner.commissions_total }}</div>
                                        <div class="text-muted small">{{ t.label_commissions }}</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Dados -->
                            <ul class="list-unstyled mb-0 small">
                                <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <span class="text-muted">{{ t.label_type }}</span>
                                    <span class="badge badge-soft-secondary rounded fs-12">{{ partner.type_label }}</span>
                                </li>
                                <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <span class="text-muted">{{ t.label_status }}</span>
                                    <span class="badge" :class="partner.status_badge">{{ partner.status_label }}</span>
                                </li>
                                <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <span class="text-muted">{{ t.label_commission }}</span>
                                    <span class="fw-semibold">{{ partner.commission_rate }}%</span>
                                </li>
                                <li v-if="partner.document" class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <span class="text-muted">{{ t.label_document }}</span>
                                    <span>{{ partner.document }}</span>
                                </li>
                                <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <span class="text-muted">{{ t.label_token }}</span>
                                    <div class="d-flex align-items-center gap-1">
                                        <code class="small">{{ partner.token }}</code>
                                        <button
                                            type="button"
                                            class="btn btn-xs btn-outline-secondary"
                                            :title="t.copy"
                                            @click="copyToken"
                                        >
                                            <i :class="tokenCopied ? 'ti ti-check text-success' : 'ti ti-copy'"></i>
                                        </button>
                                    </div>
                                </li>
                                <li class="d-flex justify-content-between align-items-center py-2">
                                    <span class="text-muted">{{ t.label_registered }}</span>
                                    <span>{{ partner.created_at }}</span>
                                </li>
                            </ul>

                            <!-- Notas -->
                            <div v-if="partner.notes" class="mt-3 p-3 rounded bg-light small text-muted">
                                <i class="ti ti-notes me-1"></i>{{ partner.notes }}
                            </div>

                        </div>
                    </div>
                </div>

                <!-- ── Coluna principal: tabs ──────────────────────────────── -->
                <div class="col-12 col-md-8">
                    <div class="card">
                        <!-- Tabs header -->
                        <div class="card-header p-0">
                            <ul class="nav nav-tabs border-bottom-0 px-3 pt-1">
                                <li class="nav-item">
                                    <button
                                        class="nav-link fw-semibold"
                                        :class="{ active: activeTab === 'leads' }"
                                        @click="activeTab = 'leads'"
                                    >
                                        <i class="ti ti-target-arrow me-1"></i>{{ t.tab_leads }}
                                        <span class="badge badge-soft-secondary rounded ms-1 fs-12">{{ leads.meta?.total ?? 0 }}</span>
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button
                                        class="nav-link fw-semibold"
                                        :class="{ active: activeTab === 'commissions' }"
                                        @click="activeTab = 'commissions'"
                                    >
                                        <i class="ti ti-cash me-1"></i>{{ t.tab_commissions }}
                                        <span class="badge badge-soft-secondary rounded ms-1 fs-12">{{ commissions.meta?.total ?? 0 }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- ── Leads tab ─────────────────────────────────── -->
                        <div v-show="activeTab === 'leads'">
                            <div class="table-responsive">
                                <table class="table table-nowrap align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>{{ t.col_name }}</th>
                                            <th>{{ t.col_city }}</th>
                                            <th class="text-center">{{ t.col_status }}</th>
                                            <th class="text-center" style="min-width:140px;">{{ t.col_advance }}</th>
                                            <th class="text-center">{{ t.col_date }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="leads.data?.length === 0">
                                            <td colspan="5" class="text-center text-muted py-5">
                                                <i class="ti ti-target-arrow fs-1 d-block mb-2 opacity-25"></i>
                                                {{ t.no_leads }}
                                            </td>
                                        </tr>
                                        <tr v-for="lead in leads.data" :key="lead.id">
                                            <td>
                                                <div class="fw-semibold">{{ lead.name }}</div>
                                                <div class="text-muted small">{{ lead.email }}</div>
                                            </td>
                                            <td class="text-muted small">{{ lead.city_state ?? '—' }}</td>
                                            <td class="text-center">
                                                <span class="badge" :class="lead.status_badge">{{ lead.status_label }}</span>
                                            </td>
                                            <td class="text-center">
                                                <select
                                                    v-if="lead.is_active"
                                                    class="form-select form-select-sm"
                                                    style="min-width:130px;"
                                                    :disabled="advancingId === lead.id"
                                                    @change="advanceLead(lead, $event.target.value); $event.target.value = ''"
                                                >
                                                    <option value="">{{ t.field_advance }}</option>
                                                    <option
                                                        v-for="s in leadStatuses.filter(s => s.value !== lead.status)"
                                                        :key="s.value"
                                                        :value="s.value"
                                                    >{{ s.label }}</option>
                                                </select>
                                                <span v-else class="text-muted small">—</span>
                                            </td>
                                            <td class="text-center text-muted small">{{ lead.created_at }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Leads pagination -->
                            <div v-if="leads.meta?.last_page > 1" class="d-flex justify-content-center py-3 gap-1">
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    :disabled="leads.meta.current_page === 1"
                                    @click="goLeadsPage(leads.meta.current_page - 1)"
                                ><i class="ti ti-arrow-left"></i></button>
                                <button
                                    v-for="p in leads.meta.last_page"
                                    :key="p"
                                    class="btn btn-sm"
                                    :class="p === leads.meta.current_page ? 'btn-primary' : 'btn-outline-secondary'"
                                    @click="goLeadsPage(p)"
                                >{{ p }}</button>
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    :disabled="leads.meta.current_page === leads.meta.last_page"
                                    @click="goLeadsPage(leads.meta.current_page + 1)"
                                ><i class="ti ti-arrow-right"></i></button>
                            </div>
                        </div>

                        <!-- ── Commissions tab ───────────────────────────── -->
                        <div v-show="activeTab === 'commissions'">
                            <div class="table-responsive">
                                <table class="table table-nowrap align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>{{ t.col_clinic }}</th>
                                            <th class="text-end">{{ t.col_value }}</th>
                                            <th class="text-center">{{ t.col_rate }}</th>
                                            <th class="text-center">{{ t.col_period }}</th>
                                            <th class="text-center">{{ t.col_status }}</th>
                                            <th class="text-center">{{ t.col_due }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="commissions.data?.length === 0">
                                            <td colspan="6" class="text-center text-muted py-5">
                                                <i class="ti ti-cash fs-1 d-block mb-2 opacity-25"></i>
                                                {{ t.no_commissions }}
                                            </td>
                                        </tr>
                                        <tr v-for="c in commissions.data" :key="c.id">
                                            <td class="fw-semibold">{{ c.entity_name }}</td>
                                            <td class="text-end fw-semibold">{{ c.amount_fmt }}</td>
                                            <td class="text-center text-muted small">{{ c.rate }}%</td>
                                            <td class="text-center text-muted small">{{ c.period }}</td>
                                            <td class="text-center">
                                                <span class="badge" :class="c.status_badge">{{ c.status_label }}</span>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex align-items-center justify-content-center gap-2">
                                                    <span class="small">{{ c.due_at ?? '—' }}</span>
                                                    <button
                                                        v-if="c.is_pending"
                                                        class="btn btn-xs btn-success"
                                                        :disabled="payingId === c.id"
                                                        @click="payCommission(c)"
                                                    >
                                                        <span v-if="payingId === c.id" class="spinner-border spinner-border-sm"></span>
                                                        <span v-else>{{ t.pay }}</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Commissions pagination -->
                            <div v-if="commissions.meta?.last_page > 1" class="d-flex justify-content-center py-3 gap-1">
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    :disabled="commissions.meta.current_page === 1"
                                    @click="goCommissionsPage(commissions.meta.current_page - 1)"
                                ><i class="ti ti-arrow-left"></i></button>
                                <button
                                    v-for="p in commissions.meta.last_page"
                                    :key="p"
                                    class="btn btn-sm"
                                    :class="p === commissions.meta.current_page ? 'btn-primary' : 'btn-outline-secondary'"
                                    @click="goCommissionsPage(p)"
                                >{{ p }}</button>
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    :disabled="commissions.meta.current_page === commissions.meta.last_page"
                                    @click="goCommissionsPage(commissions.meta.current_page + 1)"
                                ><i class="ti ti-arrow-right"></i></button>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <!-- Edit offcanvas -->
        <PartnerFormModal
            :open="formOpen"
            :partner-id="partner.id"
            :edit-data-url="partner.edit_url"
            :update-url="partner.update_url"
            :partner-types="partnerTypes"
            :t="t"
            @close="formOpen = false"
            @saved="formOpen = false; router.reload({ only: ['partner'] })"
        />

    </AppLayout>
</template>
