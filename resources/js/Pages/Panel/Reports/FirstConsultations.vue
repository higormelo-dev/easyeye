<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import DoctorMissingAlert from '@/Components/Panel/DoctorMissingAlert.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Relatório de primeira consulta (ReportsController::firstConsultations):
 * pacientes cuja 1ª consulta ATENDIDA na clínica caiu no período. Filtro por
 * médico (gestão); o médico vê só as dele. Exporta CSV/Excel com o mesmo
 * recorte (servidor registra o acesso).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    doctors: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({ total: 0, by_doctor: [] }) },
    period: { type: Object, default: () => ({}) },
    isDoctor: { type: Boolean, default: false },
    doctorMissing: { type: Boolean, default: false },
    doctorMissingText: { type: String, default: '' },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const { number } = useLocaleFormat();

const form = ref({
    date_from: props.filters.date_from ?? '',
    date_until: props.filters.date_until ?? '',
    doctor_id: props.filters.doctor_id ?? '',
});

function query() {
    return Object.fromEntries(Object.entries(form.value).filter(([, v]) => v !== '' && v !== null));
}

function apply() {
    router.get(route('panel.schedules.reports.first-consultations'), query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function exportUrl(format) {
    return `${props.urls.export}?${new URLSearchParams({ ...query(), format })}`;
}

const periodText = computed(() =>
    (props.t.period ?? ':from — :until')
        .replace(':from', props.period.from ?? '')
        .replace(':until', props.period.until ?? ''),
);
</script>

<template>
    <Head :title="t.title" />

    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.title" :subtitle="isDoctor ? t.subtitle_mine : t.subtitle" />

            <DoctorMissingAlert v-if="doctorMissing" :text="doctorMissingText" />

            <form class="card border-0 shadow-sm mb-3" @submit.prevent="apply">
                <div class="card-body py-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-auto">
                            <label class="form-label small mb-1" for="fc-from">{{ t.from_label }}</label>
                            <input id="fc-from" v-model="form.date_from" type="date" class="form-control" required />
                        </div>
                        <div class="col-6 col-md-auto">
                            <label class="form-label small mb-1" for="fc-until">{{ t.until_label }}</label>
                            <input
                                id="fc-until"
                                v-model="form.date_until"
                                type="date"
                                class="form-control"
                                :min="form.date_from || undefined"
                                required
                            />
                        </div>
                        <div v-if="!isDoctor" class="col-12 col-md-4">
                            <label class="form-label small mb-1" for="fc-doctor">{{ t.doctor_label }}</label>
                            <select id="fc-doctor" v-model="form.doctor_id" class="form-select" data-testid="fc-doctor">
                                <option value="">{{ t.doctor_all }}</option>
                                <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary" data-testid="fc-filter">
                                <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ t.filter }}
                            </button>
                            <a
                                v-if="!doctorMissing"
                                :href="exportUrl('csv')"
                                class="btn btn-outline-secondary"
                                data-testid="fc-export-csv"
                            >
                                <i class="ti ti-download me-1" aria-hidden="true"></i>{{ t.export_csv }}
                            </a>
                            <a
                                v-if="!doctorMissing"
                                :href="exportUrl('xlsx')"
                                class="btn btn-outline-secondary"
                                data-testid="fc-export-xlsx"
                            >
                                <i class="ti ti-file-spreadsheet me-1" aria-hidden="true"></i>{{ t.export_xlsx }}
                            </a>
                        </div>
                    </div>
                    <p class="form-text mb-0 mt-2">{{ t.how_it_counts }}</p>
                </div>
            </form>

            <template v-if="!doctorMissing">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <small class="text-muted d-block">{{ t.total }}</small>
                                <div class="fw-bold fs-3" data-testid="fc-total">{{ number(summary.total) }}</div>
                                <small class="text-muted">{{ periodText }}</small>
                            </div>
                        </div>
                    </div>
                    <div v-if="!isDoctor && summary.by_doctor.length" class="col-12 col-md-8">
                        <div class="card h-100">
                            <div class="card-body">
                                <small class="text-muted d-block mb-2">{{ t.by_doctor }}</small>
                                <ul class="list-unstyled mb-0 fc-by-doctor">
                                    <li
                                        v-for="d in summary.by_doctor"
                                        :key="d.name"
                                        class="d-flex justify-content-between gap-2"
                                    >
                                        <span class="text-truncate">{{ d.name }}</span>
                                        <strong>{{ number(d.total) }}</strong>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 fc-table">
                            <caption class="visually-hidden">
                                {{
                                    t.title
                                }}
                                —
                                {{
                                    periodText
                                }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ t.col_date }}</th>
                                    <th scope="col">{{ t.col_patient }}</th>
                                    <th scope="col">{{ t.col_doctor }}</th>
                                    <th scope="col">{{ t.col_covenant }}</th>
                                    <th scope="col">{{ t.col_type }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!rows.length">
                                    <td colspan="5" class="text-center text-muted py-5" data-testid="fc-empty">
                                        {{ t.empty }}
                                    </td>
                                </tr>
                                <tr v-for="row in rows" :key="row.id" data-testid="fc-row">
                                    <td class="text-nowrap">{{ row.date_time }}</td>
                                    <td>
                                        <span class="fw-medium">{{ row.patient_name }}</span>
                                        <span v-if="row.patient_code" class="d-block small text-muted">{{
                                            row.patient_code
                                        }}</span>
                                    </td>
                                    <td>{{ row.doctor_name }}</td>
                                    <td>{{ row.covenant ?? t.no_covenant }}</td>
                                    <td>
                                        {{ row.type ?? '—' }}
                                        <span v-if="row.is_urgent_care" class="badge fc-urgent ms-1">{{
                                            t.urgent_badge
                                        }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<style scoped>
.fc-by-doctor {
    display: grid;
    gap: 0.25rem;
    max-height: 9rem;
    overflow-y: auto;
}

.fc-urgent {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

[data-bs-theme='dark'] .fc-urgent {
    background: rgba(239, 68, 68, 0.2);
    color: #fecaca;
    border-color: rgba(248, 113, 113, 0.5);
}
</style>
