<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout              from '@/Layouts/AppLayout.vue';
import PatientInfoSidebar     from './Components/PatientInfoSidebar.vue';
import MedicalRecordForm      from './Components/MedicalRecordForm.vue';

defineProps({
    breadcrumbs:     { type: Array,   default: () => [] },
    patient:         { type: Object,  required: true },
    medicalrecord:   { type: Object,  default: null },
    doctors:         { type: Array,   default: () => [] },
    currentDoctorId: { type: String,  default: null },
    canChooseDoctor: { type: Boolean, default: false },
    isDoctor:        { type: Boolean, default: false },
    isEdit:          { type: Boolean, default: false },
    catalogs:        { type: Object,  required: true },
    urls:            { type: Object,  required: true },
    storage:         { type: Object,  default: () => ({}) },
    t:               { type: Object,  default: () => ({}) },
});
</script>

<template>
    <Head :title="t.create ?? 'Novo Prontuário'" />

    <AppLayout :title="t.create ?? 'Novo Prontuário'" :breadcrumbs="breadcrumbs">
        <div class="pmr-screen">
            <!-- Subnav -->
            <div class="row mb-3 align-items-center">
                <div class="col-12 col-auto">
                    <div class="btn-group" role="group">
                        <Link :href="urls.list" class="btn btn-outline-white btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>{{ t.title ?? 'Prontuários' }}
                        </Link>
                        <Link :href="urls.create" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>{{ t.new ?? 'Novo' }}
                        </Link>
                    </div>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-12 col-lg-3 col-xl-2">
                    <div class="patient-info-sticky">
                        <PatientInfoSidebar :patient="patient" />
                    </div>
                </div>

                <div class="col-12 col-lg-9 col-xl-10">
                    <div class="card pmr-content-card overflow-hidden bg-white">
                        <MedicalRecordForm
                            :patient="patient"
                            :medicalrecord="null"
                            :doctors="doctors"
                            :current-doctor-id="currentDoctorId"
                            :can-choose-doctor="canChooseDoctor"
                            :is-doctor="isDoctor"
                            :is-edit="false"
                            :catalogs="catalogs"
                            :urls="urls"
                            :storage="storage"
                            :t="t"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
