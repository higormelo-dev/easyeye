<script setup>
import { ref } from 'vue';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    exports:     { type: Object, required: true },
});

const auditForm  = ref({ date_from: '', date_until: '' });
const accessForm = ref({ date_from: '', date_until: '' });

function buildUrl(base, params) {
    const url = new URL(base, window.location.origin);
    Object.entries(params).forEach(([k, v]) => v && url.searchParams.append(k, v));
    return url.toString();
}
</script>

<template>
    <AppLayout title="Compliance & Auditoria" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Compliance & Auditoria" subtitle="LGPD/CFM — exporte trilhas de auditoria do período" />

            <div class="row g-3">
                <!-- Audit -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-transparent">
                            <h6 class="mb-0 fw-semibold">
                                <i class="ti ti-shield-check me-1 text-primary"></i>
                                Audit log (criações, alterações e exclusões)
                            </h6>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">
                                Trilha CUD de todos os models auditáveis (Patient, MedicalRecord, Schedule etc.) no período.
                            </p>
                            <form class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">De *</label>
                                    <input v-model="auditForm.date_from" type="date" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">Até *</label>
                                    <input v-model="auditForm.date_until" type="date" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-12 mt-3">
                                    <a
                                        :href="buildUrl(exports.audit, auditForm)"
                                        class="btn btn-primary btn-sm w-100"
                                        :class="{ disabled: !auditForm.date_from || !auditForm.date_until }"
                                    >
                                        <i class="ti ti-download me-1"></i>Exportar CSV
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Data access -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-transparent">
                            <h6 class="mb-0 fw-semibold">
                                <i class="ti ti-eye me-1 text-warning"></i>
                                Logs de acesso a dados sensíveis (LGPD)
                            </h6>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">
                                Quem acessou quais prontuários e qual a justificativa LGPD (rastreio para responder Solicitações de Titular).
                            </p>
                            <form class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">De *</label>
                                    <input v-model="accessForm.date_from" type="date" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">Até *</label>
                                    <input v-model="accessForm.date_until" type="date" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-12 mt-3">
                                    <a
                                        :href="buildUrl(exports.data_access, accessForm)"
                                        class="btn btn-warning btn-sm w-100"
                                        :class="{ disabled: !accessForm.date_from || !accessForm.date_until }"
                                    >
                                        <i class="ti ti-download me-1"></i>Exportar CSV
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
