<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import DoctorMissingAlert from '@/Components/Panel/DoctorMissingAlert.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import MedicalRecordDetailDrawer from './MedicalRecordDetailDrawer.vue';

/**
 * "Prontuários" no menu — busca geral (MedicalRecordsSearchController).
 *
 * Filtros na URL (paciente/código, médico, período, assinado). O servidor
 * aplica o escopo: médico vê só os prontuários dele. A lista não traz
 * conteúdo clínico; "Ver" abre o mesmo drawer do histórico do paciente (o
 * show registra o acesso).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    records: { type: Object, default: null },
    doctors: { type: Array, default: () => [] },
    isDoctor: { type: Boolean, default: false },
    doctorMissing: { type: Boolean, default: false },
    doctorMissingText: { type: String, default: '' },
    // actions.medical_records (drawer) e medical_record_search (esta tela)
    t: { type: Object, default: () => ({}) },
    ts: { type: Object, required: true },
});

const form = ref({
    q: props.filters.q ?? '',
    doctor: props.filters.doctor ?? '',
    from: props.filters.from ?? '',
    until: props.filters.until ?? '',
    signed: props.filters.signed ?? '',
});

const rows = computed(() => props.records?.data ?? []);

function params() {
    const query = { ...form.value };
    if (props.isDoctor) delete query.doctor; // escopo do médico é do servidor
    return Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}

function apply() {
    router.get(route('panel.medical-records.index'), params(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clear() {
    form.value = { q: '', doctor: props.isDoctor ? form.value.doctor : '', from: '', until: '', signed: '' };
    apply();
}

const hasFilters = computed(() =>
    Boolean(
        form.value.q ||
        form.value.from ||
        form.value.until ||
        form.value.signed ||
        (!props.isDoctor && form.value.doctor),
    ),
);

const tx = (key, params = {}) =>
    Object.entries(params).reduce(
        (text, [name, value]) => text.replace(`:${name}`, String(value)),
        props.ts[key] ?? key,
    );

// ── Drawer de detalhes (mesmo do histórico do paciente) ──────────────────
const detailOpen = ref(false);
const detailRecord = ref(null);

function openDetail(row) {
    detailRecord.value = row;
    detailOpen.value = true;
}
</script>

<template>
    <Head :title="ts.title" />

    <AppLayout :title="ts.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="ts.title" :subtitle="isDoctor ? ts.subtitle_mine : ts.subtitle" />

            <DoctorMissingAlert v-if="doctorMissing" :text="doctorMissingText" />

            <template v-else>
                <form class="card border-0 shadow-sm mb-3" role="search" @submit.prevent="apply">
                    <div class="card-body py-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-lg-4">
                                <label class="form-label small mb-1" for="mrs-q">{{ ts.search_label }}</label>
                                <input
                                    id="mrs-q"
                                    v-model="form.q"
                                    type="search"
                                    class="form-control"
                                    :placeholder="ts.search_ph"
                                    maxlength="100"
                                    data-testid="mrs-q"
                                />
                            </div>
                            <div v-if="!isDoctor" class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label small mb-1" for="mrs-doctor">{{ ts.doctor_label }}</label>
                                <select
                                    id="mrs-doctor"
                                    v-model="form.doctor"
                                    class="form-select"
                                    data-testid="mrs-doctor"
                                >
                                    <option value="">{{ ts.doctor_all }}</option>
                                    <option v-for="d in doctors" :key="d.id" :value="d.id">
                                        {{ d.name }}<template v-if="!d.active"> {{ ts.doctor_inactive }}</template>
                                    </option>
                                </select>
                            </div>
                            <div class="col-6 col-sm-3 col-lg-auto">
                                <label class="form-label small mb-1" for="mrs-from">{{ ts.from_label }}</label>
                                <input id="mrs-from" v-model="form.from" type="date" class="form-control" />
                            </div>
                            <div class="col-6 col-sm-3 col-lg-auto">
                                <label class="form-label small mb-1" for="mrs-until">{{ ts.until_label }}</label>
                                <input
                                    id="mrs-until"
                                    v-model="form.until"
                                    type="date"
                                    class="form-control"
                                    :min="form.from || undefined"
                                />
                            </div>
                            <div class="col-12 col-sm-6 col-lg-auto">
                                <label class="form-label small mb-1" for="mrs-signed">{{ ts.signed_label }}</label>
                                <select
                                    id="mrs-signed"
                                    v-model="form.signed"
                                    class="form-select"
                                    data-testid="mrs-signed"
                                >
                                    <option value="">{{ ts.signed_all }}</option>
                                    <option value="yes">{{ ts.signed_yes }}</option>
                                    <option value="no">{{ ts.signed_no }}</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-auto d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1" data-testid="mrs-submit">
                                    <i class="ti ti-search me-1" aria-hidden="true"></i>{{ ts.filter }}
                                </button>
                                <button v-if="hasFilters" type="button" class="btn btn-light" @click="clear">
                                    {{ ts.clear }}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <div v-if="!rows.length" class="card">
                    <div class="card-body text-center py-5" data-testid="mrs-empty">
                        <i class="ti ti-report-medical fs-1 text-muted d-block mb-2" aria-hidden="true"></i>
                        <p class="fw-semibold mb-1">{{ ts.empty }}</p>
                        <p v-if="hasFilters" class="text-muted small mb-0">{{ ts.empty_hint }}</p>
                    </div>
                </div>

                <div v-else class="card">
                    <ul class="list-group list-group-flush mrs-list">
                        <li v-for="row in rows" :key="row.id" class="list-group-item mrs-row" data-testid="mrs-row">
                            <div class="mrs-when">
                                <span class="fw-semibold">{{ row.created_at }}</span>
                                <span class="small text-muted">{{ row.code }}</span>
                            </div>
                            <div class="mrs-who">
                                <span class="fw-semibold mrs-name">{{ row.patient_name }}</span>
                                <span class="small text-muted">
                                    {{ row.patient_code }}
                                    <template v-if="row.age !== null">
                                        · {{ tx('age_years', { age: row.age }) }}</template
                                    >
                                </span>
                                <span v-if="row.is_urgent_care" class="badge mrs-urgent align-self-start">
                                    <i class="ti ti-urgent me-1" aria-hidden="true"></i>{{ ts.urgent_badge }}
                                </span>
                            </div>
                            <div class="mrs-doctor small">
                                <span class="visually-hidden">{{ ts.col_doctor }}: </span>{{ row.doctor_name ?? '—' }}
                            </div>
                            <div class="mrs-status">
                                <span
                                    v-if="row.is_signed"
                                    class="badge mrs-badge mrs-badge--signed"
                                    :title="tx('signed_at', { date: row.signed_at })"
                                >
                                    <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ ts.status_signed }}
                                </span>
                                <span v-else class="badge mrs-badge mrs-badge--unsigned">{{ ts.status_unsigned }}</span>
                            </div>
                            <div class="mrs-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-testid="mrs-view"
                                    @click="openDetail(row)"
                                >
                                    <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ ts.action_view }}
                                </button>
                                <Link
                                    v-if="row.edit_url"
                                    :href="row.edit_url"
                                    class="btn btn-sm btn-outline-secondary"
                                    data-testid="mrs-edit"
                                >
                                    <i class="ti ti-pencil" aria-hidden="true"></i
                                    ><span class="visually-hidden">{{ ts.action_edit }}</span>
                                </Link>
                                <Link
                                    :href="row.history_url"
                                    class="btn btn-sm btn-light"
                                    :title="ts.action_history"
                                    :aria-label="`${ts.action_history}: ${row.patient_name}`"
                                >
                                    <i class="ti ti-history" aria-hidden="true"></i>
                                </Link>
                            </div>
                        </li>
                    </ul>
                </div>

                <TablePagination
                    v-if="records"
                    :data="records"
                    :showing-from="ts.showing_from"
                    :showing-of="ts.showing_of"
                    :showing-suffix="ts.showing_suffix"
                    :aria-label="ts.pagination"
                    :previous-label="ts.previous"
                    :next-label="ts.next"
                />
            </template>
        </div>

        <MedicalRecordDetailDrawer
            :open="detailOpen"
            :record="detailRecord"
            :patient="{ id: detailRecord?.patient_id ?? '' }"
            :can-sign="isDoctor"
            :t="t"
            @close="detailOpen = false"
        />
    </AppLayout>
</template>

<style scoped>
.mrs-row {
    display: grid;
    /* Colunas fixas para status/ações: as linhas ficam alinhadas entre si. */
    grid-template-columns: 9.5rem minmax(0, 1.6fr) minmax(0, 1fr) 7.5rem 13rem;
    gap: 0.5rem 1rem;
    align-items: center;
}

