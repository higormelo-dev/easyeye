<script setup>
import { ref, watch, computed, onMounted } from 'vue';
import { contactLensSummary } from './contactLens.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Modal somente-leitura de um prontuário anterior. Busca o JSON completo via
 * `show_url` (rota panel.patients.medicalrecords.show) e renderiza por seções,
 * exibindo apenas campos preenchidos. Nenhuma edição — visualização clínica.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    record: { type: Object, default: null }, // item resumido (tem show_url)
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const data = ref(null);
const loading = ref(false);
const errorMsg = ref('');

watch(
    () => props.open,
    (val) => {
        if (val) load();
        else {
            data.value = null;
            errorMsg.value = '';
        }
    },
);

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
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        data.value = await res.json();
    } catch (e) {
        errorMsg.value = tt('view_error', 'Não foi possível carregar o prontuário.');
        // eslint-disable-next-line no-console
        console.error('[MedicalRecordView] falha ao carregar prontuário:', e);
    } finally {
        loading.value = false;
    }
}

function close() {
    emit('close');
}

// ── Montagem das seções (apenas campos preenchidos) ────────────────────────
const tt = (key, fallback) => props.t[key] ?? fallback;
const pair = (r, l) => {
    const parts = [];
    if (r !== null && r !== undefined && r !== '') parts.push(`${tt('od', 'OD')}: ${r}`);
    if (l !== null && l !== undefined && l !== '') parts.push(`${tt('oe', 'OE')}: ${l}`);
    return parts.join('  ·  ');
};
// Antecedente: paciente e/ou família (switches independentes no form) —
// histórico só familiar também aparece.
const risk = (self, family) =>
    [self ? tt('self', 'Próprio') : '', family ? tt('family', 'Familiar') : ''].filter(Boolean).join(' · ');
const followUp = (days) => (days ? `${days} ${tt('days', 'dias')}` : '');

const { locale } = useLocaleFormat();

