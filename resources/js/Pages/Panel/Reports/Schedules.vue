<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    doctors:     { type: Array,  default: () => [] },
    covenants:   { type: Array,  default: () => [] },
    situations:  { type: Array,  default: () => [] },
    filters:     { type: Object, default: () => ({}) },
    schedules:   { type: Array,  default: () => [] },
    summary:     { type: Object, default: null },
    byDoctor:    { type: Array,  default: () => [] },
});

const form = ref({
    date_from:   props.filters.date_from   || '',
    date_until:  props.filters.date_until  || '',
    doctor_id:   props.filters.doctor_id   || '',
    covenant_id: props.filters.covenant_id || '',
    situation:   props.filters.situation   || '',
});

function applyFilter() {
    router.get(route('panel.reports.schedules'), form.value, { preserveState: true, preserveScroll: true });
}

const hasResults = computed(() => props.summary !== null);
</script>

<template>
    <AppLayout title="Relatório de Agenda" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Relatório de Agenda" />

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <form @submit.prevent="applyFilter" class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small mb-1">De *</label>
                            <input v-model="form.date_from" type="date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Até *</label>
                            <input v-model="form.date_until" type="date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Médico</label>
                            <SearchSelect v-model="form.doctor_id" :options="doctors" :placeholder="'Todos'" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Convênio</label>
                            <SearchSelect v-model="form.covenant_id" :options="covenants" :placeholder="'Todos'" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Situação</label>
                            <SearchSelect v-model="form.situation" :options="situations" :value-key="'value'" :label-key="'label'" :placeholder="'Todas'" />
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="ti ti-filter me-1"></i>Filtrar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <template v-if="hasResults">
                <!-- Sumário -->
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="card h-100">
                            <div class="card-body py-3">
                                <small class="text-muted d-block">Total</small>
                                <div class="fw-bold fs-5">{{ summary.total }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100">
                            <div class="card-body py-3">
                                <small class="text-muted d-block">Atendidos</small>
                                <div class="fw-bold fs-5 text-success">{{ summary.attended }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100">
                            <div class="card-body py-3">
                                <small class="text-muted d-block">Faltas / Cancelados</small>
                                <div class="fw-bold fs-5 text-danger">{{ summary.noshow + summary.cancelled }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100">
                            <div class="card-body py-3">
                                <small class="text-muted d-block">Taxa de presença</small>
                                <div class="fw-bold fs-5 text-info">{{ summary.attendance_rate }}%</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Por médico -->
                <div v-if="byDoctor.length > 0" class="card mb-3">
                    <div class="card-header bg-transparent"><h6 class="mb-0">Por médico</h6></div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Médico</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Atendidos</th>
                                    <th class="text-center">Faltas</th>
                                    <th class="text-center">Cancelados</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in byDoctor" :key="i">
                                    <td class="fw-medium">{{ row.doctor_name }}</td>
                                    <td class="text-center">{{ row.total }}</td>
                                    <td class="text-center text-success">{{ row.attended }}</td>
                                    <td class="text-center text-danger">{{ row.noshow }}</td>
                                    <td class="text-center text-warning">{{ row.cancelled }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Lista -->
                <div class="card">
                    <div class="card-header bg-transparent"><h6 class="mb-0">Agendamentos</h6></div>
                    <div class="table-responsive">
                        <table class="table table-nowrap table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Data/hora</th>
                                    <th>Paciente</th>
                                    <th>Médico</th>
                                    <th>Convênio</th>
                                    <th>Tipo</th>
                                    <th class="text-center">Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="schedules.length === 0">
                                    <td colspan="6" class="text-center text-muted py-5">Nenhum agendamento.</td>
                                </tr>
                                <tr v-for="(s, i) in schedules" :key="i">
                                    <td class="text-muted small">{{ s.date_time }}</td>
                                    <td class="fw-medium">{{ s.patient_name || '—' }}</td>
                                    <td>{{ s.doctor_name || '—' }}</td>
                                    <td class="text-muted small">{{ s.covenant || '—' }}</td>
                                    <td class="text-muted small">{{ s.visit_type || '—' }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-soft-secondary rounded fs-11">{{ s.situation_label }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <div v-else class="alert alert-info">
                <i class="ti ti-info-circle me-1"></i>
                Informe o período acima para gerar o relatório.
            </div>
        </div>
    </AppLayout>
</template>
