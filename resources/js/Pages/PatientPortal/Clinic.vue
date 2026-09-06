<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PatientPortalLayout from '@/Layouts/PatientPortalLayout.vue';

const props = defineProps({
    appName:       { type: String, default: 'EasyEye' },
    clinicName:    { type: String, default: '' },
    documents:     { type: Array, default: () => [] },
    lgpdExportUrl: { type: String, required: true },
});

function typeIcon(type) {
    return { laudo: 'ti-file-text', exame: 'ti-photo', anexo: 'ti-paperclip' }[type] ?? 'ti-file';
}
</script>

<template>
    <Head :title="`${clinicName || 'Clínica'} — Portal do Paciente`" />

    <PatientPortalLayout>
        <Link :href="route('patient-portal.dashboard')" class="small text-decoration-none d-inline-flex align-items-center mb-3">
            <i class="ti ti-arrow-left me-1"></i>Minhas Clínicas
        </Link>

        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
            <h4 class="fw-bold mb-0">
                <i class="ti ti-building-hospital me-1 text-primary"></i>{{ clinicName || 'Clínica' }}
            </h4>
            <a :href="lgpdExportUrl" class="btn btn-sm btn-outline-primary">
                <i class="ti ti-download me-1"></i>Baixar meus dados
            </a>
        </div>
        <p class="text-muted small mb-4">
            <i class="ti ti-shield-lock me-1"></i>
            "Baixar meus dados" gera um arquivo com tudo que esta clínica registrou sobre você
            (LGPD Art. 18) — cadastro, prontuários e exames. Fica registrado como um acesso seu.
        </p>

        <div v-if="documents.length === 0" class="card shadow-sm border-0">
            <div class="card-body text-center py-5 text-muted">
                <i class="ti ti-file-off fs-1 mb-2 d-block"></i>
                Nenhum documento liberado por esta clínica até o momento.
            </div>
        </div>

        <div v-else class="list-group">
            <div v-for="doc in documents" :key="doc.id" class="list-group-item d-flex align-items-center gap-3">
                <span class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle text-primary flex-shrink-0"
                      style="width:40px;height:40px;">
                    <i :class="`ti ${typeIcon(doc.type)}`"></i>
                </span>
                <div class="flex-grow-1 min-width-0">
                    <div class="fw-semibold text-truncate">{{ doc.title }}</div>
                    <small class="text-muted">{{ doc.type_label }} · liberado em {{ doc.shared_at }}</small>
                </div>
                <Link :href="doc.view_url" class="btn btn-sm btn-outline-primary">
                    <i class="ti ti-eye me-1"></i>Ver
                </Link>
                <a :href="doc.download_url" class="btn btn-sm btn-outline-secondary" title="Baixar">
                    <i class="ti ti-download"></i>
                </a>
            </div>
        </div>
    </PatientPortalLayout>
</template>

<style scoped>
.min-width-0 { min-width: 0; }
</style>
