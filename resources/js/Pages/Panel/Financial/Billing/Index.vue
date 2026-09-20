<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout      from '@/Layouts/AppLayout.vue';
import PageHeader     from '@/Components/Panel/PageHeader.vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect   from '@/Components/Panel/SearchSelect.vue';

/**
 * Faturamento TISS — listas de:
 *  - Atendimentos elegíveis para faturar (individual ou em lote)
 *  - Guias (claims) já abertas
 *  - Lotes (batches) gerados
 *
 * Emissão real via app/Domains/Tiss (guia -> lote -> pré-validação anti-glosa
 * -> XML versionado -> envio). Convênio sem registro ANS (particular) fica
 * fora do protocolo TISS automaticamente — ver ResolveTissOperatorForCovenantAction.
 */
const props = defineProps({
    breadcrumbs:          { type: Array,  default: () => [] },
    eligibleSchedules:    { type: Array,  default: () => [] },
    claims:               { type: Array,  default: () => [] },
    batches:              { type: Array,  default: () => [] },
    covenants:            { type: Array,  default: () => [] },
    filters:              { type: Object, default: () => ({}) },
    tissVersionOptions:   { type: Array,  default: () => [] },
    tissLayoutOptions:    { type: Array,  default: () => [] },
    selectedTissVersion:  { type: String, default: '202603' },
    selectedTissLayout:   { type: String, default: '04.03.00' },
    storeIndividualUrl:   { type: String, required: true },
    storeBatchUrl:        { type: String, required: true },
    importReturnUrl:      { type: String, required: true },
    glosaReasons:         { type: Array,  default: () => [] },
    tussCodes:            { type: Array,  default: () => [] },
    t:                    { type: Object, default: () => ({}) },
});

const activeTab = ref('eligible');

const covenantsWithOperator = computed(() => props.covenants.filter((c) => c.has_tiss_operator));

function brl(v) {
    return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}

/* ───────────────────────── Filtro ───────────────────────── */
const filterFrom        = ref(props.filters.from);
const filterTo          = ref(props.filters.to);
const filterCovenantId  = ref(props.filters.covenant_id ?? '');
const filterClaimStatus = ref(props.filters.claim_status ?? '');

