<script setup>
import { reactive, ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
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
    open: { type: Boolean, required: true },
    patient: { type: Object, default: null }, // { id, code, name }
    // Com examGroups: exames que já abrem MARCADOS. Sem examGroups: exames
    // do laudo (uso fora do visualizador).
    examIds: { type: Array, default: () => [] },
    urls: { type: Object, required: true }, // { templates, preview, store }
    t: { type: Object, default: () => ({}) },
    // Imagens do(s) exame(s) — sem examGroups — botão "Inserir imagem do exame"
    // (adaptação do "Auto Load Image" do concorrente: aqui o editor é um
    // bloco de rich-text só, sem campos OD/OE endereçáveis, então em vez de
    // carregar automaticamente NUM campo específico, o médico insere a
    // imagem no cursor de onde estiver escrevendo).
    examImages: { type: Array, default: () => [] }, // [{ id, url, label }]
    // Exames abertos no visualizador que PODEM entrar no laudo — o médico
    // marca exame e olho (OD/OE/AO) que entram (um ou vários: laudo
    // conjunto). Cada um: { key, label, eyes: [{ key, label, examIds
    // (imagens ativas daquele olho), images: [{ id, url, label }] }] }.
    examGroups: { type: Array, default: () => [] },
    // Encaixado à direita do visualizador de imagens (fluxo do EyeImages:
    // painéis visíveis à esquerda enquanto o médico escreve) em vez de modal
    // centralizado com fundo escuro.
    docked: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'saved']);

// Olhos marcados pelo médico em "Exames neste laudo" ("grupo|olho"). Abre
// com os olhos que têm imagem em examIds; exame que aparecer depois
// (painel trocado no visualizador) entra desmarcado.
const checkedEyeKeys = ref([]);
const eyeKey = (group, eye) => `${group.key}|${eye.key}`;

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        const initial = new Set(props.examIds);
        checkedEyeKeys.value = props.examGroups.flatMap((g) =>
            g.eyes.filter((e) => e.examIds.some((id) => initial.has(id))).map((e) => eyeKey(g, e)),
        );
    },
    { immediate: true },
);

const checkedEyes = computed(() =>
    props.examGroups.flatMap((g) => g.eyes.filter((e) => checkedEyeKeys.value.includes(eyeKey(g, e)))),
);
const selectedExamIds = computed(() =>
    props.examGroups.length ? checkedEyes.value.flatMap((e) => e.examIds) : props.examIds,
);
const availableImages = computed(() =>
    props.examGroups.length ? checkedEyes.value.flatMap((e) => e.images ?? []) : props.examImages,
);
const noExamChecked = computed(() => props.examGroups.length > 0 && selectedExamIds.value.length === 0);

// Caixa do exame: marca/desmarca todos os olhos dele (indeterminada quando
// só parte dos olhos está marcada).
function groupState(group) {
    const enabled = group.eyes.filter((e) => e.examIds.length);
    const checked = enabled.filter((e) => checkedEyeKeys.value.includes(eyeKey(group, e))).length;
    return { all: enabled.length > 0 && checked === enabled.length, some: checked > 0, enabled };
}

function toggleGroup(group) {
    const { all, enabled } = groupState(group);
    const keys = enabled.map((e) => eyeKey(group, e));
    checkedEyeKeys.value = all
        ? checkedEyeKeys.value.filter((k) => !keys.includes(k))
        : [...new Set([...checkedEyeKeys.value, ...keys])];
}

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

const templates = ref([]);
const loadingTemplates = ref(false);
const previewing = ref(false);
const saving = ref(false);
const error = ref('');
const savedResult = ref(null); // { pdf_url, title } após salvar
const templateSelectRef = ref(null);
const editorRef = ref(null);
const showImagePicker = ref(false);
const imagePickerRef = ref(null);

