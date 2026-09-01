<script setup>
/**
 * Painel "Exames de imagem" do prontuário: mostra os exames do módulo Eye
 * Images do PACIENTE em atendimento (miniaturas, tipo, data, olho, CIDs) com
 * visualização ampliada inline — o médico consulta as imagens sem sair da
 * consulta. Dados de GET urls.eye_exams (leitura registrada p/ LGPD).
 */
import { ref, watch } from 'vue';

const props = defineProps({
    show:      { type: Boolean, default: false },
    fetchUrl:  { type: String, required: true },
    moduleUrl: { type: String, default: '' },
    t:         { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const loading  = ref(false);
const error    = ref('');
const exams    = ref([]);
const selected = ref(null); // exame em visualização ampliada
const loaded   = ref(false);

function tt(key, fallback) {
    const v = props.t?.[key];
    return typeof v === 'string' && v !== '' ? v : fallback;
}

watch(() => props.show, async (open) => {
    if (!open) return;
    selected.value = null;
    if (loaded.value) return; // URLs presigned valem 2h — não refazer a cada abertura

    loading.value = true;
    error.value = '';
    try {
        const res = await fetch(props.fetchUrl, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(String(res.status));
        const json = await res.json();
        exams.value = Array.isArray(json.exams) ? json.exams : [];
        loaded.value = true;
        if (exams.value.length) selected.value = exams.value[0];
    } catch {
        error.value = tt('imaging_error', 'Não foi possível carregar os exames. Tente novamente.');
    } finally {
        loading.value = false;
    }
});

function pick(exam) {
    selected.value = exam;
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="modal fade show d-block" style="background: rgba(15, 23, 42, .45);"
             role="dialog" aria-modal="true" @click.self="emit('close')">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h5 class="modal-title">
                            <i class="fas fa-x-ray me-2 text-primary"></i>{{ tt('imaging_title', 'Exames de imagem do paciente') }}
                        </h5>
                        <a v-if="moduleUrl" :href="moduleUrl" target="_blank" rel="noopener"
                           class="btn btn-outline-secondary btn-sm ms-auto me-2">
                            <i class="fas fa-external-link-alt me-1"></i>{{ tt('imaging_open_module', 'Abrir módulo de imagens') }}
                        </a>
                        <button type="button" class="btn-close" @click="emit('close')"></button>
                    </div>

                    <div class="modal-body">
                        <div v-if="loading" class="text-center text-muted py-5">
                            <span class="spinner-border spinner-border-sm me-2"></span>
                            {{ tt('imaging_loading', 'Carregando exames…') }}
                        </div>

                        <div v-else-if="error" class="alert alert-danger py-2 small mb-0">
                            <i class="fas fa-exclamation-triangle me-1"></i>{{ error }}
                        </div>

                        <div v-else-if="!exams.length" class="text-center text-muted py-5">
                            <i class="fas fa-eye-slash d-block fs-3 mb-2"></i>
                            {{ tt('imaging_empty', 'Nenhum exame de imagem cadastrado para este paciente.') }}
                        </div>

                        <div v-else class="row g-3">
                            <!-- Visualização ampliada -->
                            <div class="col-12 col-lg-8">
                                <div v-if="selected" class="border rounded p-2 bg-light h-100 d-flex flex-column">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                                        <span class="fw-semibold">{{ selected.exam_type ?? tt('imaging_exam', 'Exame') }}</span>
                                        <span class="badge bg-primary-subtle text-primary border border-primary">{{ selected.laterality }}</span>
                                        <span v-if="selected.performed_at" class="text-muted">{{ selected.performed_at }}</span>
                                        <span v-if="selected.source" class="badge bg-secondary-subtle text-secondary border border-secondary">{{ selected.source }}</span>
                                        <span v-for="cid in selected.diagnosis" :key="cid"
                                              class="badge bg-info-subtle text-info border border-info">{{ cid }}</span>
                                        <a v-if="selected.original_url" :href="selected.original_url" target="_blank" rel="noopener"
                                           class="btn btn-outline-secondary btn-sm ms-auto py-0">
                                            <i class="fas fa-download me-1"></i>{{ selected.is_pdf ? 'PDF' : tt('imaging_original', 'Original') }}
                                        </a>
                                    </div>
                                    <div class="flex-grow-1 d-flex align-items-center justify-content-center overflow-hidden">
                                        <img v-if="selected.display_url" :src="selected.display_url" :alt="selected.exam_type ?? 'Exame'"
                                             class="img-fluid rounded" style="max-height: 62vh; object-fit: contain;">
                                        <div v-else class="text-muted small py-5">
                                            {{ tt('imaging_no_preview', 'Sem visualização — use o arquivo original.') }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Lista/miniaturas -->
                            <div class="col-12 col-lg-4">
                                <div class="list-group overflow-auto" style="max-height: 66vh;">
                                    <button v-for="exam in exams" :key="exam.id" type="button"
                                            class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2"
                                            :class="{ active: selected && selected.id === exam.id }"
                                            @click="pick(exam)">
                                        <img v-if="exam.thumb_url" :src="exam.thumb_url" alt=""
                                             class="rounded border flex-shrink-0"
                                             style="width: 56px; height: 42px; object-fit: cover;">
                                        <span v-else class="rounded border bg-light d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                              style="width: 56px; height: 42px;"><i class="fas fa-file-medical text-muted"></i></span>
                                        <span class="text-start small lh-sm">
                                            <span class="d-block fw-semibold text-truncate" style="max-width: 180px;">
                                                {{ exam.exam_type ?? tt('imaging_exam', 'Exame') }}
                                            </span>
                                            <span class="d-block" :class="selected && selected.id === exam.id ? '' : 'text-muted'">
                                                {{ exam.laterality }}<template v-if="exam.performed_at"> · {{ exam.performed_at }}</template>
                                            </span>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