const sections = computed(() => {
    const d = data.value;
    if (!d) return [];

    const rows = (items) =>
        items
            .map(([label, value]) => ({ label, value }))
            .filter((x) => x.value !== null && x.value !== undefined && x.value !== '');

    const out = [];

    const anamnese = rows([
        [tt('complaint', 'Queixa principal'), d.main_complaint],
        [tt('hda_short', 'HDA'), d.hda],
        [tt('ocular_surgical_history', 'Histórico cirúrgico ocular'), d.ocular_surgical_history],
        [tt('medications_in_use', 'Medicamentos em uso'), d.medications_in_use],
        [tt('diabetic', 'Diabético'), risk(d.diabetic, d.diabetic_family)],
        [tt('hypertensive', 'Hipertenso'), risk(d.hypertensive, d.hypertensive_family)],
        [tt('glaucomatous', 'Glaucomatoso'), risk(d.glaucomatous, d.glaucomatous_family)],
    ]);
    if (anamnese.length)
        out.push({ title: tt('tab_anamnesis', 'Anamnese'), icon: 'fa-comment-medical', rows: anamnese });

    const exame = rows([
        [tt('visual_acuity', 'Acuidade visual'), d.visual_acuity_type],
        [tt('ocular_motility', 'Motilidade ocular'), d.ocular_motility],
        [tt('tonometry', 'Tonometria'), pair(d.tonometer_right, d.tonometer_left)],
        [tt('tonometry_time', 'Horário da tonometria'), d.tonometer_time],
        [tt('pachymetry', 'Paquimetria'), pair(d.pachymetry_right, d.pachymetry_left)],
        [tt('gonioscopy', 'Gonioscopia'), pair(d.gonioscopy_right, d.gonioscopy_left)],
        [tt('cover_test', 'Cover Test'), d.cover_test_type],
        [tt('chromatic_vision', 'Vis. Cromática'), d.color_vision_type],
        [tt('near_point_convergence', 'Ponto próximo de convergência'), d.near_point_convergence],
    ]);
    if (exame.length) out.push({ title: tt('tab_exam', 'Exame Físico'), icon: 'fa-eye', rows: exame });

    const refracao = rows([
        [
            tt('av_without', 'A/V sem correção'),
            pair(d.visual_acuity_without_correction_right, d.visual_acuity_without_correction_left),
        ],
        [
            tt('av_with', 'A/V com correção'),
            pair(d.visual_acuity_with_correction_right, d.visual_acuity_with_correction_left),
        ],
        [tt('dynamic_spherical', 'Esférico dinâmico'), pair(d.dynamic_spherical_right, d.dynamic_spherical_left)],
        [
            tt('dynamic_cylindrical', 'Cilíndrico dinâmico'),
            pair(d.dynamic_cylindrical_right, d.dynamic_cylindrical_left),
        ],
        [tt('dynamic_axis', 'Eixo dinâmico'), pair(d.dynamic_axis_right, d.dynamic_axis_left)],
        [tt('static_spherical', 'Esférico estático'), pair(d.static_spherical_right, d.static_spherical_left)],
        [tt('static_cylindrical', 'Cilíndrico estático'), pair(d.static_cylindrical_right, d.static_cylindrical_left)],
        [tt('static_axis', 'Eixo estático'), pair(d.static_axis_right, d.static_axis_left)],
        [tt('addition', 'Adição'), d.addition_type],
        [tt('lens_away_label', 'Lente longe'), d.lens_away],
        [tt('lens_near_label', 'Lente perto'), d.lens_near],
        // Lente de contato da consulta: sugerida por olho + teórico, linha e
        // avisos (v2); cálculo da versão 1 no formato antigo.
        ...contactLensSummary(d.contact_lens_calculation, props.t, locale.value, { detailed: true }).map((row) => [
            row.label,
            row.value,
        ]),
    ]);
    if (refracao.length) out.push({ title: tt('tab_refraction', 'Refração'), icon: 'fa-glasses', rows: refracao });

    const achados = rows([
        [tt('biomicroscopy', 'Biomicroscopia'), pair(d.biomicroscopy_right, d.biomicroscopy_left)],
        [tt('fundoscopy', 'Fundoscopia'), pair(d.fundoscopy_right, d.fundoscopy_left)],
        [tt('general_obs', 'Observação geral'), d.observation_general],
        [tt('lenses_obs', 'Observação de lentes'), d.observation_of_lenses],
    ]);
    if (achados.length) out.push({ title: tt('tab_findings', 'Achados'), icon: 'fa-magnifying-glass', rows: achados });

    const conduta = rows([
        [tt('clinical_conduct', 'Conduta clínica'), d.clinical_conduct],
        [tt('follow_up', 'Retorno'), followUp(d.follow_up_days)],
    ]);
    if (conduta.length)
        out.push({ title: tt('diagnosis_conduct', 'Diagnóstico & conduta'), icon: 'fa-notes-medical', rows: conduta });

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
        <div
            v-if="open"
            class="modal fade show d-block"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            aria-labelledby="medicalRecordViewTitle"
            style="background: rgba(15, 23, 42, 0.55)"
            @click.self="close"
        >
            <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-width: 760px">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header py-2 border-0">
                        <h6 id="medicalRecordViewTitle" class="modal-title d-flex align-items-center gap-2 mb-0">
                            <i class="fas fa-file-medical text-primary"></i>
                            {{ t.view_title ?? 'Prontuário' }}
                            <small v-if="record?.code" class="text-muted fw-normal">{{ record.code }}</small>
                        </h6>
                        <button
                            type="button"
                            class="btn-close"
                            :aria-label="tt('close', 'Fechar')"
                            @click="close"
                        ></button>
                    </div>

                    <div class="modal-body">
                        <div v-if="loading" class="text-center text-muted py-5">
                            <i class="fas fa-spinner fa-spin"></i> {{ t.loading ?? 'Carregando…' }}
                        </div>
                        <div
                            v-else-if="errorMsg"
                            class="alert alert-danger small d-flex justify-content-between align-items-center gap-2"
                        >
                            <span><i class="fas fa-circle-exclamation me-1"></i>{{ errorMsg }}</span>
                            <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" @click="load">
                                <i class="fas fa-rotate-right me-1"></i>{{ t.reload ?? 'Recarregar' }}
                            </button>
                        </div>

                        <template v-else-if="data">
                            <!-- Cabeçalho -->
                            <div
                                class="d-flex flex-wrap justify-content-between align-items-start gap-2 pb-2 mb-3 border-bottom"
                            >
                                <div>
                                    <div class="fw-bold">{{ data.created_at_formatted }}</div>
                                    <div class="small text-muted">
                                        <i class="fas fa-user-doctor me-1"></i>{{ data.doctor_name || '—' }}
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span
                                        v-if="data.is_signed"
                                        class="badge bg-success-subtle text-success border border-success-subtle"
                                    >
                                        <i class="fas fa-lock me-1"></i>{{ t.signed ?? 'Assinado' }}
                                        <span v-if="data.signed_at_formatted"> · {{ data.signed_at_formatted }}</span>
                                    </span>
                                    <a
                                        v-if="data.pdf_url"
                                        :href="data.pdf_url"
                                        target="_blank"
                                        class="badge bg-light text-danger border text-decoration-none"
                                    >
                                        <i class="fas fa-file-pdf me-1"></i>PDF
                                    </a>
                                </div>
                            </div>

                            <!-- Diagnósticos (CID) -->
                            <div v-if="diagnosisCids.length" class="mb-3">
                                <div
                                    class="text-uppercase text-muted fw-semibold mb-1"
                                    style="font-size: 0.7rem; letter-spacing: 0.04em"
                                >
                                    <i class="fas fa-notes-medical me-1"></i>{{ t.diagnoses ?? 'Diagnósticos (CID)' }}
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span
                                        v-for="(cid, i) in diagnosisCids"
                                        :key="i"
                                        class="badge bg-info-subtle text-info-emphasis border border-info-subtle"
                                        >{{ cid }}</span
                                    >
                                </div>
                            </div>

                            <!-- Seções clínicas -->
                            <div v-for="sec in sections" :key="sec.title" class="mb-3">
                                <div
                                    class="text-uppercase text-muted fw-semibold mb-1"
                                    style="font-size: 0.7rem; letter-spacing: 0.04em"
                                >
                                    <i class="fas me-1" :class="sec.icon"></i>{{ sec.title }}
                                </div>
                                <dl class="row gy-1 mb-0 small">
                                    <template v-for="row in sec.rows" :key="row.label">
                                        <dt class="col-sm-4 text-muted fw-normal">{{ row.label }}</dt>
                                        <dd class="col-sm-8 mb-0" style="white-space: pre-line">{{ row.value }}</dd>
                                    </template>
                                </dl>
                            </div>

                            <!-- Documentações / laudos -->
                            <div v-if="documentations.length" class="mb-1">
                                <div
                                    class="text-uppercase text-muted fw-semibold mb-1"
                                    style="font-size: 0.7rem; letter-spacing: 0.04em"
                                >
                                    <i class="fas fa-file-lines me-1"></i>{{ t.documentations ?? 'Documentações' }}
                                </div>
                                <ul class="list-group list-group-flush">
                                    <li
                                        v-for="doc in documentations"
                                        :key="doc.id"
                                        class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center gap-2"
                                    >
                                        <div class="min-w-0">
                                            <div class="small fw-semibold text-truncate">
                                                {{ doc.title || doc.type_label }}
                                                <span
                                                    v-if="doc.is_ai"
                                                    class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle ms-1"
                                                    :title="
                                                        doc.ai_workflow_label || tt('ai_generated', 'Gerado por IA')
                                                    "
                                                    data-ai-badge
                                                >
                                                    <i class="fas fa-robot" aria-hidden="true"></i>
                                                    {{ tt('ai_badge', 'IA') }}
                                                </span>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.72rem">
                                                {{ doc.type_label }} · {{ doc.doctor_name }} · {{ doc.created_at }}
                                            </div>
                                        </div>
                                        <a
                                            v-if="doc.pdf_url"
                                            :href="doc.pdf_url"
                                            target="_blank"
                                            class="btn btn-sm btn-outline-secondary flex-shrink-0"
                                            :aria-label="tt('open_pdf', 'Abrir PDF')"
                                            :title="tt('open_pdf', 'Abrir PDF')"
                                        >
                                            <i class="fas fa-file-pdf" aria-hidden="true"></i>
                                        </a>
                                    </li>
                                </ul>
                            </div>

                            <div
                                v-if="!sections.length && !diagnosisCids.length && !documentations.length"
                                class="text-center text-muted py-4 small"
                            >
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
