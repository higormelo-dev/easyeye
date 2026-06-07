<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    doctors:     { type: Array,  default: () => [] },
    filters:     { type: Object, default: () => ({}) },
    schedules:   { type: Array,  default: () => [] },
    summary:     { type: Object, default: null },
});

const form = ref({
    date_from:  props.filters.date_from  || '',
    date_until: props.filters.date_until || '',
    doctor_id:  props.filters.doctor_id  || '',
});

function applyFilter() {
    router.get(route('panel.reports.absenteeism'), form.value, { preserveState: true, preserveScroll: true });
}

const hasResults = computed(() => props.summary !== null);
</script>

<template>
    <AppLayout title="Relatório de Absenteísmo" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Relatório de Absenteísmo" />

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <form @submit.prevent="applyFilter" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">De *</label>
                            <input v-model="form.date_from" type="date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Até *</label>
                            <input v-model="form.date_until" type="date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Médico</label>
                            <SearchSelect v-model="form.doctor_id" :options="doctors" :placeholder="'Todos'" />
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="ti ti-filter me-1"></i>Filtrar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <template v-if="hasResults">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="card h-100"><div class="card-body py-3">
                            <small class="text-muted d-block">Total ausentes</small>
                            <div class="fw-bold fs-5 text-danger">{{ summary.total_absent }}</div>
                        </div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100"><div class="card-body py-3">
                            <small class="text-muted d-block">Não comparecimentos</small>
                            <div class="fw-bold fs-5 text-warning">{{ summary.noshow }}</div>
                        </div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100"><div class="card-body py-3">
                            <small class="text-muted d-block">Cancelados</small>
                            <div class="fw-bold fs-5 text-secondary">{{ summary.cancelled }}</div>
                        </div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100"><div class="card-body py-3">
                            <small class="text-muted d-block">Taxa de absenteísmo</small>
                            <div class="fw-bold fs-5 text-danger">{{ summary.absenteeism_rate }}%</div>
                        </div></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-transparent"><h6 class="mb-0">Detalhe</h6></div>
                    <div class="table-responsive">
                        <table class="table table-nowrap table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Data/hora</th>
                                    <th>Paciente</th>
                                    <th>Médico</th>
                                    <th>Convênio</th>
                                    <th class="text-center">Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="schedules.length === 0">
                                    <td colspan="5" class="text-center text-muted py-5">Nenhum registro.</td>
                                </tr>
                                <tr v-for="(s, i) in schedules" :key="i">
                                    <td class="text-muted small">{{ s.date_time }}</td>
                                    <td class="fw-medium">{{ s.patient_name || '—' }}</td>
                                    <td>{{ s.doctor_name || '—' }}</td>
                                    <td class="text-muted small">{{ s.covenant || '—' }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-soft-warning rounded fs-11">{{ s.situation_label }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <div v-else class="alert alert-info">
                <i class="ti ti-info-circle me-1"></i>
                Informe o período para gerar o relatório.
            </div>
        </div>
    </AppLayout>
</template>
