<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PatientPortalLayout from '@/Layouts/PatientPortalLayout.vue';

defineProps({
    appName:     { type: String, default: 'EasyEye' },
    type:        { type: String, required: true },
    typeLabel:   { type: String, default: '' },
    title:       { type: String, default: '' },
    isImage:     { type: Boolean, default: false },
    isPdf:       { type: Boolean, default: false },
    showUrl:     { type: String, required: true },
    downloadUrl: { type: String, required: true },
});
</script>

<template>
    <Head :title="`${title} — Portal do Paciente`" />

    <PatientPortalLayout>
        <Link :href="route('patient-portal.dashboard')" class="small text-decoration-none d-inline-flex align-items-center mb-3">
            <i class="ti ti-arrow-left me-1"></i>Minhas Clínicas
        </Link>

        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div>
                <span class="badge bg-primary-subtle text-primary mb-1">{{ typeLabel }}</span>
                <h5 class="fw-bold mb-0">{{ title }}</h5>
            </div>
            <a :href="downloadUrl" class="btn btn-sm btn-primary">
                <i class="ti ti-download me-1"></i>Baixar
            </a>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0" style="min-height:60vh;">
                <iframe v-if="isPdf" :src="showUrl" style="width:100%;height:80vh;border:0;" title="Documento"></iframe>
                <div v-else-if="isImage" class="text-center p-3">
                    <img :src="showUrl" class="img-fluid rounded" :alt="title" style="max-height:75vh;">
                </div>
                <div v-else class="text-center py-5 text-muted">
                    <i class="ti ti-file fs-1 d-block mb-2"></i>
                    Pré-visualização indisponível para este arquivo.
                    <a :href="downloadUrl" class="d-block mt-2">Baixar arquivo</a>
                </div>
            </div>
        </div>
    </PatientPortalLayout>
</template>