// Frases rápidas do médico (benchmark 18/09/2026) — dropdown irmão do de
// imagem, mesmo padrão de fechar ao clicar fora.
const phrases = ref([]);
const loadingPhrases = ref(false);
const showPhrasesPicker = ref(false);
const phrasesPickerRef = ref(null);

function onDocumentClick(event) {
    if (showImagePicker.value && imagePickerRef.value && !imagePickerRef.value.contains(event.target)) {
        showImagePicker.value = false;
    }
    if (showPhrasesPicker.value && phrasesPickerRef.value && !phrasesPickerRef.value.contains(event.target)) {
        showPhrasesPicker.value = false;
    }
}
onMounted(() => document.addEventListener('click', onDocumentClick, true));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick, true));

async function fetchPhrases() {
    if (!props.urls.phrasesIndex) return;
    loadingPhrases.value = true;
    try {
        const { data } = await window.axios.get(props.urls.phrasesIndex);
        phrases.value = data?.data ?? [];
    } catch {
        phrases.value = [];
    } finally {
        loadingPhrases.value = false;
    }
}

function insertPhrase(phrase) {
    editorRef.value?.insertContent(phrase.content);
    showPhrasesPicker.value = false;
}

async function savePhraseFromSelection() {
    const selection = (editorRef.value?.getSelectionHtml() ?? '').trim();
    if (!selection) {
        if (window.showErrorToast)
            window.showErrorToast(
                tt('report_phrases_save_hint', 'Selecione um trecho do texto acima antes de salvar como frase.'),
            );
        return;
    }

    let label = '';
    if (window.Swal) {
        const result = await window.Swal.fire({
            title: tt('report_phrases_label_title', 'Rótulo da frase'),
            input: 'text',
            inputValidator: (v) => (!v || !v.trim() ? ' ' : undefined),
            showCancelButton: true,
            confirmButtonText: tt('save', 'Salvar'),
            cancelButtonText: tt('cancel', 'Cancelar'),
        });
        if (!result.isConfirmed) return;
        label = result.value.trim();
    } else {
        label = window.prompt(tt('report_phrases_label_title', 'Rótulo da frase')) ?? '';
        if (!label.trim()) return;
        label = label.trim();
    }

    try {
        const { data } = await window.axios.post(props.urls.phrasesStore, { label, content: selection });
        phrases.value.push(data);
        if (window.showSuccessToast) window.showSuccessToast(tt('report_phrases_saved', 'Frase salva.'));
    } catch (e) {
        if (window.showErrorToast)
            window.showErrorToast(e?.response?.data?.message ?? tt('report_save_failed', 'Não foi possível salvar.'));
    }
}

// Extração de texto do PDF nativo do equipamento (benchmark 18/09/2026) —
// texto LITERAL do PDF do fabricante, nunca interpretado/resumido (ver
// PdfTextExtractionService). Botão sempre visível quando há exam_ids: mais
// simples que o front adivinhar extensão de arquivo, e um 422 "sem texto"
// é um resultado normal (imagem, não PDF), não um erro de verdade.
const extractingPdf = ref(false);

async function extractPdfText() {
    if (!props.urls.extractPdfText || !selectedExamIds.value.length || extractingPdf.value) return;
    extractingPdf.value = true;
    try {
        const { data } = await window.axios.post(props.urls.extractPdfText, { exam_ids: selectedExamIds.value });
        editorRef.value?.insertContent(
            data.text
                .split(/\n{2,}/)
                .map((p) => `<p>${escapeHtml(p).replace(/\n/g, '<br>')}</p>`)
                .join(''),
        );
    } catch (e) {
        if (window.showErrorToast)
            window.showErrorToast(
                e?.response?.data?.message ?? tt('pdf_extract_failed', 'Não foi possível extrair o texto do PDF.'),
            );
    } finally {
        extractingPdf.value = false;
    }
}

