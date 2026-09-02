<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout              from '@/Layouts/AppLayout.vue';
import PatientInfoSidebar     from './Components/PatientInfoSidebar.vue';
import PreviousRecordsCard    from './Components/PreviousRecordsCard.vue';
import MedicalRecordViewModal from './Components/MedicalRecordViewModal.vue';
import MedicalRecordForm      from './Components/MedicalRecordForm.vue';
import ScheduleFlowGuard      from './Components/ScheduleFlowGuard.vue';

defineProps({
    breadcrumbs:     { type: Array,   default: () => [] },
    patient:         { type: Object,  required: true },
    medicalrecord:   { type: Object,  default: null },
    previousRecords: { type: Array,   default: () => [] },
    doctors:         { type: Array,   default: () => [] },
    currentDoctorId: { type: String,  default: null },
    canChooseDoctor: { type: Boolean, default: false },
    isDoctor:        { type: Boolean, default: false },
    isEdit:          { type: Boolean, default: false },
    // Fluxo Agenda ↔ Prontuário (ver ScheduleFlowGuard.vue) — no create chega
    // via ?schedule_id= quando o atendimento é aberto a partir da Agenda.
    scheduleFlow:    { type: Object,  default: null },
    catalogs:        { type: Object,  required: true },
    urls:            { type: Object,  required: true },
    storage:         { type: Object,  default: () => ({}) },
    t:               { type: Object,  default: () => ({}) },
});

const flowGuard  = ref(null);
const recordForm = ref(null);

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
    <Head :title="t.create ?? 'Novo Prontuário'" />

    <AppLayout :title="t.create ?? 'Novo Prontuário'" :breadcrumbs="breadcrumbs">
        <div class="pmr-screen">
            <!-- Subnav -->
            <div class="row mb-3 align-items-center">
                <div class="col-12 col-auto">
                    <div class="btn-group" role="group">
                        <!-- Com fluxo de agendamento ativo, sair passa pelo
                             ScheduleFlowGuard (Finalizar/Dilatar/Exame/Continuar). -->
                        <button v-if="flowGuard?.active"
                                type="button"
                                class="btn btn-outline-white btn-sm"
                                @click="flowGuard.requestExit()">
                            <i class="fas fa-arrow-left me-1"></i>{{ t.title ?? 'Prontuários' }}
                        </button>
                        <Link v-else :href="urls.list" class="btn btn-outline-white btn-sm">
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
                        <PreviousRecordsCard :records="previousRecords" :t="t" @view="openView" />
                    </div>
                </div>

                <div class="col-12 col-lg-9 col-xl-10">
                    <div class="card pmr-content-card overflow-hidden bg-white">
                        <MedicalRecordForm
                            ref="recordForm"
                            :patient="patient"
                            :medicalrecord="null"
                            :doctors="doctors"
                            :current-doctor-id="currentDoctorId"
                            :can-choose-doctor="canChooseDoctor"
                            :is-doctor="isDoctor"
                            :is-edit="false"
                            :catalogs="catalogs"
                            :urls="urls"
                            :schedule-flow="scheduleFlow"
                            :storage="storage"
                            :t="t"
                        />
                    </div>
                </div>
            </div>
        </div>

        <MedicalRecordViewModal :open="viewOpen" :record="viewRecord" :t="t" @close="closeView" />

        <ScheduleFlowGuard
            :t="t"
            ref="flowGuard"
            :flow="scheduleFlow"
            :is-doctor="isDoctor"
            :locked="false"
            :exit-url="urls.list"
            :finish-url="urls.schedules"
            :submit-flow="(action) => recordForm?.submitFlow(action)"
        />
    </AppLayout>
</template>
