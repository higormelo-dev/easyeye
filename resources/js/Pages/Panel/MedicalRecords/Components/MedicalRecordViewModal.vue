<script setup>
import { ref, watch, computed, onMounted } from 'vue';

/**
 * Modal somente-leitura de um prontuário anterior. Busca o JSON completo via
 * `show_url` (rota panel.patients.medicalrecords.show) e renderiza por seções,
 * exibindo apenas campos preenchidos. Nenhuma edição — visualização clínica.
 */
const props = defineProps({
    open:    { type: Boolean, required: true },
    record:  { type: Object,  default: null },   // item resumido (tem show_url)
    t:       { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

const data = ref(null);
const loading = ref(false);
const errorMsg = ref('');

watch(() => props.open, (val) => {
    if (val) load();
    else { data.value = null; errorMsg.value = ''; }
});

onMounted(() => {
    if (props.open) load();
});

async function load() {
    const url = props.record?.show_url;
    if (!url) return;
    loading.value = true;
    errorMsg.value = '';
    data.value = null;
    try {
        const res = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        data.value = await res.json();
    } catch (e) {
        errorMsg.value = e.message;
        // eslint-disable-next-line no-console
        console.error('[MedicalRecordView] falha ao carregar prontuário:', e);
    } finally {
        loading.value = false;
    }
}

function close() { emit('close'); }

// ── Montagem das seções (apenas campos preenchidos) ────────────────────────
const yn = (v) => (v ? (props.t.yes ?? 'Sim') : (props.t.no ?? 'Não'));
const pair = (r, l) => {
    const parts = [];
    if (r !== null && r !== undefined && r !== '') parts.push(`OD: ${r}`);
    if (l !== null && l !== undefined && l !== '') parts.push(`OE: ${l}`);
    return parts.join('  ·  ');
};

const sections = computed(() => {
    const d = data.value;
    if (!d) return [];

    const rows = (items) => items
        .map(([label, value]) => ({ label, value }))
        .filter((x) => x.value !== null && x.value !== undefined && x.value !== '');

    const out = [];

    const anamnese = rows([
        ['Queixa principal', d.main_complaint],
        ['HDA', d.hda],
        ['Cirurgias oculares', d.ocular_surgical_history],
        ['Medicações em uso', d.medications_in_use],
        ['Diabético', d.diabetic ? `${yn(d.diabetic)}${d.diabetic_family ? ' · familiar' : ''}` : ''],
        ['Hipertenso', d.hypertensive ? `${yn(d.hypertensive)}${d.hypertensive_family ? ' · familiar' : ''}` : ''],
        ['Glaucomatoso', d.glaucomatous ? `${yn(d.glaucomatous)}${d.glaucomatous_family ? ' · familiar' : ''}` : ''],
    ]);
    if (anamnese.length) out.push({ title: 'Anamnese', icon: 'fa-comment-medical', rows: anamnese });

    const exame = rows([
        ['Acuidade visual', d.visual_acuity_type],
        ['Motilidade ocular', d.ocular_motility],
        ['Tonometria', pair(d.tonometer_right, d.tonometer_left)],
        ['Horário tonometria', d.tonometer_time],
        ['Paquimetria', pair(d.pachymetry_right, d.pachymetry_left)],
        ['Gonioscopia', pair(d.gonioscopy_right, d.gonioscopy_left)],
        ['Cover test', d.cover_test_type],
        ['Visão de cores', d.color_vision_type],
        ['Ponto próximo convergência', d.near_point_convergence],
    ]);
    if (exame.length) out.push({ title: 'Exame físico', icon: 'fa-eye', rows: exame });

    const refracao = rows([
        ['AV sem correção', pair(d.visual_acuity_without_correction_right, d.visual_acuity_without_correction_left)],
        ['AV com correção', pair(d.visual_acuity_with_correction_right, d.visual_acuity_with_correction_left)],
        ['Esférico dinâmico', pair(d.dynamic_spherical_right, d.dynamic_spherical_left)],
        ['Cilíndrico dinâmico', pair(d.dynamic_cylindrical_right, d.dynamic_cylindrical_left)],
        ['Eixo dinâmico', pair(d.dynamic_axis_right, d.dynamic_axis_left)],
        ['Esférico estático', pair(d.static_spherical_right, d.static_spherical_left)],
        ['Cilíndrico estático', pair(d.static_cylindrical_right, d.static_cylindrical_left)],
        ['Eixo estático', pair(d.static_axis_right, d.static_axis_left)],
        ['Adição', d.addition_type],
        ['Lente longe', d.lens_away],
        ['Lente perto', d.lens_near],
    ]);
    if (refracao.length) out.push({ title: 'Refração', icon: 'fa-glasses', rows: refracao });

    const achados = rows([
        ['Biomicroscopia', pair(d.biomicroscopy_right, d.biomicroscopy_left)],
        ['Fundoscopia', pair(d.fundoscopy_right, d.fundoscopy_left)],
        ['Observação geral', d.observation_general],
        ['Observação de lentes', d.observation_of_lenses],
    ]);
    if (achados.length) out.push({ title: 'Achados', icon: 'fa-magnifying-glass', rows: achados });

    const conduta = rows([
        ['Conduta clínica', d.clinical_conduct],
        ['Retorno (dias)', d.follow_up_days],
    ]);
    if (conduta.length) out.push({ title: 'Diagnóstico & conduta', icon: 'fa-notes-medical', rows: conduta });

    return out;
});

const diagnosisCids = computed(() => {
    const cids = data.value?.diagnosis_cids;
    if (!Array.isArray(cids)) return [];
    return cids.map((c) => {
        if (typeof c === 'string') return c;
        return [c.code, c.description].filter(Boolean).join(' — ') || JSON.stringify(c);
    });
});

const documentations = computed(() => data.value?.documentations ?? []);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal fade show d-block" tabindex="-1"
             style="background: rgba(15,23,42,.55);" @click.self="close">
            <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-width: 760px;">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header py-2 border-0">
                        <h6 class="modal-title d-flex align-items-center gap-2 mb-0">
                            <i class="fas fa-file-medical text-primary"></i>
                            {{ t.view_title ?? 'Prontuário' }}
                            <small v-if="record?.code" class="text-muted fw-normal">{{ record.code }}</small>
                        </h6>
                        <button type="button" class="btn-close" @click="close"></button>
                    </div>

                    <div class="modal-body">
                        <div v-if="loading" class="text-center text-muted py-5">
                            <i class="fas fa-spinner fa-spin"></i> {{ t.loading ?? 'Carregando…' }}
                        </div>
                        <div v-else-if="errorMsg" class="alert alert-danger small d-flex justify-content-between align-items-center gap-2">
                            <span><i class="fas fa-circle-exclamation me-1"></i>{{ errorMsg }}</span>
                            <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" @click="load">
                                <i class="fas fa-rotate-right me-1"></i>{{ t.reload ?? 'Recarregar' }}
                            </button>
                        </div>

                        <template v-else-if="data">
                            <!-- Cabeçalho -->
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 pb-2 mb-3 border-bottom">
                                <div>
                                    <div class="fw-bold">{{ data.created_at_formatted }}</div>
                                    <div class="small text-muted"><i class="fas fa-user-doctor me-1"></i>{{ data.doctor_name || '—' }}</div>
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span v-if="data.is_signed" class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="fas fa-lock me-1"></i>{{ t.signed ?? 'Assinado' }}
                                        <span v-if="data.signed_at_formatted"> · {{ data.signed_at_formatted }}</span>
                                    </span>
                                    <a v-if="data.pdf_url" :href="data.pdf_url" target="_blank" class="badge bg-light text-danger border text-decoration-none">
                                        <i class="fas fa-file-pdf me-1"></i>PDF
                                    </a>
                                </div>
                            </div>

                            <!-- Diagnósticos (CID) -->
                            <div v-if="diagnosisCids.length" class="mb-3">
                                <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.7rem;letter-spacing:.04em;">
                                    <i class="fas fa-notes-medical me-1"></i>{{ t.diagnoses ?? 'Diagnósticos (CID)' }}
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span v-for="(cid, i) in diagnosisCids" :key="i"
                                          class="badge bg-info-subtle text-info-emphasis border border-info-subtle">{{ cid }}</span>
                                </div>
                            </div>

                            <!-- Seções clínicas -->
                            <div v-for="sec in sections" :key="sec.title" class="mb-3">
                                <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.7rem;letter-spacing:.04em;">
                                    <i class="fas me-1" :class="sec.icon"></i>{{ sec.title }}
                                </div>
                                <dl class="row gy-1 mb-0 small">
                                    <template v-for="row in sec.rows" :key="row.label">
                                        <dt class="col-sm-4 text-muted fw-normal">{{ row.label }}</dt>
                                        <dd class="col-sm-8 mb-0" style="white-space: pre-line;">{{ row.value }}</dd>
                                    </template>
                                </dl>
                            </div>

                            <!-- Documentações / laudos -->
                            <div v-if="documentations.length" class="mb-1">
                                <div class="text-uppercase text-muted fw-semibold mb-1" style="font-size:.7rem;letter-spacing:.04em;">
                                    <i class="fas fa-file-lines me-1"></i>{{ t.documentations ?? 'Documentações' }}
                                </div>
                                <ul class="list-group list-group-flush">
                                    <li v-for="doc in documentations" :key="doc.id"
                                        class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center gap-2">
                                        <div class="min-w-0">
                                            <div class="small fw-semibold text-truncate">
                                                {{ doc.title || doc.type_label }}
                                                <span v-if="doc.is_ai" class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle ms-1"
                                                      :title="doc.ai_workflow_label || 'IA'">
                                                    <i class="fas fa-robot"></i> IA
                                                </span>
                                            </div>
                                            <div class="text-muted" style="font-size:.72rem;">
                                                {{ doc.type_label }} · {{ doc.doctor_name }} · {{ doc.created_at }}
                                            </div>
                                        </div>
                                        <a v-if="doc.pdf_url" :href="doc.pdf_url" target="_blank"
                                           class="btn btn-sm btn-outline-secondary flex-shrink-0">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                    </li>
                                </ul>
                            </div>

                            <div v-if="!sections.length && !diagnosisCids.length && !documentations.length"
                                 class="text-center text-muted py-4 small">
                                {{ t.empty_record ?? 'Prontuário sem dados preenchidos.' }}
                            </div>
                        </template>
                    </div>

                    <div class="modal-footer py-2 border-0">
                        <a v-if="data?.edit_url" :href="data.edit_url" class="btn btn-sm btn-outline-primary me-auto">
                            <i class="fas fa-arrow-up-right-from-square me-1"></i>{{ t.open_full ?? 'Abrir completo' }}
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="close">
                            {{ t.close ?? 'Fechar' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
