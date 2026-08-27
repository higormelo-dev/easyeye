<script setup>
import { ref, computed, watch } from 'vue';
import OffcanvasPanel    from '@/Components/Panel/OffcanvasPanel.vue';
import ActionIconButton  from '@/Components/Panel/ActionIconButton.vue';
import PdfPreviewModal   from './Components/PdfPreviewModal.vue';

/**
 * Drawer de detalhes do prontuário (read-only) — abas:
 *   1. Informações: anamnese, exame físico, refração, achados, diagnóstico
 *   2. Documentos: tabela com hora/data, tipo, título e download
 *
 * Consome MedicalRecordsController::show() que já retorna documentações
 * com created_at formatado em d/m/Y H:i e pdf_url assinado.
 */
const props = defineProps({
    open:    { type: Boolean, required: true },
    record:  { type: Object,  default: null },
    patient: { type: Object,  required: true },
});

defineEmits(['close']);

const loading    = ref(false);
const detail     = ref(null);
const activeTab  = ref('info');

async function loadDetail() {
    if (!props.record?.show_url) return;
    loading.value = true;
    detail.value  = null;
    activeTab.value = 'info';
    try {
        const res = await fetch(props.record.show_url, { headers: { Accept: 'application/json' } });
        detail.value = await res.json();
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val) loadDetail();
    else { detail.value = null; activeTab.value = 'info'; }
});

function valOr(v, alt = '—') {
    return v === null || v === undefined || v === '' ? alt : v;
}

function boolLabel(v, labels) {
    if (v === true)  return labels?.yes ?? 'Sim';
    if (v === false) return labels?.no ?? 'Não';
    return labels?.not_informed ?? 'Não informado';
}

/**
 * Extrai a parte de data (dd/mm/yyyy) de um timestamp 'dd/mm/yyyy HH:mm'.
 */
function datePart(s) {
    if (!s || typeof s !== 'string') return '';
    return s.slice(0, 10);
}

/**
 * Formata a hora do documento:
 *   - Mesmo dia do prontuário → 'HH:mm'
 *   - Dia diferente          → 'dd/mm HH:mm'  (ano só se for diferente)
 */
function formatDocTime(docCreatedAt) {
    if (!docCreatedAt || typeof docCreatedAt !== 'string') return '—';
    const recordDate = datePart(detail.value?.created_at_formatted ?? '');
    const docDate    = datePart(docCreatedAt);
    const docTime    = docCreatedAt.slice(11, 16); // HH:mm
    if (!recordDate || !docDate) return docCreatedAt;
    if (recordDate === docDate) return docTime;
    // Comparar ano: se for o mesmo, mostrar só dd/mm; senão, dd/mm/yy
    const recordYear = recordDate.slice(-4);
    const docYear    = docDate.slice(-4);
    const shortDate  = recordYear === docYear ? docDate.slice(0, 5) : docDate;
    return `${shortDate} ${docTime}`;
}

const documentations = computed(() => detail.value?.documentations ?? []);
const docCount       = computed(() => documentations.value.length);

// ── PDF preview modal (visualizar antes de baixar) ─────────────────────────
const pdfPreviewOpen  = ref(false);
const pdfPreviewUrl   = ref('');
const pdfPreviewTitle = ref('');
const pdfPreviewName  = ref('');

function openPdfPreview(doc) {
    if (!doc?.pdf_url) return;
    pdfPreviewUrl.value   = doc.pdf_url;
    pdfPreviewTitle.value = `${doc.type_label} — ${doc.title}`;
    pdfPreviewName.value  = `${doc.type_label}-${doc.title}`.toLowerCase();
    pdfPreviewOpen.value  = true;
}

