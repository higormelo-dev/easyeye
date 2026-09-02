<script setup>
import { ref, computed, onMounted } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout            from '@/Layouts/AppLayout.vue';
import PageHeader           from '@/Components/Panel/PageHeader.vue';
import ActionIconGroup      from '@/Components/Panel/ActionIconGroup.vue';
import ActionIconButton     from '@/Components/Panel/ActionIconButton.vue';
import ActionDropdown       from '@/Components/Panel/ActionDropdown.vue';
import MedicalRecordDetailDrawer from './MedicalRecordDetailDrawer.vue';
import PatientInfoSidebar   from './Components/PatientInfoSidebar.vue';
import PdfPreviewModal      from './Components/PdfPreviewModal.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    patient:     { type: Object, required: true },
    urls:        { type: Object, required: true },
    t:           { type: Object, default: () => ({}) },
    /** Apenas médicos podem criar/editar prontuário (CFM Res. 2.227/2018). */
    isDoctor:    { type: Boolean, default: false },
});

// ── Timeline: paginação infinita ────────────────────────────────────────────
const records      = ref([]);
const loading      = ref(false);
const loadingMore  = ref(false);
const hasMore      = ref(true);
const nextPage     = ref(1);
const total        = ref(0);

async function loadPage(page = 1) {
    if (page === 1) {
        loading.value = true;
        records.value = [];
    } else {
        loadingMore.value = true;
    }

    try {
        const res  = await fetch(`${props.urls.ajax_list}?page=${page}&per_page=10`, {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();

        if (page === 1) records.value = json.data;
        else            records.value.push(...json.data);

        hasMore.value  = json.has_more;
        nextPage.value = json.next_page;
        total.value    = json.total;
    } finally {
        loading.value     = false;
        loadingMore.value = false;
    }
}

onMounted(() => loadPage(1));

// ── Detail drawer ───────────────────────────────────────────────────────────
const detailOpen   = ref(false);
const detailRecord = ref(null);

function openDetail(record) {
    detailRecord.value = record;
    detailOpen.value   = true;
}

// ── PDF preview modal ───────────────────────────────────────────────────────
// Em vez de abrir o PDF direto em nova aba (download bruto), exibimos primeiro
// num iframe dentro de um modal com opção de baixar/abrir externamente.
const pdfPreviewOpen  = ref(false);
const pdfPreviewUrl   = ref('');
const pdfPreviewTitle = ref('');

function openPdfPreview(record) {
    if (!record?.pdf_url) return;
    pdfPreviewUrl.value   = record.pdf_url;
    pdfPreviewTitle.value = `Prontuário ${record.code} — ${record.created_at ?? ''}`;
    pdfPreviewOpen.value  = true;
}

function closePdfPreview() {
    pdfPreviewOpen.value  = false;
    pdfPreviewUrl.value   = '';
    pdfPreviewTitle.value = '';
}

// ── Delete ──────────────────────────────────────────────────────────────────
async function onDelete(record) {
    if (record.is_locked) {
        alert('Prontuário assinado/bloqueado — não pode ser excluído.');
        return;
    }
    if (!confirm('Excluir este prontuário?')) return;

    const res = await fetch(record.destroy_url, {
        method: 'DELETE',
        headers: {
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
    });

    if (res.ok || res.status === 302) {
        if (window.showSuccessToast) window.showSuccessToast('Prontuário removido.');
        loadPage(1);
    } else if (window.showErrorToast) {
        window.showErrorToast('Erro ao remover.');
    }
}

const isEmpty = computed(() => !loading.value && records.value.length === 0);
</script>

<template>
    <AppLayout :title="t.title ?? 'Prontuários'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3 page-medical-records">

            <!-- Header ocupa a linha toda (padrão das telas raiz do painel) -->
            <PageHeader
                :title="`${patient.full_name ?? 'Paciente'} — ${t.title ?? 'Prontuários'}`"
                :total="total > 0 ? total : null"
            >
                <template #actions>
                    <div class="btn-group" role="group">
                        <!-- Agenda primeiro: após o atendimento o médico segue
                             pro próximo paciente — "Pacientes" fica como secundário. -->
                        <Link v-if="urls.schedules" :href="urls.schedules" class="btn btn-outline-secondary btn-sm">
                            <i class="ti ti-calendar-event me-1"></i>{{ t.schedule ?? 'Agenda' }}
                        </Link>
                        <Link :href="urls.patients" class="btn btn-outline-secondary btn-sm">
                            <i class="ti ti-arrow-left me-1"></i>Pacientes
                        </Link>
                        <!-- Novo prontuário: exclusivo para médicos (CFM 2.227/2018). -->
                        <Link v-if="isDoctor" :href="urls.create" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1"></i>Novo prontuário
                        </Link>
                    </div>
                </template>
            </PageHeader>

            <div class="row g-3">
                <!-- Coluna lateral: info do paciente (componente padrão do prontuário) -->
                <div class="col-12 col-md-3 col-lg-2">
                    <div class="patient-info-sticky">
                        <PatientInfoSidebar :patient="patient" />
                    </div>
                </div>

                <!-- Coluna principal: timeline -->
                <div class="col-12 col-md-9 col-lg-10">

                    <div class="card">
                        <div class="card-body">
                            <!-- Loading -->
                            <div v-if="loading" class="text-center py-5">
                                <div class="spinner-border text-info"></div>
                            </div>

                            <!-- Empty -->
                            <div v-else-if="isEmpty" class="text-center py-5 text-muted">
                                <i class="ti ti-file-text-ai fs-1 d-block mb-3 opacity-25"></i>
                                <p class="mb-3">{{ t.no_records ?? 'Nenhum prontuário cadastrado.' }}</p>
                                <Link v-if="isDoctor" :href="urls.create" class="btn btn-primary btn-sm">
                                    <i class="ti ti-plus me-1"></i>Criar primeiro prontuário
                                </Link>
                            </div>

                            <!-- Timeline -->
                            <div v-else class="medical-record-timeline">
                                <div
                                    v-for="record in records"
                                    :key="record.id"
                                    class="timeline-item"
                                >
                                    <div class="d-flex gap-3">
                                        <div class="timeline-marker">
                                            <i :class="`ti ${record.is_signed ? 'ti-shield-check-filled text-success' : 'ti-file-text text-info'} fs-4`"></i>
                                        </div>
                                        <div class="flex-grow-1 timeline-content">
                                            <div class="card border-0 shadow-sm">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div>
                                                            <h6 class="mb-0 fw-semibold">
                                                                <code class="small text-muted me-2">{{ record.code }}</code>
                                                                {{ record.created_at }}
                                                            </h6>
                                                            <small v-if="record.doctor_name" class="text-muted">
                                                                <i class="ti ti-stethoscope me-1"></i>{{ record.doctor_name }}
                                                            </small>
                                                        </div>
                                                        <div class="d-flex gap-1">
                                                            <span v-if="record.is_signed"
                                                                  class="badge badge-soft-success rounded text-success border border-success fs-11"
                                                                  :title="`Assinado em ${record.signed_at}`">
                                                                <i class="ti ti-shield-check me-1"></i>Assinado
                                                            </span>
                                                            <span v-if="record.documentations_count > 0"
                                                                  class="badge badge-soft-info rounded fs-11">
                                                                <i class="ti ti-paperclip me-1"></i>{{ record.documentations_count }} doc(s)
                                                            </span>
                                                        </div>
                                                    </div>

                                                    <div v-if="record.main_complaint" class="mb-2 small">
                                                        <strong class="text-muted">Queixa:</strong>
                                                        {{ record.main_complaint }}
                                                    </div>

                                                    <div v-if="record.clinical_conduct" class="mb-2 small">
                                                        <strong class="text-muted">Conduta:</strong>
                                                        {{ record.clinical_conduct }}
                                                    </div>

                                                    <div v-if="record.diagnosis_cids?.length > 0" class="mb-2">
                                                        <span v-for="(cid, idx) in record.diagnosis_cids" :key="idx"
                                                              class="badge badge-soft-secondary me-1 fs-11">
                                                            {{ typeof cid === 'object' ? `${cid.code} ${cid.description ?? ''}` : cid }}
                                                        </span>
                                                    </div>

                                                    <!--
                                                        Ações:
                                                          - Ver detalhes: aberto a todos os perfis (read-only)
                                                          - Editar: exclusivo para médicos (CFM 2.227/2018). Admin/secretária
                                                            não vê o botão. Se locked (assinado), nem médico edita.
                                                          - Visualizar PDF: aberto a todos
                                                          - Excluir: exclusivo para médicos + não-locked
                                                    -->
                                                    <div class="d-flex justify-content-end mt-3">
                                                        <ActionIconGroup align="end" gap="tight">
                                                            <ActionIconButton
                                                                icon="ti ti-eye"
                                                                title="Ver detalhes"
                                                                variant="default"
                                                                @click="openDetail(record)"
                                                            />
                                                            <ActionIconButton
                                                                v-if="isDoctor"
                                                                :icon="record.is_locked ? 'ti ti-lock' : 'ti ti-edit'"
                                                                :title="record.is_locked ? 'Visualizar (assinado)' : 'Editar prontuário'"
                                                                :href="record.edit_url"
                                                                variant="default"
                                                            />
                                                            <ActionDropdown
                                                                btn-class="ee-action-icon ee-action-icon--default"
                                                                icon="ti ti-dots-vertical"
                                                                align="right"
                                                            >
                                                                <li>
                                                                    <button type="button" class="dropdown-item" @click="openPdfPreview(record)">
                                                                        <i class="ti ti-file-search me-2 text-secondary"></i>Visualizar PDF
                                                                    </button>
                                                                </li>
                                                                <li v-if="isDoctor && !record.is_locked"><hr class="dropdown-divider"></li>
                                                                <li v-if="isDoctor && !record.is_locked">
                                                                    <button type="button" class="dropdown-item text-danger" @click="onDelete(record)">
                                                                        <i class="ti ti-trash me-2"></i>Excluir
                                                                    </button>
                                                                </li>
                                                            </ActionDropdown>
                                                        </ActionIconGroup>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Load more -->
                                <div v-if="hasMore" class="text-center py-3">
                                    <button class="btn btn-outline-secondary btn-sm" :disabled="loadingMore" @click="loadPage(nextPage)">
                                        <span v-if="loadingMore" class="spinner-border spinner-border-sm me-1"></span>
                                        <i v-else class="ti ti-chevron-down me-1"></i>
                                        Carregar mais
                                    </button>
                                </div>

                                <div v-else-if="!isEmpty" class="text-center py-3 text-muted small">
                                    <i class="ti ti-check me-1 text-success"></i>Fim da lista
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <MedicalRecordDetailDrawer
                :open="detailOpen"
                :record="detailRecord"
                :patient="patient"
                @close="detailOpen = false"
            />

            <!-- PDF preview universal: abre dentro de modal antes de baixar -->
            <PdfPreviewModal
                v-if="pdfPreviewOpen"
                :url="pdfPreviewUrl"
                :title="pdfPreviewTitle"
                :filename="`prontuario-${patient.code ?? 'paciente'}`"
                @close="closePdfPreview"
            />
        </div>
    </AppLayout>
</template>

<style scoped>
.patient-info-sticky { position: sticky; top: 1rem; }
.medical-record-timeline { position: relative; padding-left: 1rem; }
.timeline-item { position: relative; margin-bottom: 1rem; padding-bottom: 1rem; border-left: 2px solid var(--bs-border-color); padding-left: 1rem; }
.timeline-item:last-child { border-left-color: transparent; }
.timeline-marker {
    position: absolute;
    left: -22px;
    top: 12px;
    width: 40px;
    height: 40px;
    background: white;
    border: 2px solid var(--bs-border-color);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>