.mrs-when,
.mrs-who {
    display: grid;
    min-width: 0;
}

.mrs-name {
    overflow-wrap: anywhere;
}

.mrs-actions {
    display: flex;
    gap: 0.35rem;
    justify-content: flex-end;
}

.mrs-badge--signed {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.mrs-badge--unsigned {
    background: var(--bs-secondary-bg);
    color: var(--bs-body-color);
    border: 1px solid var(--bs-border-color);
}

.mrs-urgent {
    justify-self: start;
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
    margin-top: 0.15rem;
}

@media (max-width: 991.98px) {
    .mrs-row {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .mrs-when {
        grid-column: 1 / -1;
        grid-auto-flow: column;
        justify-content: start;
        gap: 0.5rem;
        align-items: baseline;
    }

    .mrs-doctor {
        grid-column: 1;
    }

    .mrs-actions {
        grid-column: 1 / -1;
        justify-content: flex-start;
    }
}

[data-bs-theme='dark'] .mrs-badge--signed {
    background: rgba(34, 197, 94, 0.18);
    color: #bbf7d0;
    border-color: rgba(74, 222, 128, 0.4);
}

[data-bs-theme='dark'] .mrs-urgent {
    background: rgba(239, 68, 68, 0.2);
    color: #fecaca;
    border-color: rgba(248, 113, 113, 0.5);
}
</style>