function closePdfPreview() {
    pdfPreviewOpen.value  = false;
    pdfPreviewUrl.value   = '';
    pdfPreviewTitle.value = '';
    pdfPreviewName.value  = '';
}
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="680"
        :loading="loading"
        loading-label="Carregando prontuário..."
        @close="$emit('close')"
    >
        <template #header>
            <div>
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-file-text me-2 text-info"></i>
                    Prontuário
                </h5>
                <code v-if="detail" class="text-muted small">{{ detail.code }}</code>
            </div>
            <div v-if="detail" class="d-flex gap-1 ms-2">
                <span v-if="detail.is_signed" class="badge badge-soft-success rounded text-success border border-success fs-11">
                    <i class="ti ti-shield-check me-1"></i>Assinado
                </span>
                <a v-if="record?.pdf_url" :href="record.pdf_url" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="ti ti-file-download me-1"></i>PDF
                </a>
                <a v-if="record?.edit_url" :href="record.edit_url" class="btn btn-sm btn-outline-primary">
                    <i class="ti ti-edit me-1"></i>{{ detail?.is_locked ? 'Ver' : 'Editar' }}
                </a>
            </div>
        </template>

        <template v-if="detail">
            <!-- Header info -->
            <div class="mb-3 small text-muted">
                <i class="ti ti-calendar me-1"></i>{{ detail.created_at_formatted }}
                <span v-if="detail.doctor_name" class="ms-3">
                    <i class="ti ti-stethoscope me-1"></i>{{ detail.doctor_name }}
                </span>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{ active: activeTab === 'info' }"
                        @click="activeTab = 'info'"
                    >
                        <i class="ti ti-clipboard-text me-1"></i>Informações
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link d-flex align-items-center gap-1"
                        :class="{ active: activeTab === 'docs' }"
                        @click="activeTab = 'docs'"
                    >
                        <i class="ti ti-paperclip me-1"></i>Documentos
                        <span v-if="docCount > 0"
                              class="badge rounded-pill bg-info-subtle text-info border border-info-subtle ms-1">
                            {{ docCount }}
                        </span>
                    </button>
                </li>
            </ul>

            <!-- ═══════════════════ ABA 1 — INFORMAÇÕES ═══════════════════ -->
            <div v-show="activeTab === 'info'">

                <!-- Anamnese -->
                <section class="detail-section">
                    <h6 class="detail-section__title">
                        <i class="ti ti-message me-1"></i>{{ detail.labels?.complaint ?? 'Queixa & Anamnese' }}
                    </h6>
                    <div class="detail-row"><span class="detail-label">{{ detail.labels?.complaint ?? 'Queixa principal' }}</span><span class="detail-value">{{ valOr(detail.main_complaint) }}</span></div>
                    <div class="detail-row"><span class="detail-label">{{ detail.labels?.history ?? 'HDA' }}</span><span class="detail-value">{{ valOr(detail.hda) }}</span></div>
                    <div class="detail-row"><span class="detail-label">{{ detail.labels?.diabetic ?? 'Diabético' }}</span><span class="detail-value">{{ boolLabel(detail.diabetic, detail.labels) }} <small v-if="detail.diabetic_family" class="text-muted">({{ detail.labels?.family }})</small></span></div>
                    <div class="detail-row"><span class="detail-label">{{ detail.labels?.hypertensive ?? 'Hipertenso' }}</span><span class="detail-value">{{ boolLabel(detail.hypertensive, detail.labels) }} <small v-if="detail.hypertensive_family" class="text-muted">({{ detail.labels?.family }})</small></span></div>
                    <div class="detail-row"><span class="detail-label">{{ detail.labels?.glaucomatous ?? 'Glaucomatoso' }}</span><span class="detail-value">{{ boolLabel(detail.glaucomatous, detail.labels) }} <small v-if="detail.glaucomatous_family" class="text-muted">({{ detail.labels?.family }})</small></span></div>
                    <div v-if="detail.ocular_surgical_history" class="detail-row"><span class="detail-label">Histórico cirúrgico</span><span class="detail-value">{{ detail.ocular_surgical_history }}</span></div>
                    <div v-if="detail.medications_in_use" class="detail-row"><span class="detail-label">Medicações em uso</span><span class="detail-value">{{ detail.medications_in_use }}</span></div>
                </section>

                <!-- Exame físico -->
                <section class="detail-section">
                    <h6 class="detail-section__title"><i class="ti ti-eye me-1"></i>Exame físico</h6>
                    <div class="detail-row"><span class="detail-label">Acuidade visual</span><span class="detail-value">{{ valOr(detail.visual_acuity_type) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Conv. ponto próximo</span><span class="detail-value">{{ valOr(detail.near_point_convergence) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Cover test</span><span class="detail-value">{{ valOr(detail.cover_test_type) }}</span></div>
                    <div class="detail-row"><span class="detail-label">Visão de cores</span><span class="detail-value">{{ valOr(detail.color_vision_type) }}</span></div>
                    <div v-if="detail.tonometer_right || detail.tonometer_left" class="detail-row">
                        <span class="detail-label">{{ detail.labels?.tonometry ?? 'Tonometria' }}</span>
                        <span class="detail-value">
                            OD: <code>{{ valOr(detail.tonometer_right) }}</code> /
                            OE: <code>{{ valOr(detail.tonometer_left) }}</code>
                            <small v-if="detail.tonometer_time" class="text-muted ms-2">{{ detail.tonometer_time }}</small>
                        </span>
                    </div>
                    <div v-if="detail.pachymetry_right || detail.pachymetry_left" class="detail-row">
                        <span class="detail-label">Paquimetria</span>
                        <span class="detail-value">
                            OD: <code>{{ valOr(detail.pachymetry_right) }}</code> /
                            OE: <code>{{ valOr(detail.pachymetry_left) }}</code>
                        </span>
                    </div>
                </section>

                <!-- Refração -->
                <section v-if="detail.dynamic_spherical_right || detail.dynamic_spherical_left || detail.static_spherical_right" class="detail-section">
                    <h6 class="detail-section__title"><i class="ti ti-eyeglass-2 me-1"></i>Refração</h6>
                    <div class="small">
                        <table class="table table-sm table-borderless mb-2">
                            <thead class="table-light">
                                <tr><th></th><th>OD</th><th>OE</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-medium">Dinâmica esf/cil/eixo</td>
                                    <td><code>{{ valOr(detail.dynamic_spherical_right) }} / {{ valOr(detail.dynamic_cylindrical_right) }} / {{ valOr(detail.dynamic_axis_right) }}</code></td>
                                    <td><code>{{ valOr(detail.dynamic_spherical_left) }} / {{ valOr(detail.dynamic_cylindrical_left) }} / {{ valOr(detail.dynamic_axis_left) }}</code></td>
                                </tr>
                                <tr>
                                    <td class="fw-medium">Estática esf/cil/eixo</td>
                                    <td><code>{{ valOr(detail.static_spherical_right) }} / {{ valOr(detail.static_cylindrical_right) }} / {{ valOr(detail.static_axis_right) }}</code></td>
                                    <td><code>{{ valOr(detail.static_spherical_left) }} / {{ valOr(detail.static_cylindrical_left) }} / {{ valOr(detail.static_axis_left) }}</code></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="detail-row"><span class="detail-label">Adição</span><span class="detail-value">{{ valOr(detail.addition_type) }}</span></div>
                        <div class="detail-row"><span class="detail-label">Lente longe</span><span class="detail-value">{{ valOr(detail.lens_away) }}</span></div>
                        <div class="detail-row"><span class="detail-label">Lente perto</span><span class="detail-value">{{ valOr(detail.lens_near) }}</span></div>
                    </div>
                </section>

                <!-- Achados -->
                <section v-if="detail.biomicroscopy_right || detail.fundoscopy_right || detail.observation_general" class="detail-section">
                    <h6 class="detail-section__title"><i class="ti ti-microscope me-1"></i>Achados</h6>
                    <div v-if="detail.biomicroscopy_right || detail.biomicroscopy_left" class="detail-row">
                        <span class="detail-label">Biomicroscopia</span>
                        <span class="detail-value small">
                            <strong>OD:</strong> {{ valOr(detail.biomicroscopy_right) }}<br>
                            <strong>OE:</strong> {{ valOr(detail.biomicroscopy_left) }}
                        </span>
                    </div>
                    <div v-if="detail.fundoscopy_right || detail.fundoscopy_left" class="detail-row">
                        <span class="detail-label">Fundoscopia</span>
                        <span class="detail-value small">
                            <strong>OD:</strong> {{ valOr(detail.fundoscopy_right) }}<br>
                            <strong>OE:</strong> {{ valOr(detail.fundoscopy_left) }}
                        </span>
                    </div>
                    <div v-if="detail.observation_general" class="detail-row">
                        <span class="detail-label">{{ detail.labels?.general_obs ?? 'Observação geral' }}</span>
                        <span class="detail-value">{{ detail.observation_general }}</span>
                    </div>
                </section>

                <!-- Diagnóstico & conduta -->
                <section v-if="detail.diagnosis_cids?.length > 0 || detail.clinical_conduct" class="detail-section">
                    <h6 class="detail-section__title"><i class="ti ti-clipboard-text me-1"></i>Diagnóstico & Conduta</h6>
                    <div v-if="detail.diagnosis_cids?.length > 0" class="mb-2">
                        <span v-for="(cid, idx) in detail.diagnosis_cids" :key="idx" class="badge badge-soft-secondary me-1">
                            {{ typeof cid === 'object' ? `${cid.code} ${cid.description ?? ''}` : cid }}
                        </span>
                    </div>
                    <div v-if="detail.clinical_conduct" class="detail-row">
                        <span class="detail-label">Conduta clínica</span>
                        <span class="detail-value">{{ detail.clinical_conduct }}</span>
                    </div>
                    <div v-if="detail.follow_up_days" class="detail-row">
                        <span class="detail-label">Retorno</span>
                        <span class="detail-value">{{ detail.follow_up_days }} dias</span>
                    </div>
                </section>

                <!-- Assinatura -->
                <section v-if="detail.is_signed" class="detail-section">
                    <h6 class="detail-section__title"><i class="ti ti-shield-check me-1 text-success"></i>Assinatura digital</h6>
                    <div class="alert alert-success small mb-0">
                        <i class="ti ti-check me-1"></i>
                        Assinado em <strong>{{ detail.signed_at_formatted }}</strong>
                        <span v-if="detail.signed_by_name">por <strong>{{ detail.signed_by_name }}</strong></span>
                        <small class="d-block mt-1 text-muted">CFM Res. 2.227/2018 — prontuário travado para edição.</small>
                    </div>
                </section>
            </div>

            <!-- ═══════════════════ ABA 2 — DOCUMENTOS ═══════════════════ -->
            <div v-show="activeTab === 'docs'">
                <div v-if="documentations.length === 0" class="text-center py-5 text-muted">
                    <i class="ti ti-paperclip fs-1 d-block mb-2 opacity-25"></i>
                    <p class="mb-0 small">Nenhum documento gerado neste prontuário.</p>
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 110px;">Hora</th>
                                <th style="width: 140px;">Tipo</th>
                                <th>Título</th>
                                <th style="width: 70px;" class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="doc in documentations" :key="doc.id">
                                <td class="small text-muted">
                                    <span :title="doc.created_at">{{ formatDocTime(doc.created_at) }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-soft-info text-info">
                                        {{ doc.type_label }}
                                    </span>
                                    <span v-if="doc.is_ai"
                                          class="badge bg-info text-dark ms-1"
                                          :title="doc.ai_workflow_label || 'Gerado por IA'">
                                        <i class="ti ti-robot me-1"></i>IA
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-medium small">{{ doc.title }}</div>
                                    <small v-if="doc.doctor_name" class="text-muted">
                                        <i class="ti ti-stethoscope me-1"></i>{{ doc.doctor_name }}
                                    </small>
                                </td>
                                <td class="text-end">
                                    <ActionIconButton
                                        icon="ti ti-eye"
                                        title="Visualizar documento"
                                        variant="default"
                                        @click="openPdfPreview(doc)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </OffcanvasPanel>

    <!-- Visualizador de PDF do documento (Teleport para body, z-index acima do drawer) -->
    <PdfPreviewModal
        v-if="pdfPreviewOpen"
        :url="pdfPreviewUrl"
        :title="pdfPreviewTitle"
        :filename="pdfPreviewName"
        @close="closePdfPreview"
    />
</template>

<style scoped>
.detail-section { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--bs-border-color); }
.detail-section:last-child { border-bottom: none; }
.detail-section__title {
    font-size: .8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--bs-secondary-color);
    margin-bottom: .75rem;
}
.detail-row {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: .5rem;
    font-size: .875rem;
    align-items: baseline;
    margin-bottom: .375rem;
}
.detail-label { font-weight: 600; color: var(--bs-body-color); }
/* pre-line: preserva quebras de linha/parágrafos como o médico escreveu —
   essencial pros atendimentos em texto livre no histórico. */
.detail-value { color: var(--bs-secondary-color); word-break: break-word; white-space: pre-line; }

.nav-tabs .nav-link {
    font-size: .875rem;
    color: var(--bs-secondary-color);
    border-bottom: 2px solid transparent;
}
.nav-tabs .nav-link.active {
    color: var(--bs-primary);
    border-bottom-color: var(--bs-primary);
    background: transparent;
    font-weight: 600;
}
</style>
