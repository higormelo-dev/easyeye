<script setup>
import { reactive, ref, watch } from 'vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';

/**
 * Laudo manual do Gerenciador de Imagens ("Modelos" — ver vídeo de
 * referência do ticket): médico escolhe um modelo pronto por patologia
 * (reaproveita o MESMO catálogo ReportSetting/ReportSettingContent das
 * Documentações do prontuário, filtrado no backend a laudos/exames
 * especializados), edita livremente e salva. Vira MedicalRecordDocumentation
 * — mesma tabela/PDF/histórico do laudo de IA — via EyeImageReportController.
 *
 * Ancoragem no prontuário: se não há prontuário do dia da consulta, o
 * backend devolve 422 + requires_record_confirmation e este componente
 * pergunta antes de reenviar com confirm_open_record=true (mesmo padrão do
 * "Analisar com IA").
 */
const props = defineProps({
    open:     { type: Boolean, required: true },
    patient:  { type: Object,  default: null }, // { id, code, name }
    examIds:  { type: Array,   default: () => [] },
    urls:     { type: Object,  required: true }, // { templates, preview, store }
    t:        { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

const templates        = ref([]);
const loadingTemplates = ref(false);
const previewing       = ref(false);
const saving           = ref(false);
const error            = ref('');
const savedResult      = ref(null); // { pdf_url, title } após salvar

// Sem campo de Título na tela — o título é sempre derivado do modelo
// escolhido (ou fica em branco/"Em branco", e o backend aplica um título
// padrão). Menos um campo pro médico preencher à toa.
const form = reactive({
    report_setting_content_id: '',
    title:   '',
    content: '',
});

function reset() {
    form.report_setting_content_id = '';
    form.title   = '';
    form.content = '';
    error.value        = '';
    savedResult.value  = null;
}

async function fetchTemplates() {
    loadingTemplates.value = true;
    try {
        const { data } = await window.axios.get(props.urls.templates);
        templates.value = data?.data ?? [];
    } catch {
        templates.value = [];
    } finally {
        loadingTemplates.value = false;
    }
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        reset();
        fetchTemplates();
    }
});

async function onTemplateChange() {
    if (!form.report_setting_content_id || !props.patient?.id) return;

    previewing.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(props.urls.preview, {
            report_setting_content_id: form.report_setting_content_id,
            patient_id: props.patient.id,
            exam_ids: props.examIds,
        });
        form.content = data?.content ?? '';

        const tpl = templates.value
            .flatMap((g) => g.contents || [])
            .find((c) => c.id === form.report_setting_content_id);
        form.title = tpl?.label ?? '';
    } catch (e) {
        error.value = e?.response?.data?.message ?? tt('report_save_failed', 'Não foi possível carregar o modelo.');
    } finally {
        previewing.value = false;
    }
}

async function confirmOpenRecord(consultationDate) {
    const message = tt('report_confirm_open_record',
        'Não há prontuário do dia da consulta para este paciente. Deseja abrir um novo prontuário para registrar o laudo?');

    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'question',
            title: message,
            text: consultationDate ? `Data: ${consultationDate}` : undefined,
            showCancelButton: true,
            confirmButtonText: tt('report_save', 'Salvar laudo'),
            cancelButtonText: tt('cancel', 'Cancelar'),
        });
        return result.isConfirmed;
    }

    return window.confirm(message);
}

async function save(confirmOpen = false) {
    if (!props.patient?.id) return;

    const plain = form.content.replace(/<[^>]*>/g, '').trim();
    if (!plain) {
        error.value = tt('report_content_required', 'Escreva o conteúdo do laudo antes de salvar.');
        return;
    }

    saving.value = true;
    error.value  = '';

    try {
        const { data } = await window.axios.post(props.urls.store, {
            patient_id: props.patient.id,
            exam_ids: props.examIds,
            report_setting_content_id: form.report_setting_content_id || null,
            title: form.title || null,
            content: form.content,
            confirm_open_record: confirmOpen,
        });

        savedResult.value = { pdf_url: data.pdf_url, title: data.title };
        if (window.showSuccessToast) window.showSuccessToast(tt('report_saved', 'Laudo salvo com sucesso.'));
        emit('saved', data);
    } catch (e) {
        const payload = e?.response?.data;

        if (e?.response?.status === 422 && payload?.requires_record_confirmation) {
            const confirmed = await confirmOpenRecord(payload.consultation_date);
            saving.value = false;
            if (confirmed) return save(true);
            return;
        }

        error.value = payload?.message ?? tt('report_save_failed', 'Não foi possível salvar o laudo.');
    } finally {
        saving.value = false;
    }
}

