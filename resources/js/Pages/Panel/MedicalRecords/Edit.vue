<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout              from '@/Layouts/AppLayout.vue';
import PatientInfoSidebar     from './Components/PatientInfoSidebar.vue';
import PreviousRecordsCard    from './Components/PreviousRecordsCard.vue';
import MedicalRecordViewModal from './Components/MedicalRecordViewModal.vue';
import MedicalRecordForm      from './Components/MedicalRecordForm.vue';

defineProps({
    breadcrumbs:     { type: Array,   default: () => [] },
    patient:         { type: Object,  required: true },
    medicalrecord:   { type: Object,  required: true },
    previousRecords: { type: Array,   default: () => [] },
    doctors:         { type: Array,   default: () => [] },
    currentDoctorId: { type: String,  default: null },
    canChooseDoctor: { type: Boolean, default: false },
    isDoctor:        { type: Boolean, default: false },
    isEdit:          { type: Boolean, default: true },
    catalogs:        { type: Object,  required: true },
    urls:            { type: Object,  required: true },
    storage:         { type: Object,  default: () => ({}) },
    ai:              { type: Object,  default: () => ({ enabled: false }) },
    t:               { type: Object,  default: () => ({}) },
});

const viewOpen = ref(false);
const viewRecord = ref(null);
function openView(record) {
    viewRecord.value = record;
    viewOpen.value = true;
}
function closeView() {
    viewOpen.value = false;
    viewRecord.value = null;
}
</script>

<template>
    <Head :title="t.edit ?? 'Editar Prontuário'" />

    <AppLayout :title="t.edit ?? 'Editar Prontuário'" :breadcrumbs="breadcrumbs">
        <div class="pmr-screen">
            <!-- Subnav -->
            <div class="row mb-3 align-items-center">
                <div class="col-12 col-md-auto d-flex gap-2 flex-wrap align-items-center">
                    <div class="btn-group" role="group">
                        <Link :href="urls.list" class="btn btn-outline-white btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>{{ t.title ?? 'Prontuários' }}
                        </Link>
                        <Link :href="urls.create" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>{{ t.new ?? 'Novo' }}
                        </Link>
                        <a :href="urls.pdf" target="_blank" class="btn btn-outline-white btn-sm">
                            <i class="fas fa-file-pdf me-1"></i>PDF
                        </a>
                    </div>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-12 col-lg-3 col-xl-2">
                    <div class="patient-info-sticky">
                        <PatientInfoSidebar :patient="patient" />
                        <PreviousRecordsCard :records="previousRecords" :t="t" @view="openView" />
                    </div>
                </div>

                <div class="col-12 col-lg-9 col-xl-10">
                    <div class="card pmr-content-card overflow-hidden bg-white">
                        <div class="d-flex align-items-center justify-content-between px-3 py-1 pmr-record-strip">
                            <span>
                                <i class="fas fa-file-medical-alt me-1"></i>{{ medicalrecord.code }}
                            </span>
                            <span v-if="medicalrecord.is_locked"
                                  class="badge bg-warning text-dark"
                                  style="font-size:.65rem;">
                                <i class="fas fa-lock me-1"></i>{{ t.locked ?? 'Bloqueado' }}
                            </span>
                        </div>

                        <MedicalRecordForm
                            :patient="patient"
                            :medicalrecord="medicalrecord"
                            :doctors="doctors"
                            :current-doctor-id="currentDoctorId"
                            :can-choose-doctor="canChooseDoctor"
                            :is-doctor="isDoctor"
                            :is-edit="true"
                            :catalogs="catalogs"
                            :urls="urls"
                            :storage="storage"
                            :ai="ai"
                            :t="t"
                        />
                    </div>
                </div>
            </div>
        </div>

        <MedicalRecordViewModal :open="viewOpen" :record="viewRecord" :t="t" @close="closeView" />
    </AppLayout>
</template>