async function deletePhrase(phrase) {
    try {
        await window.axios.delete(props.urls.phrasesDestroy.replace('__ID__', phrase.id));
        phrases.value = phrases.value.filter((p) => p.id !== phrase.id);
        if (window.showSuccessToast) window.showSuccessToast(tt('report_phrases_deleted', 'Frase removida.'));
    } catch (e) {
        if (window.showErrorToast)
            window.showErrorToast(e?.response?.data?.message ?? tt('report_save_failed', 'Não foi possível remover.'));
    }
}

// alt vem de exam_type.name (cadastro configurável pela clínica, não é
// literal fixo) — escapar antes de injetar como atributo HTML.
function escapeHtml(s) {
    return String(s).replace(
        /[&<>"']/g,
        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
    );
}

function insertImage(img) {
    editorRef.value?.insertContent(
        `<p><img src="${img.url}" alt="${escapeHtml(img.label)}" style="max-width:320px;display:block;margin:.5rem 0;" /></p>`,
    );
    showImagePicker.value = false;
}

// Sem campo de Título na tela — o título é sempre derivado do modelo
// escolhido (ou fica em branco/"Em branco", e o backend aplica um título
// padrão). Menos um campo pro médico preencher à toa.
const form = reactive({
    report_setting_content_id: '',
    title: '',
    content: '',
});

function reset() {
    form.report_setting_content_id = '';
    form.title = '';
    form.content = '';
    error.value = '';
    savedResult.value = null;
}

async function fetchTemplates() {
    loadingTemplates.value = true;
    try {
        const { data } = await window.axios.get(props.urls.templates);
        templates.value = data?.data ?? [];
    } catch {
        templates.value = [];
        // Distinto de "clínica sem modelos cadastrados" — sem isso o médico
        // via só "Nenhum modelo disponível." e achava que era o esperado.
        error.value = tt(
            'report_templates_load_failed',
            'Não foi possível carregar os modelos. Você pode escrever o laudo do zero ou fechar e tentar novamente.',
        );
    } finally {
        loadingTemplates.value = false;
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            reset();
            fetchTemplates();
            fetchPhrases();
            nextTick(() => templateSelectRef.value?.focus());
        }
    },
);

