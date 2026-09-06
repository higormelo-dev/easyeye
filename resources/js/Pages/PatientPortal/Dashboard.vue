<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PatientPortalLayout from '@/Layouts/PatientPortalLayout.vue';

defineProps({
    patientName: { type: String, default: '' },
    clinics: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Minhas Clínicas — Portal do Paciente" />

    <PatientPortalLayout :patient-name="patientName">
        <h4 class="fw-bold mb-3">
            <i class="ti ti-building-hospital me-1 text-primary"></i>Minhas Clínicas
        </h4>

        <div v-if="clinics.length === 0" class="card shadow-sm border-0">
            <div class="card-body text-center py-5 text-muted">
                <i class="ti ti-building-hospital-off fs-1 mb-2 d-block"></i>
                Nenhuma clínica encontrada para o seu cadastro.
            </div>
        </div>

        <div v-else class="row g-3">
            <div v-for="clinic in clinics" :key="clinic.entity_id" class="col-12 col-md-6 col-lg-4">
                <Link :href="clinic.clinic_url" class="card shadow-sm border-0 h-100 text-decoration-none text-reset d-block">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3">
                            <span
                                class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle text-primary flex-shrink-0"
                                style="width:44px;height:44px;"
                            >
                                <i class="ti ti-building-hospital"></i>
                            </span>
                            <div class="min-width-0">
                                <h6 class="mb-0 fw-semibold text-truncate">{{ clinic.name ?? 'Clínica' }}</h6>
                                <small v-if="clinic.city" class="text-muted">{{ clinic.city }}</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mt-3 pt-2 border-top">
                            <small class="text-primary fw-semibold">Ver documentos</small>
                            <i class="ti ti-chevron-right text-primary"></i>
                        </div>
                    </div>
                </Link>
            </div>
        </div>
    </PatientPortalLayout>
</template>

<style scoped>
.min-width-0 { min-width: 0; }
</style>