function close() {
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55);"
             @click.self="close">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title">
                            <i class="ti ti-file-text me-2 text-primary"></i>{{ tt('report_new', 'Novo laudo') }}
                            <span v-if="patient" class="text-muted fw-normal ms-1" style="font-size:.82rem;">
                                — {{ patient.name }}
                            </span>
                        </h6>
                        <button type="button" class="btn-close" @click="close"></button>
                    </div>

                    <div class="modal-body">
                        <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

                        <template v-if="!savedResult">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">{{ tt('report_templates', 'Modelos') }}</label>
                                <select v-model="form.report_setting_content_id"
                                        class="form-select form-select-sm"
                                        :disabled="loadingTemplates || previewing"
                                        @change="onTemplateChange">
                                    <option value="">{{ tt('report_template_blank', 'Em branco') }}</option>
                                    <optgroup v-for="group in templates" :key="group.report_setting_id" :label="group.report_setting_title">
                                        <option v-for="tpl in (group.contents || [])" :key="tpl.id" :value="tpl.id">
                                            {{ tpl.label }}
                                        </option>
                                    </optgroup>
                                </select>
                                <small v-if="loadingTemplates" class="text-muted d-block mt-1">
                                    <span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;"></span>
                                    {{ tt('report_loading_templates', 'Carregando modelos…') }}
                                </small>
                                <small v-else-if="!templates.length" class="text-muted d-block mt-1">
                                    {{ tt('report_no_templates', 'Nenhum modelo disponível.') }}
                                </small>
                            </div>

                            <div class="mb-0">
                                <label class="form-label small fw-semibold d-flex align-items-center gap-2">
                                    {{ tt('report_content_label', 'Conteúdo do laudo') }}
                                    <span v-if="previewing" class="text-muted fw-normal" style="font-size:.75rem;">
                                        <span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;"></span>
                                        {{ tt('report_loading_templates', 'Carregando modelo…') }}
                                    </span>
                                </label>
                                <!--
                                    Montado UMA vez só (sem :key trocando por modelo) e mantido
                                    vivo pelo resto da vida do modal — nunca desmonta/remonta ao
                                    trocar de modelo. Duas corridas reais já vieram de remontar
                                    isso: (1) TinyMCE reinicializando no meio do fetch do preview
                                    deixava o <textarea> nativo pequeno aparecendo sem conteúdo;
                                    (2) trocar o :key forçava o Vue a desmontar/remontar o editor
                                    no mesmo tick em que o TinyMCE mexe no DOM da textarea pra
                                    montar seu iframe — o unmount do Vue corrida com essa
                                    manipulação e corrompe o vnode tracking (erros
                                    "insertBefore"/"Cannot set properties of null" no console).
                                    O conteúdo do modelo chega no editor JÁ MONTADO via v-model
                                    (TinyMceEditor sincroniza mudanças externas via setContent).
                                -->
                                <TinyMceEditor
                                    v-model="form.content"
                                    :height="360"
                                    :disabled="previewing"
                                    :placeholder="tt('report_content_label', 'Conteúdo do laudo')"
                                />
                            </div>
                        </template>

                        <!-- Pós-salvar: confirmação + link do PDF já gerado -->
                        <div v-else class="text-center py-4">
                            <i class="ti ti-circle-check text-success" style="font-size:2.6rem;"></i>
                            <p class="fw-semibold mt-2 mb-1">{{ tt('report_saved', 'Laudo salvo com sucesso.') }}</p>
                            <p class="text-muted small mb-3">{{ savedResult.title }}</p>
                            <a :href="savedResult.pdf_url" target="_blank" class="btn btn-primary btn-sm">
                                <i class="ti ti-file-download me-1"></i>{{ tt('download_pdf', 'Baixar PDF') }}
                            </a>
                        </div>
                    </div>

                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="close">
                            {{ tt('close', 'Fechar') }}
                        </button>
                        <button v-if="!savedResult" type="button" class="btn btn-primary btn-sm"
                                :disabled="saving || previewing"
                                @click="save(false)">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="ti ti-device-floppy me-1"></i>{{ tt('report_save', 'Salvar laudo') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