async function onTemplateChange() {
    if (!form.report_setting_content_id || !props.patient?.id) return;

    previewing.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(props.urls.preview, {
            report_setting_content_id: form.report_setting_content_id,
            patient_id: props.patient.id,
            exam_ids: selectedExamIds.value,
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
    const message = tt(
        'report_confirm_open_record',
        'Não há prontuário do dia da consulta para este paciente. Deseja abrir um novo prontuário para registrar o laudo?',
    );

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

    if (noExamChecked.value) {
        error.value = tt('report_select_exam', 'Marque ao menos um exame para laudar.');
        return;
    }

    const plain = form.content.replace(/<[^>]*>/g, '').trim();
    if (!plain) {
        error.value = tt('report_content_required', 'Escreva o conteúdo do laudo antes de salvar.');
        return;
    }

    saving.value = true;
    error.value = '';

    try {
        const { data } = await window.axios.post(props.urls.store, {
            patient_id: props.patient.id,
            exam_ids: selectedExamIds.value,
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

// Conteúdo digitado e ainda não salvo não pode desaparecer num clique
// acidental no backdrop/Esc — mesmo risco que confirmOpenRecord já cobre
// pro caso de prontuário ausente, mas aqui é perda de digitação mesmo.
function isDirty() {
    if (savedResult.value) return false;
    return !!form.content.replace(/<[^>]*>/g, '').trim();
}

async function confirmDiscard() {
    const message = tt('report_discard_text', 'O conteúdo digitado será perdido.');
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            title: tt('report_discard_title', 'Descartar laudo não salvo?'),
            text: message,
            showCancelButton: true,
            confirmButtonText: tt('report_discard_confirm', 'Descartar'),
            cancelButtonText: tt('cancel', 'Cancelar'),
            confirmButtonColor: '#dc3545',
        });
        return result.isConfirmed;
    }
    return window.confirm(message);
}

async function close() {
    if (isDirty() && !(await confirmDiscard())) return;
    emit('close');
}

// Encaixado, o laudo fica acima do visualizador (z-index 9998): SweetAlert
// (1060) e menus do TinyMCE (1300) ficariam escondidos atrás dele — a
// classe no body sobe os dois (estilo global no fim do arquivo).
watch(
    () => props.open && props.docked,
    (on) => document.body.classList.toggle('ei-report-docked', on),
    { immediate: true },
);
onBeforeUnmount(() => document.body.classList.remove('ei-report-docked'));

// Visualizador fecha o laudo pelo mesmo caminho do X (pergunta antes de
// descartar rascunho).
defineExpose({ close });
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            :class="docked ? 'ei-report-dock' : 'modal fade show d-block'"
            tabindex="-1"
            :style="docked ? null : 'background: rgba(0, 0, 0, 0.55)'"
            role="dialog"
            :aria-modal="docked ? 'false' : 'true'"
            aria-labelledby="eyeReportModalTitle"
            @click.self="docked || close()"
            @keydown.escape.window="close"
        >
            <div :class="docked ? 'h-100' : 'modal-dialog modal-lg modal-dialog-scrollable'">
                <div class="modal-content" :class="{ 'h-100 rounded-0 border-0': docked }">
                    <div class="modal-header py-2">
                        <h6 id="eyeReportModalTitle" class="modal-title">
                            <i class="ti ti-file-text me-2 text-primary"></i>{{ tt('report_new', 'Novo laudo') }}
                            <span v-if="patient" class="text-muted fw-normal ms-1" style="font-size: 0.82rem">
                                — {{ patient.name }}
                            </span>
                        </h6>
                        <button type="button" class="btn-close" @click="close"></button>
                    </div>

                    <div v-if="examGroups.length && !savedResult" class="px-3 py-2 border-bottom small">
                        <div class="text-muted fw-semibold mb-1">
                            <i class="ti ti-stack-2 me-1"></i>{{ tt('report_exams_label', 'Exames neste laudo') }}
                            <span class="fw-normal">— {{ tt('report_exams_hint', 'marque quais entram') }}</span>
                        </div>
                        <ul class="list-unstyled mb-0 d-flex flex-column gap-1">
                            <li
                                v-for="group in examGroups"
                                :key="group.key"
                                class="d-flex align-items-center flex-wrap gap-2 border rounded px-2 py-1"
                                :class="groupState(group).some ? 'border-primary bg-primary-subtle' : ''"
                                style="font-size: 0.74rem"
                            >
                                <label class="d-inline-flex align-items-center gap-1 mb-0" style="cursor: pointer">
                                    <input
                                        type="checkbox"
                                        class="form-check-input m-0"
                                        :checked="groupState(group).all"
                                        :indeterminate="groupState(group).some && !groupState(group).all"
                                        :disabled="!groupState(group).enabled.length"
                                        @change="toggleGroup(group)"
                                    />
                                    <span>{{ group.label }}</span>
                                </label>
                                <span class="d-inline-flex gap-1 ms-auto">
                                    <label
                                        v-for="eye in group.eyes"
                                        :key="eye.key"
                                        class="d-inline-flex align-items-center gap-1 mb-0 border rounded px-1 bg-body"
                                        style="cursor: pointer"
                                    >
                                        <input
                                            v-model="checkedEyeKeys"
                                            type="checkbox"
                                            class="form-check-input m-0"
                                            :value="eyeKey(group, eye)"
                                            :disabled="!eye.examIds.length"
                                        />
                                        {{ eye.label }}
                                    </label>
                                </span>
                            </li>
                        </ul>
                        <small v-if="noExamChecked" class="text-danger d-block mt-1">
                            {{ tt('report_select_exam', 'Marque ao menos um exame para laudar.') }}
                        </small>
                    </div>

                    <div class="modal-body" :class="{ 'overflow-auto': docked }">
                        <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

                        <template v-if="!savedResult">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">{{
                                    tt('report_templates', 'Modelos')
                                }}</label>
                                <select
                                    ref="templateSelectRef"
                                    v-model="form.report_setting_content_id"
                                    class="form-select form-select-sm"
                                    :disabled="loadingTemplates || previewing"
                                    @change="onTemplateChange"
                                >
                                    <option value="">{{ tt('report_template_blank', 'Em branco') }}</option>
                                    <optgroup
                                        v-for="group in templates"
                                        :key="group.report_setting_id"
                                        :label="group.report_setting_title"
                                    >
                                        <option v-for="tpl in group.contents || []" :key="tpl.id" :value="tpl.id">
                                            {{ tpl.label }}
                                        </option>
                                    </optgroup>
                                </select>
                                <small v-if="loadingTemplates" class="text-muted d-block mt-1">
                                    <span
                                        class="spinner-border spinner-border-sm me-1"
                                        style="width: 0.7rem; height: 0.7rem"
                                    ></span>
                                    {{ tt('report_loading_templates', 'Carregando modelos…') }}
                                </small>
                                <small v-else-if="!templates.length" class="text-muted d-block mt-1">
                                    {{ tt('report_no_templates', 'Nenhum modelo disponível.') }}
                                </small>
                            </div>

                            <div class="mb-0">
                                <label class="form-label small fw-semibold d-flex align-items-center gap-2">
                                    {{ tt('report_content_label', 'Conteúdo do laudo') }}
                                    <span v-if="previewing" class="text-muted fw-normal" style="font-size: 0.75rem">
                                        <span
                                            class="spinner-border spinner-border-sm me-1"
                                            style="width: 0.7rem; height: 0.7rem"
                                        ></span>
                                        {{ tt('report_loading_templates', 'Carregando modelo…') }}
                                    </span>

                                    <span class="ms-auto d-flex align-items-center gap-1">
                                        <span
                                            v-if="availableImages.length"
                                            ref="imagePickerRef"
                                            class="position-relative"
                                        >
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                style="font-size: 0.72rem"
                                                @click="showImagePicker = !showImagePicker"
                                            >
                                                <i class="ti ti-photo-plus me-1"></i
                                                >{{ tt('report_insert_image', 'Inserir imagem') }}
                                            </button>
                                            <div
                                                v-if="showImagePicker"
                                                class="position-absolute end-0 mt-1 bg-body border rounded shadow-sm py-1"
                                                style="z-index: 20; min-width: 220px"
                                            >
                                                <button
                                                    v-for="img in availableImages"
                                                    :key="img.id"
                                                    type="button"
                                                    class="dropdown-item d-flex align-items-center gap-2 py-1 px-2 small w-100 text-start border-0 bg-transparent"
                                                    @click="insertImage(img)"
                                                >
                                                    <img
                                                        :src="img.url"
                                                        :alt="img.label"
                                                        width="36"
                                                        height="28"
                                                        style="object-fit: cover; border-radius: 3px; flex-shrink: 0"
                                                    />
                                                    <span class="text-truncate">{{ img.label }}</span>
                                                </button>
                                            </div>
                                        </span>

                                        <span ref="phrasesPickerRef" class="position-relative">
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                style="font-size: 0.72rem"
                                                @click="showPhrasesPicker = !showPhrasesPicker"
                                            >
                                                <i class="ti ti-message-2 me-1"></i
                                                >{{ tt('report_phrases', 'Frases rápidas') }}
                                            </button>
                                            <div
                                                v-if="showPhrasesPicker"
                                                class="position-absolute end-0 mt-1 bg-body border rounded shadow-sm py-1"
                                                style="
                                                    z-index: 20;
                                                    min-width: 240px;
                                                    max-height: 260px;
                                                    overflow-y: auto;
                                                "
                                            >
                                                <button
                                                    type="button"
                                                    class="dropdown-item d-flex align-items-center gap-2 py-1 px-2 small w-100 text-start border-0 bg-transparent text-primary"
                                                    @click="savePhraseFromSelection"
                                                >
                                                    <i class="ti ti-plus"></i
                                                    >{{ tt('report_phrases_save', 'Salvar seleção como frase') }}
                                                </button>
                                                <div class="dropdown-divider my-1"></div>
                                                <div v-if="loadingPhrases" class="px-2 py-1 small text-muted">
                                                    <span
                                                        class="spinner-border spinner-border-sm me-1"
                                                        style="width: 0.7rem; height: 0.7rem"
                                                    ></span>
                                                </div>
                                                <div v-else-if="!phrases.length" class="px-2 py-1 small text-muted">
                                                    {{ tt('report_phrases_empty', 'Nenhuma frase salva ainda.') }}
                                                </div>
                                                <div
                                                    v-for="phrase in phrases"
                                                    :key="phrase.id"
                                                    class="d-flex align-items-center gap-1 px-1"
                                                >
                                                    <button
                                                        type="button"
                                                        class="dropdown-item py-1 px-1 small flex-grow-1 text-start border-0 bg-transparent text-truncate"
                                                        :title="phrase.label"
                                                        @click="insertPhrase(phrase)"
                                                    >
                                                        {{ phrase.label }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm p-0 border-0 bg-transparent text-muted"
                                                        :title="tt('delete', 'Excluir')"
                                                        @click="deletePhrase(phrase)"
                                                    >
                                                        <i class="ti ti-trash" style="font-size: 0.75rem"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </span>

                                        <button
                                            v-if="selectedExamIds.length"
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm py-0 px-2"
                                            style="font-size: 0.72rem"
                                            :disabled="extractingPdf"
                                            @click="extractPdfText"
                                        >
                                            <span
                                                v-if="extractingPdf"
                                                class="spinner-border spinner-border-sm me-1"
                                                style="width: 0.65rem; height: 0.65rem"
                                            ></span>
                                            <i v-else class="ti ti-file-text-ai me-1"></i>
                                            {{
                                                extractingPdf
                                                    ? tt('pdf_extracting', 'Extraindo texto do PDF…')
                                                    : tt('pdf_extract_text', 'Extrair texto do PDF')
                                            }}
                                        </button>
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
                                    ref="editorRef"
                                    v-model="form.content"
                                    :height="360"
                                    :disabled="previewing"
                                    :placeholder="tt('report_content_label', 'Conteúdo do laudo')"
                                />
                            </div>
                        </template>

                        <!-- Pós-salvar: confirmação + link do PDF já gerado -->
                        <div v-else class="text-center py-4">
                            <i class="ti ti-circle-check text-success" style="font-size: 2.6rem"></i>
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
                        <button
                            v-if="!savedResult"
                            type="button"
                            class="btn btn-primary btn-sm"
                            :disabled="saving || previewing || noExamChecked"
                            @click="save(false)"
                        >
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="ti ti-device-floppy me-1"></i>{{ tt('report_save', 'Salvar laudo') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.ei-report-dock {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: var(--ei-report-dock-width, min(46vw, 760px));
    z-index: 10001;
    box-shadow: -6px 0 18px rgba(0, 0, 0, 0.45);
}
.ei-report-dock .modal-content {
    display: flex;
    flex-direction: column;
}
.ei-report-dock .modal-body {
    flex: 1 1 auto;
    min-height: 0;
}
@media (max-width: 991.98px) {
    .ei-report-dock {
        width: 100%;
    }
}
</style>

<style>
body.ei-report-docked .swal2-container,
body.ei-report-docked .tox-tinymce-aux {
    z-index: 10020 !important;
}
</style>