function applyFilter() {
    router.get(route('panel.financial.billing.index'), {
        from:         filterFrom.value,
        to:           filterTo.value,
        covenant_id:  filterCovenantId.value || undefined,
        claim_status: filterClaimStatus.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

/* ───────────────────────── Seleção em massa (Elegíveis) ───────────────────────── */
const selectedScheduleIds = ref([]);

const allEligibleSelected = computed(() =>
    props.eligibleSchedules.length > 0 && selectedScheduleIds.value.length === props.eligibleSchedules.length,
);

function toggleSelectAll() {
    selectedScheduleIds.value = allEligibleSelected.value
        ? []
        : props.eligibleSchedules.map((s) => s.id);
}

function toggleSchedule(id) {
    const idx = selectedScheduleIds.value.indexOf(id);
    if (idx === -1) {
        selectedScheduleIds.value.push(id);
    } else {
        selectedScheduleIds.value.splice(idx, 1);
    }
}

/* ───────────────────────── Form: faturamento individual ───────────────────────── */
const individualOpen = ref(false);

const individualForm = useForm({
    schedule_id:           '',
    quantity:              1,
    unit_price:            '',
    due_date:              '',
    tuss_code:             '',
    procedure_description: '',
    authorization_code:    '',
    eye_side:              '',
    clinical_indication:   '',
    notes:                 '',
});

const individualSchedule = computed(
    () => props.eligibleSchedules.find((s) => s.id === individualForm.schedule_id) ?? null,
);

function openIndividual(schedule) {
    individualForm.reset();
    individualForm.clearErrors();
    individualForm.schedule_id = schedule.id;
    individualOpen.value = true;
}

function submitIndividual() {
    individualForm.post(props.storeIndividualUrl, {
        preserveScroll: true,
        onSuccess: () => { individualOpen.value = false; },
    });
}

/* ───────────────────────── Form: faturamento em lote ───────────────────────── */
const batchOpen = ref(false);

const batchForm = useForm({
    covenant_id:           '',
    date_from:             props.filters.from,
    date_until:            props.filters.to,
    unit_price:            '',
    tuss_code:             '',
    procedure_description: '',
    clinical_indication:   '',
    due_date:              '',
    tiss_version:          props.selectedTissVersion,
    tiss_layout_version:   props.selectedTissLayout,
    notes:                 '',
});

const selectedBatchCovenant = computed(
    () => props.covenants.find((c) => c.id === batchForm.covenant_id) ?? null,
);

function openBatch() {
    batchForm.reset();
    batchForm.clearErrors();
    batchForm.date_from  = props.filters.from;
    batchForm.date_until = props.filters.to;

    if (selectedScheduleIds.value.length > 0) {
        const first = props.eligibleSchedules.find((s) => s.id === selectedScheduleIds.value[0]);
        if (first?.covenant_id) batchForm.covenant_id = first.covenant_id;
    }

    batchOpen.value = true;
}

function submitBatchForm() {
    batchForm
        .transform((data) => ({ ...data, schedule_ids: [...selectedScheduleIds.value] }))
        .post(props.storeBatchUrl, {
            preserveScroll: true,
            onSuccess: () => {
                batchOpen.value = false;
                selectedScheduleIds.value = [];
            },
        });
}

/* ───────────────────────── Pagar / Glosar guia ───────────────────────── */
function markPaid(claim) {
    if (!window.confirm(props.t.billing?.confirm_mark_paid)) return;
    router.post(claim.mark_paid_url, {}, { preserveScroll: true });
}

const denyOpen   = ref(false);
const denyTarget = ref(null);

const denyForm = useForm({
    glosa_amount: '',
    glosa_code:   '',
    notes:        '',
});

function openDeny(claim) {
    denyForm.reset();
    denyForm.clearErrors();
    denyForm.glosa_amount = claim.amount;
    denyTarget.value = claim;
    denyOpen.value = true;
}

function submitDeny() {
    denyForm.post(denyTarget.value.mark_denied_url, {
        preserveScroll: true,
        onSuccess: () => { denyOpen.value = false; },
    });
}

function onGlosaReasonSelected(reason) {
    if (reason && !denyForm.notes) denyForm.notes = reason.description;
}

function onIndividualTussSelected(tuss) {
    if (tuss) individualForm.procedure_description = tuss.description;
}

function onBatchTussSelected(tuss) {
    if (tuss) batchForm.procedure_description = tuss.description;
}

/* ───────────────────────── Enviar lote ───────────────────────── */
function submitBatchToOperator(batch) {
    router.post(batch.submit_url, {}, { preserveScroll: true });
}

/* ───────────────────────── Importar retorno TISS (XML) ───────────────────────── */
const importReturnOpen = ref(false);

const importReturnForm = useForm({
    covenant_id: '',
    xml_file:    null,
});

function openImportReturn() {
    importReturnForm.reset();
    importReturnForm.clearErrors();
    importReturnOpen.value = true;
}

function onImportFileChange(event) {
    importReturnForm.xml_file = event.target.files[0] ?? null;
}

function submitImportReturn() {
    importReturnForm.post(props.importReturnUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { importReturnOpen.value = false; },
    });
}

/* ───────────────────────── Verificar pendência (pré-validação TISS) ───────────────────────── */
const checkingClaimId   = ref(null);
const pendingResult     = ref(null);
const pendingResultOpen = ref(false);

async function checkPending(claim) {
    if (!claim.pre_validate_url) return;
    checkingClaimId.value = claim.id;
    try {
        const { data } = await window.axios.get(claim.pre_validate_url);
        pendingResult.value = data;
        pendingResultOpen.value = true;
    } catch {
        if (window.showErrorToast) window.showErrorToast(props.t.billing?.pending_result_title);
    } finally {
        checkingClaimId.value = null;
    }
}
</script>

<template>
    <AppLayout :title="t.billing?.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.billing?.title">
                <template #actions>
                    <button type="button" class="btn btn-outline-primary btn-sm" @click="openImportReturn">
                        <i class="ti ti-file-upload me-1"></i>{{ t.billing?.import_return_title }}
                    </button>
                </template>
            </PageHeader>

            <!-- Filtro -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <form class="row g-2 align-items-end" @submit.prevent="applyFilter">
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1">{{ t.period_from }}</label>
                            <input v-model="filterFrom" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1">{{ t.period_to }}</label>
                            <input v-model="filterTo" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">{{ t.billing?.covenant_label }}</label>
                            <select v-model="filterCovenantId" class="form-select form-select-sm">
                                <option value="">{{ t.billing?.all }}</option>
                                <option v-for="c in covenants" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">{{ t.billing?.claim_status_label }}</label>
                            <select v-model="filterClaimStatus" class="form-select form-select-sm">
                                <option value="">{{ t.billing?.all }}</option>
                                <option value="draft">{{ t.billing?.status_draft }}</option>
                                <option value="submitted">{{ t.billing?.status_submitted }}</option>
                                <option value="paid">{{ t.billing?.status_paid }}</option>
                                <option value="denied">{{ t.billing?.status_denied }}</option>
                                <option value="cancelled">{{ t.billing?.status_cancelled }}</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="ti ti-filter me-1"></i>{{ t.filter }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <button :class="['nav-link', { active: activeTab === 'eligible' }]" @click="activeTab = 'eligible'">
                        <i class="ti ti-list-check me-1"></i>
                        {{ t.billing?.eligible_count?.replace(':count', eligibleSchedules.length) }}
                    </button>
                </li>
                <li class="nav-item">
                    <button :class="['nav-link', { active: activeTab === 'claims' }]" @click="activeTab = 'claims'">
                        <i class="ti ti-file-invoice me-1"></i>
                        {{ t.billing?.claims_title }} ({{ claims.length }})
                    </button>
                </li>
                <li class="nav-item">
                    <button :class="['nav-link', { active: activeTab === 'batches' }]" @click="activeTab = 'batches'">
                        <i class="ti ti-package me-1"></i>
                        {{ t.billing?.batches_title }} ({{ batches.length }})
                    </button>
                </li>
            </ul>

            <!-- ELEGÍVEIS -->
            <div v-show="activeTab === 'eligible'" class="card">
                <div class="card-header bg-transparent border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="form-check mb-0">
                        <input
                            id="select-all-eligible"
                            type="checkbox"
                            class="form-check-input"
                            :checked="allEligibleSelected"
                            :disabled="eligibleSchedules.length === 0"
                            @change="toggleSelectAll"
                        >
                        <label class="form-check-label small" for="select-all-eligible">{{ t.billing?.select_all }}</label>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" @click="openBatch">
                        <i class="ti ti-package me-1"></i>
                        {{ selectedScheduleIds.length > 0
                            ? t.billing?.bill_selected_btn?.replace(':count', selectedScheduleIds.length)
                            : t.billing?.new_batch_btn }}
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px;"></th>
                                <th>{{ t.billing?.col_date }}</th>
                                <th>{{ t.billing?.col_patient }}</th>
                                <th>{{ t.billing?.col_doctor }}</th>
                                <th>{{ t.billing?.covenant_label }}</th>
                                <th class="text-end">{{ t.billing?.bill_btn }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="eligibleSchedules.length === 0">
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="ti ti-clipboard-off fs-1 d-block mb-2"></i>
                                    {{ t.billing?.no_eligible }}
                                </td>
                            </tr>
                            <tr v-for="s in eligibleSchedules" :key="s.id">
                                <td>
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        :checked="selectedScheduleIds.includes(s.id)"
                                        :aria-label="`${t.billing?.bill_btn}: ${s.patient_name || ''} — ${s.date_time || ''}`"
                                        @change="toggleSchedule(s.id)"
                                    >
                                </td>
                                <td class="text-muted small">{{ s.date_time }}</td>
                                <td class="fw-medium">{{ s.patient_name || '—' }}</td>
                                <td class="text-muted">{{ s.doctor_name || '—' }}</td>
                                <td>{{ s.covenant_name || t.billing?.no_covenant }}</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" @click="openIndividual(s)">
                                        <i class="ti ti-receipt me-1"></i>{{ t.billing?.bill_btn }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- GUIAS -->
            <div v-show="activeTab === 'claims'" class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.billing?.col_date }}</th>
                                <th>{{ t.billing?.col_patient }}</th>
                                <th>{{ t.billing?.col_doctor }}</th>
                                <th>{{ t.billing?.covenant_label }}</th>
                                <th class="text-center">{{ t.billing?.col_batch }}</th>
                                <th class="text-center">{{ t.billing?.col_status }}</th>
                                <th class="text-end">{{ t.billing?.col_value }}</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="claims.length === 0">
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="ti ti-file-off fs-1 d-block mb-2"></i>
                                    {{ t.billing?.no_claims }}
                                </td>
                            </tr>
                            <tr v-for="c in claims" :key="c.id">
                                <td class="text-muted small">{{ c.created_at }}</td>
                                <td class="fw-medium">{{ c.patient_name || '—' }}</td>
                                <td class="text-muted">{{ c.doctor_name || '—' }}</td>
                                <td>{{ c.covenant_name || t.billing?.no_covenant }}</td>
                                <td class="text-center">
                                    <code v-if="c.batch_id" class="small">{{ String(c.batch_id).substring(0, 8) }}…</code>
                                    <span v-else class="text-muted small">—</span>
                                </td>
                                <td class="text-center">
                                    <span :class="`badge ${c.status_badge} fs-11`">{{ c.status_label }}</span>
                                    <span v-if="c.has_pending_guide" class="badge bg-warning text-dark fs-11 ms-1">
                                        {{ t.billing?.pending_badge }}
                                    </span>
                                </td>
                                <td class="text-end fw-bold">{{ brl(c.amount) }}</td>
                                <td class="text-end">
                                    <button
                                        v-if="c.pre_validate_url"
                                        class="btn btn-sm btn-outline-secondary me-1"
                                        :disabled="checkingClaimId === c.id"
                                        :title="t.billing?.check_pending_btn"
                                        @click="checkPending(c)"
                                    >
                                        <span v-if="checkingClaimId === c.id" class="spinner-border spinner-border-sm"></span>
                                        <i v-else class="ti ti-checklist"></i>
                                    </button>
                                    <button
                                        v-if="c.status !== 'paid' && c.status !== 'cancelled'"
                                        class="btn btn-sm btn-outline-success me-1"
                                        :title="t.billing?.pay_btn"
                                        @click="markPaid(c)"
                                    >
                                        <i class="ti ti-check"></i>
                                    </button>
                                    <button
                                        v-if="c.status !== 'denied' && c.status !== 'cancelled'"
                                        class="btn btn-sm btn-outline-danger"
                                        :title="t.billing?.deny_btn"
                                        @click="openDeny(c)"
                                    >
                                        <i class="ti ti-x"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- LOTES -->
            <div v-show="activeTab === 'batches'" class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.billing?.col_date }}</th>
                                <th>{{ t.billing?.covenant_label }}</th>
                                <th class="text-center">{{ t.billing?.col_guides }}</th>
                                <th class="text-center">{{ t.billing?.col_status }}</th>
                                <th class="text-end">{{ t.billing?.submit_btn }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="batches.length === 0">
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="ti ti-package-off fs-1 d-block mb-2"></i>
                                    {{ t.billing?.no_batches }}
                                </td>
                            </tr>
                            <template v-for="b in batches" :key="b.id">
                                <tr>
                                    <td class="text-muted small">{{ b.created_at }}</td>
                                    <td>
                                        {{ b.covenant_name || t.billing?.no_covenant }}
                                        <span v-if="!b.is_tiss" class="badge badge-soft-secondary ms-1 fs-11">
                                            {{ t.billing?.particular_batch_badge }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-soft-info rounded fs-12">{{ b.claims_count }}</span>
                                    </td>
                                    <td class="text-center">
                                        <span :class="`badge ${b.status_badge} fs-11`">{{ b.status_label }}</span>
                                    </td>
                                    <td class="text-end">
                                        <button
                                            v-if="b.status === 'draft'"
                                            class="btn btn-sm btn-outline-primary me-1"
                                            :title="t.billing?.submit_btn"
                                            @click="submitBatchToOperator(b)"
                                        >
                                            <i class="ti ti-send"></i>
                                        </button>
                                        <a :href="b.xml_url" class="btn btn-sm btn-outline-secondary" :title="t.export">
                                            <i class="ti ti-download"></i>
                                        </a>
                                    </td>
                                </tr>
                                <tr v-if="b.notes">
                                    <td colspan="5" class="small text-muted bg-light-subtle border-top-0 pt-0">
                                        <i class="ti ti-info-circle me-1"></i>{{ b.notes }}
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Painel: Faturamento individual -->
        <OffcanvasPanel :open="individualOpen" :width="520" @close="individualOpen = false">
            <template #header>
                <h5 class="mb-0 fw-semibold"><i class="ti ti-receipt me-2 text-primary"></i>{{ t.billing?.individual_title }}</h5>
            </template>

            <form @submit.prevent="submitIndividual">
                <div class="mb-3">
                    <label class="form-label">{{ t.billing?.attended_schedule }}</label>
                    <input type="text" class="form-control" disabled
                           :value="`${individualSchedule?.patient_name ?? ''} — ${individualSchedule?.date_time ?? ''}`">
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.quantity }}</label>
                        <input v-model.number="individualForm.quantity" type="number" min="1" max="99" class="form-control"
                               :class="{ 'is-invalid': individualForm.errors.quantity }">
                        <div v-if="individualForm.errors.quantity" class="invalid-feedback">{{ individualForm.errors.quantity }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.unit_price }} <span class="text-danger">*</span></label>
                        <input v-model.number="individualForm.unit_price" type="number" step="0.01" min="0" class="form-control"
                               :class="{ 'is-invalid': individualForm.errors.unit_price }">
                        <div v-if="individualForm.errors.unit_price" class="invalid-feedback">{{ individualForm.errors.unit_price }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.tuss_code }}</label>
                        <SearchSelect
                            v-model="individualForm.tuss_code"
                            :options="tussCodes"
                            value-key="code"
                            label-key="label"
                            :placeholder="t.billing?.tuss_code_placeholder"
                            :invalid="!!individualForm.errors.tuss_code"
                            @option-selected="onIndividualTussSelected"
                        />
                        <div v-if="individualForm.errors.tuss_code" class="invalid-feedback d-block">{{ individualForm.errors.tuss_code }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.due_date }}</label>
                        <input v-model="individualForm.due_date" type="date" class="form-control"
                               :class="{ 'is-invalid': individualForm.errors.due_date }">
                        <div v-if="individualForm.errors.due_date" class="invalid-feedback">{{ individualForm.errors.due_date }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.eye_side_label }}</label>
                        <select v-model="individualForm.eye_side" class="form-select"
                                :class="{ 'is-invalid': individualForm.errors.eye_side }">
                            <option value="">{{ t.billing?.eye_side_none }}</option>
                            <option value="OD">{{ t.billing?.eye_side_od }}</option>
                            <option value="OE">{{ t.billing?.eye_side_oe }}</option>
                            <option value="AO">{{ t.billing?.eye_side_ao }}</option>
                        </select>
                        <div v-if="individualForm.errors.eye_side" class="invalid-feedback">{{ individualForm.errors.eye_side }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.billing?.procedure_desc }}</label>
                        <input v-model="individualForm.procedure_description" type="text" maxlength="255" class="form-control"
                               :class="{ 'is-invalid': individualForm.errors.procedure_description }">
                        <div v-if="individualForm.errors.procedure_description" class="invalid-feedback">{{ individualForm.errors.procedure_description }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.billing?.clinical_indication }}</label>
                        <input v-model="individualForm.clinical_indication" type="text" maxlength="10" class="form-control text-uppercase"
                               placeholder="H52.1" :class="{ 'is-invalid': individualForm.errors.clinical_indication }">
                        <div v-if="individualForm.errors.clinical_indication" class="invalid-feedback">{{ individualForm.errors.clinical_indication }}</div>
                        <small class="text-muted">{{ t.billing?.clinical_indication_hint }}</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.billing?.authorization }}</label>
                        <input v-model="individualForm.authorization_code" type="text" maxlength="64" class="form-control"
                               :class="{ 'is-invalid': individualForm.errors.authorization_code }">
                        <div v-if="individualForm.errors.authorization_code" class="invalid-feedback">{{ individualForm.errors.authorization_code }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.billing?.notes_label }}</label>
                        <textarea v-model="individualForm.notes" rows="2" maxlength="2000" class="form-control"
                                  :class="{ 'is-invalid': individualForm.errors.notes }"></textarea>
                        <div v-if="individualForm.errors.notes" class="invalid-feedback">{{ individualForm.errors.notes }}</div>
                    </div>
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-light" @click="individualOpen = false">{{ t.glosas?.cancel_btn }}</button>
                <button type="button" class="btn btn-primary" :disabled="individualForm.processing" @click="submitIndividual">
                    <span v-if="individualForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                    {{ t.billing?.create_individual }}
                </button>
            </template>
        </OffcanvasPanel>

        <!-- Painel: Faturamento em lote -->
        <OffcanvasPanel :open="batchOpen" :width="560" @close="batchOpen = false">
            <template #header>
                <h5 class="mb-0 fw-semibold"><i class="ti ti-package me-2 text-primary"></i>{{ t.billing?.batch_title }}</h5>
            </template>

            <form @submit.prevent="submitBatchForm">
                <div class="alert alert-info small py-2 mb-3">
                    {{ t.billing?.eligible_hint }}
                    <strong>{{ selectedScheduleIds.length }}</strong>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">{{ t.billing?.covenant_label }} <span class="text-danger">*</span></label>
                        <select v-model="batchForm.covenant_id" class="form-select" :class="{ 'is-invalid': batchForm.errors.covenant_id }">
                            <option value="">{{ t.billing?.select }}</option>
                            <option v-for="c in covenants" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <div v-if="batchForm.errors.covenant_id" class="invalid-feedback">{{ batchForm.errors.covenant_id }}</div>
                        <small v-if="selectedBatchCovenant && !selectedBatchCovenant.has_ans_registry" class="text-warning d-block mt-1">
                            <i class="ti ti-alert-triangle me-1"></i>{{ t.billing?.particular_hint }}
                        </small>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.period_from }}</label>
                        <input v-model="batchForm.date_from" type="date" class="form-control" :class="{ 'is-invalid': batchForm.errors.date_from }">
                        <div v-if="batchForm.errors.date_from" class="invalid-feedback">{{ batchForm.errors.date_from }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.period_to }}</label>
                        <input v-model="batchForm.date_until" type="date" class="form-control" :class="{ 'is-invalid': batchForm.errors.date_until }">
                        <div v-if="batchForm.errors.date_until" class="invalid-feedback">{{ batchForm.errors.date_until }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.unit_price }} <span class="text-danger">*</span></label>
                        <input v-model.number="batchForm.unit_price" type="number" step="0.01" min="0" class="form-control"
                               :class="{ 'is-invalid': batchForm.errors.unit_price }">
                        <div v-if="batchForm.errors.unit_price" class="invalid-feedback">{{ batchForm.errors.unit_price }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.tuss_code }}</label>
                        <SearchSelect
                            v-model="batchForm.tuss_code"
                            :options="tussCodes"
                            value-key="code"
                            label-key="label"
                            :placeholder="t.billing?.tuss_code_placeholder"
                            :invalid="!!batchForm.errors.tuss_code"
                            @option-selected="onBatchTussSelected"
                        />
                        <div v-if="batchForm.errors.tuss_code" class="invalid-feedback d-block">{{ batchForm.errors.tuss_code }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.billing?.procedure_desc }}</label>
                        <input v-model="batchForm.procedure_description" type="text" maxlength="255" class="form-control"
                               :class="{ 'is-invalid': batchForm.errors.procedure_description }">
                        <div v-if="batchForm.errors.procedure_description" class="invalid-feedback">{{ batchForm.errors.procedure_description }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.billing?.clinical_indication }}</label>
                        <input v-model="batchForm.clinical_indication" type="text" maxlength="10" class="form-control text-uppercase"
                               placeholder="H52.1" :class="{ 'is-invalid': batchForm.errors.clinical_indication }">
                        <div v-if="batchForm.errors.clinical_indication" class="invalid-feedback">{{ batchForm.errors.clinical_indication }}</div>
                        <small class="text-muted">{{ t.billing?.clinical_indication_hint }}</small>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.tiss_version }}</label>
                        <select v-model="batchForm.tiss_version" class="form-select">
                            <option v-for="v in tissVersionOptions" :key="v" :value="v">{{ v }}</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.tiss_layout }}</label>
                        <select v-model="batchForm.tiss_layout_version" class="form-select">
                            <option v-for="v in tissLayoutOptions" :key="v" :value="v">{{ v }}</option>
                        </select>
                    </div>
                    <div v-if="batchForm.errors.schedule_ids" class="col-12">
                        <div class="alert alert-danger small py-2 mb-0">{{ batchForm.errors.schedule_ids }}</div>
                    </div>
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-light" @click="batchOpen = false">{{ t.glosas?.cancel_btn }}</button>
                <button type="button" class="btn btn-primary" :disabled="batchForm.processing" @click="submitBatchForm">
                    <span v-if="batchForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                    {{ t.billing?.create_batch }}
                </button>
            </template>
        </OffcanvasPanel>

        <!-- Painel: Marcar guia como glosada -->
        <OffcanvasPanel :open="denyOpen" :width="480" @close="denyOpen = false">
            <template #header>
                <h5 class="mb-0 fw-semibold"><i class="ti ti-x me-2 text-danger"></i>{{ t.billing?.mark_denied_title }}</h5>
            </template>

            <form @submit.prevent="submitDeny">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.glosa_amount_label }}</label>
                        <input v-model.number="denyForm.glosa_amount" type="number" step="0.01" min="0" class="form-control"
                               :class="{ 'is-invalid': denyForm.errors.glosa_amount }">
                        <div v-if="denyForm.errors.glosa_amount" class="invalid-feedback">{{ denyForm.errors.glosa_amount }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.billing?.glosa_code_label }}</label>
                        <SearchSelect
                            v-model="denyForm.glosa_code"
                            :options="glosaReasons"
                            value-key="code"
                            label-key="label"
                            :placeholder="t.billing?.glosa_code_placeholder"
                            :invalid="!!denyForm.errors.glosa_code"
                            @option-selected="onGlosaReasonSelected"
                        />
                        <div v-if="denyForm.errors.glosa_code" class="invalid-feedback d-block">{{ denyForm.errors.glosa_code }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.billing?.glosa_notes_label }}</label>
                        <textarea v-model="denyForm.notes" rows="3" maxlength="1000" class="form-control"
                                  :class="{ 'is-invalid': denyForm.errors.notes }"></textarea>
                        <div v-if="denyForm.errors.notes" class="invalid-feedback">{{ denyForm.errors.notes }}</div>
                    </div>
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-light" @click="denyOpen = false">{{ t.glosas?.cancel_btn }}</button>
                <button type="button" class="btn btn-danger" :disabled="denyForm.processing" @click="submitDeny">
                    <span v-if="denyForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                    {{ t.billing?.deny_submit_btn }}
                </button>
            </template>
        </OffcanvasPanel>

        <!-- Painel: Importar retorno TISS -->
        <OffcanvasPanel :open="importReturnOpen" :width="460" @close="importReturnOpen = false">
            <template #header>
                <h5 class="mb-0 fw-semibold"><i class="ti ti-file-upload me-2 text-primary"></i>{{ t.billing?.import_return_title }}</h5>
            </template>

            <form @submit.prevent="submitImportReturn">
                <p class="small text-muted">{{ t.billing?.import_return_hint }}</p>

                <div v-if="covenantsWithOperator.length === 0" class="alert alert-warning small py-2 mb-3">
                    <i class="ti ti-alert-triangle me-1"></i>{{ t.billing?.import_return_no_covenants }}
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ t.billing?.import_return_covenant }} <span class="text-danger">*</span></label>
                    <select
                        v-model="importReturnForm.covenant_id"
                        class="form-select"
                        :disabled="covenantsWithOperator.length === 0"
                        :class="{ 'is-invalid': importReturnForm.errors.covenant_id }"
                    >
                        <option value="">{{ t.billing?.select }}</option>
                        <option v-for="c in covenantsWithOperator" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <div v-if="importReturnForm.errors.covenant_id" class="invalid-feedback">{{ importReturnForm.errors.covenant_id }}</div>
                </div>

                <div class="mb-0">
                    <label class="form-label">{{ t.billing?.import_return_file }} <span class="text-danger">*</span></label>
                    <input
                        type="file" accept=".xml,text/xml,application/xml"
                        class="form-control"
                        :disabled="covenantsWithOperator.length === 0"
                        :class="{ 'is-invalid': importReturnForm.errors.xml_file }"
                        @change="onImportFileChange"
                    >
                    <div v-if="importReturnForm.errors.xml_file" class="invalid-feedback">{{ importReturnForm.errors.xml_file }}</div>
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-light" @click="importReturnOpen = false">{{ t.glosas?.cancel_btn }}</button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="importReturnForm.processing || covenantsWithOperator.length === 0"
                    @click="submitImportReturn"
                >
                    <span v-if="importReturnForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                    {{ t.billing?.import_return_btn }}
                </button>
            </template>
        </OffcanvasPanel>

        <!-- Painel: Resultado da verificação de pendência -->
        <OffcanvasPanel :open="pendingResultOpen" :width="480" @close="pendingResultOpen = false">
            <template #header>
                <h5 class="mb-0 fw-semibold"><i class="ti ti-checklist me-2 text-primary"></i>{{ t.billing?.pending_result_title }}</h5>
            </template>

            <div v-if="pendingResult">
                <p class="small text-muted">{{ pendingResult.summary }}</p>

                <div v-if="pendingResult.errors?.length" class="mb-3">
                    <h6 class="text-danger small fw-semibold">{{ t.billing?.errors_label }}</h6>
                    <div v-for="(e, i) in pendingResult.errors" :key="`err-${i}`" class="alert alert-danger small py-2 mb-2">
                        {{ e.message }}
                        <div v-if="e.suggestion" class="text-muted mt-1">{{ e.suggestion }}</div>
                    </div>
                </div>

                <div v-if="pendingResult.warnings?.length">
                    <h6 class="text-warning small fw-semibold">{{ t.billing?.warnings_label }}</h6>
                    <div v-for="(w, i) in pendingResult.warnings" :key="`warn-${i}`" class="alert alert-warning small py-2 mb-2">
                        {{ w.message }}
                        <div v-if="w.suggestion" class="text-muted mt-1">{{ w.suggestion }}</div>
                    </div>
                </div>

                <p v-if="pendingResult.passes && !pendingResult.errors?.length && !pendingResult.warnings?.length" class="text-success small">
                    <i class="ti ti-circle-check me-1"></i>{{ t.billing?.pending_no_issues }}
                </p>
            </div>

            <template #footer>
                <button type="button" class="btn btn-light" @click="pendingResultOpen = false">{{ t.billing?.close_btn }}</button>
            </template>
        </OffcanvasPanel>
    </AppLayout>
</template>
