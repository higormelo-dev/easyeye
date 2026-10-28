<script setup>
import { reactive, ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';
import DocumentDateField from '@/Components/Panel/Documents/DocumentDateField.vue';
import DictationButton from '@/Components/Panel/DictationButton.vue';
import { documentDatePayload, fill } from '@/Support/clinicalDocuments.js';
import { dictationToHtml } from '@/Support/dictation.js';
import { useEscapeToClose } from '@/Support/useEscapeToClose.js';

/**
 * Laudo manual do Gerenciador de exames ("Modelos" — ver vídeo de
 * referência do ticket): médico escolhe um modelo pronto por patologia
 * (reaproveita o MESMO catálogo ReportSetting/ReportSettingContent das
 * Documentações do prontuário, filtrado no backend a laudos/exames
 * especializados), edita livremente e salva. Vira MedicalRecordDocumentation
 * — mesma tabela/PDF/histórico do laudo de IA — via ExamManagerReportController.
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
    // Encaixado à direita do visualizador de imagens (fluxo do ExamManager:
    // painéis visíveis à esquerda enquanto o médico escreve) em vez de modal
    // centralizado com fundo escuro.
    docked: { type: Boolean, default: false },
    // Data alternativa (Fase 5) — { today, min, max }, DocumentDate::clientConfig().
    dateConfig: { type: Object, default: () => ({}) },
    // Rascunho sendo continuado (Fase 5 — fluxo secretária-rascunha /
    // médico-emite): pré-popula o form. Serialize com content de
    // ExamManagerReportDraftController::show()/update()/store().
    draft: { type: Object, default: null },
    // Quem está emitindo de fato (Gate::IssueReport) decide o rodapé: só
    // médico emite; secretária/admin só rascunham (Fase 5).
    isDoctor: { type: Boolean, default: false },
    // Ditado por voz (Fase 5) — { available, max_seconds } de ExamManagerController;
    // `available` já considera o recurso ligado e o provedor dentro da política LGPD.
    dictation: { type: Object, default: () => ({ available: false, max_seconds: 120 }) },
});

const emit = defineEmits(['close', 'saved', 'draft-saved', 'draft-discarded', 'draft-returned']);

// Rascunho em andamento nesta sessão do modal — do prop `draft` (continuando
// um existente) OU criado no meio do caminho pelo primeiro "Salvar
// rascunho" (então as chamadas seguintes de salvar viram update, não store).
const draftId = ref(null);
const draftStatus = ref(null);
// Token de revisão (ExamManagerReportDraftController::serialize(), reaproveita
// updated_at) — enviado de volta em toda atualização/emissão deste rascunho
// pra o backend rejeitar (409) se alguém salvou uma versão mais nova
// enquanto esta tela estava aberta (achado via revisão adversarial Codex).
const draftRevision = ref(null);
const savingDraft = ref(false);
// JSON do draftPayload() no momento do ÚLTIMO save confirmado (reset()
// trata abrir um rascunho existente como "já salvo" até aqui; cada
// saveDraft() bem-sucedido refaz o snapshot). isDirty() compara o payload
// ATUAL contra isto — nunca um boolean fixo (draftSavedOnce), que fazia
// qualquer edição POSTERIOR ao 1º save sumir sem aviso ao fechar/Esc/
// backdrop (achado via revisão adversarial Codex).
const lastSavedDraftSnapshot = ref(null);

// Incrementado em reset() (toda vez que o modal abre, mesmo pro MESMO
// paciente) — identifica a sessão de edição ATUAL. Fechar o modal com um
// "Salvar rascunho" ainda em voo (POST draftStore) e reabrir pra OUTRO
// paciente antes da resposta chegar fazia o draftId do paciente ANTERIOR
// ser atribuído ao form do paciente NOVO — o próximo "Salvar" virava um
// PUT no rascunho ERRADO, sobrescrevendo o conteúdo de outro paciente (sem
// 403 quando exam_ids vem vazio, já que a posse só é checada contra exames
// não-vazios) — achado via revisão Codex. Mesmo princípio do
// captureSessionId em ImportExternalExamModal.vue.
let draftSaveSessionId = 0;

// Rascunho em Draft OU Submitted ainda pode ser editado/descartado (mesma
// regra do modelo de rascunho::isEditable() no backend).
const draftIsEditable = computed(() => ['draft', 'submitted', 'returned'].includes(draftStatus.value));

// Motivo da devolução ao técnico (status `returned`): vem do servidor e some
// quando o técnico reenvia. A tela mostra no topo até lá.
const draftReturnReason = ref('');
const returningDraft = ref(false);

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

// "Imagens no laudo" (Fase 5 / 5b) — bloco opcional embutido no PDF
// assinado (ReportImageBlockBuilder resolve de verdade na emissão/reimpressão;
// aqui é só a escolha + ordem + legenda). Candidatos vêm do MESMO
// availableImages já usado por "Inserir imagem" — nunca uma lista paralela.
const imageReport = reactive({
    enabled: false,
    columns: 2,
    pair_od_oe: false,
    images: [], // [{exam_id, url, label, caption}] — url/label só de apoio visual, nunca persistidos
});
const showImageReportPicker = ref(false);
const imageReportPickerRef = ref(null);

const imageReportAvailableToAdd = computed(() =>
    availableImages.value.filter((img) => !imageReport.images.some((i) => i.exam_id === img.id)),
);

// imageReport.images só é preenchido UMA VEZ (onImageReportToggle(), ao
// ligar o bloco) — sem isto, desmarcar um olho/exame DEPOIS de já ter
// ligado o bloco deixava a imagem dele na galeria embutida mesmo fora da
// seleção principal: a tela continuava mostrando e enviando, o backend
// descartava em silêncio (ownedExamIds() já valida exam_ids em si; aqui
// era só o bloco de imagens que ficava obsoleto) e o aviso de "laudo
// incompleto" nunca disparava pra essa imagem, já fora do bloco antes de
// resolveForIssuance() contar o que falta (achado via revisão adversarial
// Codex). Mantém a galeria sempre refletindo a seleção atual.
watch(selectedExamIds, (ids) => {
    imageReport.images = imageReport.images.filter((img) => ids.includes(img.exam_id));
});

// Liga o bloco já com todas as imagens disponíveis (ponto de partida mais
// útil que uma lista vazia — o médico remove o que não quiser).
function onImageReportToggle() {
    if (imageReport.enabled && imageReport.images.length === 0) {
        imageReport.images = availableImages.value.map((img) => ({
            exam_id: img.id,
            url: img.url,
            label: img.label,
            caption: '',
        }));
    }
}

function addImageReportImage(img) {
    imageReport.images.push({ exam_id: img.id, url: img.url, label: img.label, caption: '' });
    showImageReportPicker.value = false;
}

function removeImageReportImage(idx) {
    imageReport.images.splice(idx, 1);
}

function moveImageReportImage(idx, direction) {
    const to = idx + direction;
    if (to < 0 || to >= imageReport.images.length) return;
    [imageReport.images[idx], imageReport.images[to]] = [imageReport.images[to], imageReport.images[idx]];
}

// Payload pro backend: {exam_id, caption, order} só (sem url/label — isso o
// backend/ReportImageBlockBuilder resolve de novo a partir do exam_id).
function imageReportPayload() {
    if (!imageReport.enabled || imageReport.images.length === 0) return null;
    return {
        enabled: true,
        columns: imageReport.columns,
        pair_od_oe: imageReport.pair_od_oe,
        images: imageReport.images.map((img, idx) => ({
            exam_id: img.exam_id,
            caption: img.caption || null,
            order: idx,
        })),
    };
}

// Rehidrata a partir de um rascunho (metadata.image_report / image_report_payload
// têm o MESMO formato) — re-liga url/label a partir do exam_id; imagem que
// não existe mais em availableImages (exame excluído/trocado) cai fora
// silenciosamente, sem quebrar o form.
function hydrateImageReport(payload) {
    if (!payload?.enabled) {
        imageReport.enabled = false;
        imageReport.columns = 2;
        imageReport.pair_od_oe = false;
        imageReport.images = [];
        return;
    }

    const byId = new Map(availableImages.value.map((img) => [img.id, img]));
    imageReport.enabled = true;
    imageReport.columns = payload.columns || 2;
    imageReport.pair_od_oe = !!payload.pair_od_oe;
    imageReport.images = (payload.images ?? [])
        .slice()
        .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
        .map((item) => {
            const img = byId.get(item.exam_id);
            return img ? { exam_id: item.exam_id, url: img.url, label: img.label, caption: item.caption ?? '' } : null;
        })
        .filter(Boolean);
}

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
    if (
        showImageReportPicker.value &&
        imageReportPickerRef.value &&
        !imageReportPickerRef.value.contains(event.target)
    ) {
        showImageReportPicker.value = false;
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

    let label;
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

// Ditado por voz (Fase 5): escritor ASSÍNCRONO do conteúdo, como a extração de
// PDF — grava/transcreve em segundo plano e o texto entra no cursor. Por isso:
// (a) enquanto grava/transcreve, os botões de salvar/emitir ficam travados (o
// texto que ainda vai chegar não estaria no que foi enviado) e (b) texto que
// chega depois que a emissão começou (ou terminou — o sucesso esconde o editor)
// é descartado, nunca inserido numa tela que já não corresponde ao enviado.
const dictationBusy = ref(false);

function onDictated(text) {
    if (saving.value || savingDraft.value || returningDraft.value || savedResult.value) return;
    editorRef.value?.insertContent(dictationToHtml(text));
}

function onDictationError(message) {
    if (window.showErrorToast) window.showErrorToast(message);
}

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
    // data-exam-image-id: referência estável — o backend NUNCA confia na
    // src crua (URL assinada, expira/troca depois), sempre revalida e
    // resolve de novo a partir do exam_id na hora de salvar/emitir (achado
    // via revisão adversarial Codex: o profile de sanitização excluía
    // <img> por completo e a imagem inserida desaparecia em silêncio ao
    // salvar). src aqui é só pra pré-visualização ao vivo no editor.
    editorRef.value?.insertContent(
        `<p><img src="${img.url}" data-exam-image-id="${img.id}" alt="${escapeHtml(img.label)}" style="max-width:320px;display:block;margin:.5rem 0;" /></p>`,
    );
    showImagePicker.value = false;
}

// Rascunho recarregado: content persistido nunca tem src (removida na
// sanitização do backend — ver insertImage() acima) — sem isto, a foto
// inserida "desaparecia" visualmente ao reabrir o rascunho, mesmo com a
// referência preservada. Resolve de novo a partir do MESMO
// availableImages já carregado nesta tela (nunca uma chamada nova ao
// backend) — exame fora da seleção atual fica sem src mesmo (ícone
// quebrado), tolerável: a referência em si nunca se perde.
function rehydrateInlineImages(html) {
    if (!html || !html.includes('data-exam-image-id')) return html;

    const byId = new Map(availableImages.value.map((img) => [img.id, img.url]));
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('img[data-exam-image-id]').forEach((img) => {
        const url = byId.get(img.getAttribute('data-exam-image-id'));
        if (url) img.setAttribute('src', url);
    });

    return doc.body.innerHTML;
}

// Sem campo de Título na tela — o título é sempre derivado do modelo
// escolhido (ou fica em branco/"Em branco", e o backend aplica um título
// padrão). Menos um campo pro médico preencher à toa.
const form = reactive({
    report_setting_content_id: '',
    title: '',
    content: '',
    clinical_indication: '',
    observations: '',
    document_date: '',
    requesting_doctor_name: '',
    requesting_doctor_email: '',
});

// Último texto resolvido por um preview de VERDADE (nunca o de um rascunho
// carregado) — trocar a data só reemite o preview (ver onDocumentDateInput)
// quando o conteúdo ainda é exatamente esse texto; conteúdo editado a mão OU
// vindo de um rascunho com achados já escritos nunca é sobrescrito sozinho.
const lastPreviewContent = ref('');
const contentEdited = computed(() => Boolean(form.content) && form.content !== lastPreviewContent.value);

// Médico usado na ÚLTIMA pré-visualização (Fase 5 / revisão Codex) — viaja
// com o rascunho (resolved_doctor_id) só pra store() comparar contra quem
// de fato emite e avisar se o texto puder citar outro médico (templates
// personalizados com {{MEDICO_NOME}}/{{MEDICO_CRM}} no corpo — os modelos
// padrão de laudo de imagem não usam, só a assinatura do PDF). Nunca edita
// o texto sozinho.
const resolvedDoctorId = ref(null);

function reset() {
    form.report_setting_content_id = props.draft?.report_setting_content_id ?? '';
    form.title = props.draft?.title ?? '';
    form.content = rehydrateInlineImages(props.draft?.content ?? '');
    form.clinical_indication = props.draft?.clinical_indication ?? '';
    form.observations = props.draft?.observations ?? '';
    form.document_date = props.draft?.document_date ?? '';
    form.requesting_doctor_name = props.draft?.requesting_doctor_name ?? '';
    form.requesting_doctor_email = props.draft?.requesting_doctor_email ?? '';
    error.value = '';
    savedResult.value = null;
    draftId.value = props.draft?.id ?? null;
    draftStatus.value = props.draft?.status ?? null;
    draftReturnReason.value = props.draft?.status === 'returned' ? (props.draft?.return_reason ?? '') : '';
    draftRevision.value = props.draft?.revision ?? null;
    hydrateImageReport(props.draft?.image_report_payload ?? null);
    // Começa "sem preview ainda" DE PROPÓSITO, mesmo com draft.content
    // preenchido: conteúdo de rascunho é tratado como editado (contentEdited
    // vira true), nunca perdido por um reemite automático ao trocar a data.
    lastPreviewContent.value = '';
    resolvedDoctorId.value = props.draft?.resolved_doctor_id ?? null;
    draftSaveSessionId += 1;
    // Sessão NOVA começa sempre "não salvando" — o finally de um
    // saveDraft()/save() ainda em voo da sessão ANTERIOR nunca mais vai
    // zerar isto (o guard de sessão acima é exatamente pra ele NÃO tocar em
    // nada desta sessão nova), então sem esta linha savingDraft/saving
    // ficava true pra sempre, travando "Salvar rascunho"/"Enviar"/"Salvar
    // laudo" até recarregar a página (achado via revisão Codex; save()
    // ganhou o MESMO guard de sessão na 4ª rodada de revisão adversarial).
    savingDraft.value = false;
    saving.value = false;
    returningDraft.value = false;
    // Mesmo motivo do draftSaveSessionId acima, aplicado ao preview de
    // modelo: abrir o rascunho NÃO dispara onTemplateChange() de novo, então
    // um preview do paciente/sessão ANTERIOR ainda em voo continuava batendo
    // com previewRequestId (nada o invalidava) e sobrescrevia o conteúdo já
    // salvo deste rascunho quando a resposta atrasada chegasse. Invalida
    // aqui e tira o loading, que senão ficaria travado em true pra sempre
    // (o finally da chamada velha agora é bloqueado pelo guard de id) —
    // achado via revisão Codex.
    previewRequestId += 1;
    previewing.value = false;
    // ÚLTIMO, depois de todo form.*/checkedEyeKeys/imageReport acima
    // assentados: snapshot do que está na tela AGORA, tratado como "ponto de
    // partida salvo" (continuar um rascunho existente não é, por si só,
    // edição não salva).
    lastSavedDraftSnapshot.value = JSON.stringify(draftPayload());
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

// Incrementado a cada chamada — identifica a MAIS NOVA. Trocar a data duas
// vezes seguidas (ou modelo + data em sequência rápida) dispara 2 requests
// de preview; se a rede devolver fora de ordem (a 1ª responde DEPOIS da
// 2ª), a resposta velha sobrescrevia form.content com o texto de uma data
// ULTRAPASSADA, enquanto form.document_date já estava na data nova — o
// laudo salvo citava uma data no corpo diferente da gravada no campo
// (achado via revisão Codex). Mesmo princípio do captureSessionId em
// ImportExternalExamModal.vue: só a resposta da chamada AINDA MAIS RECENTE
// pode alterar o estado; qualquer uma mais velha é descartada em silêncio.
let previewRequestId = 0;

async function onTemplateChange() {
    if (!form.report_setting_content_id || !props.patient?.id) return;

    const requestId = ++previewRequestId;
    previewing.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(props.urls.preview, {
            report_setting_content_id: form.report_setting_content_id,
            patient_id: props.patient.id,
            exam_ids: selectedExamIds.value,
            ...documentDatePayload(form.document_date, props.dateConfig?.today),
        });
        if (requestId !== previewRequestId) return; // resposta desatualizada — uma chamada mais nova já venceu

        form.content = data?.content ?? '';
        lastPreviewContent.value = form.content;
        resolvedDoctorId.value = data?.resolved_doctor_id ?? null;

        const tpl = templates.value
            .flatMap((g) => g.contents || [])
            .find((c) => c.id === form.report_setting_content_id);
        form.title = tpl?.label ?? '';
    } catch (e) {
        if (requestId !== previewRequestId) return; // idem, erro de uma chamada já superada
        error.value = e?.response?.data?.message ?? tt('report_save_failed', 'Não foi possível carregar o modelo.');
    } finally {
        // Mesma guarda do try/catch acima: se A (desatualizada) resolve
        // ANTES de B (a mais nova, ainda pendente), o finally de A rodava
        // incondicionalmente e desligava previewing — reabilitava "Salvar"
        // com o conteúdo de A enquanto B ainda estava a caminho, mesma
        // janela de corrida que este requestId existe pra fechar (achado via
        // revisão Codex). Só a chamada AINDA atual pode desligar o loading.
        if (requestId === previewRequestId) previewing.value = false;
    }
}

// [GAP] O campo de data vem DEPOIS do de modelo na tela (ordem natural:
// "Modelos" é o primeiro campo) — sem isto, escolher o modelo e SÓ DEPOIS
// trocar a data deixava {{DATA_ATUAL}}/{{LOCAL_DATA}} presos na data de
// quando o modelo foi carregado: o MESMO bug do achado original
// (previewTemplate() ignorava a data alternativa), só que um passo depois —
// onTemplateChange() já manda form.document_date certo, mas nada chamava
// de novo quando só a DATA mudava. Reemite só quando o conteúdo ainda é
// exatamente o do último preview (mesma regra de docContentEdited em
// MedicalRecordForm.vue) — nunca sobrescreve texto editado a mão ou um
// rascunho com achados já escritos.
function onDocumentDateInput(value) {
    form.document_date = value;
    if (form.report_setting_content_id && !contentEdited.value) onTemplateChange();
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

// Bloqueia ANTES de emitir (backend devolve 422 com physician_mismatch_warning
// em vez de criar o documento) — nunca só avisar DEPOIS do laudo já emitido
// e do e-mail do médico solicitante já enviado de verdade (achado via
// revisão Codex: o aviso só aparecia na resposta de SUCESSO, tarde demais
// pra servir de revisão).
async function confirmPhysicianMismatch(warningText) {
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            title: warningText,
            showCancelButton: true,
            confirmButtonText: tt('report_physician_mismatch_confirm', 'Emitir mesmo assim'),
            cancelButtonText: tt('cancel', 'Cancelar'),
        });
        return result.isConfirmed;
    }

    return window.confirm(warningText);
}

// Mesmo motivo de confirmPhysicianMismatch acima: bloqueia ANTES de emitir
// (backend devolve 422 com image_report_incomplete_warning em vez de criar
// o documento) — nunca só avisar depois do laudo já assinado e enviado
// (achado na 2ª rodada de revisão adversarial Codex: sem isto, uma imagem
// que não pôde ser congelada simplesmente desaparecia do laudo, sem o
// médico nunca saber).
async function confirmImageReportIncomplete(warningText) {
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            title: warningText,
            showCancelButton: true,
            confirmButtonText: tt('report_image_incomplete_confirm', 'Emitir mesmo assim'),
            cancelButtonText: tt('cancel', 'Cancelar'),
        });
        return result.isConfirmed;
    }

    return window.confirm(warningText);
}

// Mesma ideia, pras referências inline <img> inseridas no texto via
// "Inserir imagem" (distinto do bloco acima) — achado via revisão
// adversarial Codex: o backend congela essas referências só na emissão;
// uma que não resolveu (exame excluído/desabilitado desde a inserção)
// nunca pode desaparecer em silêncio do laudo assinado.
async function confirmInlineImagesIncomplete(warningText) {
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            title: warningText,
            showCancelButton: true,
            confirmButtonText: tt('report_image_incomplete_confirm', 'Emitir mesmo assim'),
            cancelButtonText: tt('cancel', 'Cancelar'),
        });
        return result.isConfirmed;
    }

    return window.confirm(warningText);
}

async function save(
    confirmOpen = false,
    confirmMismatch = false,
    confirmImageIncomplete = false,
    confirmInlineIncomplete = false,
) {
    if (!props.patient?.id) return;
    // Proteção extra (achado na 8ª rodada de revisão adversarial Codex):
    // nunca entra duas vezes ao mesmo tempo, mesmo se algo chamar save()
    // sem passar pelo botão (:disabled="saving" já cobre o clique normal).
    // Cada retentativa de confirmação abaixo zera saving ANTES de chamar
    // save() de novo, de propósito, pra não se bloquear sozinha aqui.
    if (saving.value) return;

    if (noExamChecked.value) {
        error.value = tt('report_select_exam', 'Marque ao menos um exame para laudar.');
        return;
    }

    const plain = form.content.replace(/<[^>]*>/g, '').trim();
    if (!plain) {
        error.value = tt('report_content_required', 'Escreva o conteúdo do laudo antes de salvar.');
        return;
    }

    // Capturado ANTES do await — mesmo padrão de saveDraft(): fechar e
    // reabrir pra OUTRO paciente enquanto esta emissão ainda está em voo
    // nunca pode aplicar a resposta (nem o retry de uma confirmação) na
    // tela NOVA. Sem isto, a resposta atrasada da emissão de A chegava
    // depois do médico já ter trocado pra B, preenchia savedResult de B
    // (escondendo o editor de B) e fazia isDirty() achar "nada pra
    // perder" — a correção de B era descartada em silêncio ao fechar
    // (achado via revisão adversarial Codex, 4ª rodada).
    const sessionId = draftSaveSessionId;
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
            confirm_physician_mismatch: confirmMismatch,
            confirm_image_report_incomplete: confirmImageIncomplete,
            confirm_inline_images_incomplete: confirmInlineIncomplete,
            clinical_indication: form.clinical_indication || null,
            observations: form.observations || null,
            ...documentDatePayload(form.document_date, props.dateConfig?.today),
            // Rascunho revisado e emitido agora (Fase 5) — undefined quando
            // não há rascunho (fluxo direto, inalterado).
            draft_id: draftId.value || undefined,
            // Token de revisão deste rascunho (ver draftRevision acima) — o
            // backend rejeita com 409 se alguém salvou uma versão mais nova
            // enquanto esta tela estava aberta (achado via revisão
            // adversarial Codex).
            draft_revision: draftId.value ? draftRevision.value || undefined : undefined,
            // Médico solicitante + envio por e-mail (Fase 5).
            requesting_doctor_name: form.requesting_doctor_name || null,
            requesting_doctor_email: form.requesting_doctor_email || null,
            // Imagens embutidas no laudo (Fase 5 / 5b).
            image_report: imageReportPayload(),
        });
        if (sessionId !== draftSaveSessionId) return; // resposta obsoleta — outra tela já está aberta

        savedResult.value = {
            id: data.id,
            pdf_url: data.pdf_url,
            title: data.title,
            requesting_doctor_email: data.requesting_doctor_email,
            requesting_doctor_sent_at: data.requesting_doctor_sent_at,
            // O envio só é liberado com o prontuário ASSINADO (o laudo nasce
            // dentro de um prontuário ainda editável) — a emissão só grava o
            // destinatário; o servidor devolve true enquanto o envio estiver
            // pendente da assinatura (achado via revisão adversarial Codex).
            requesting_doctor_awaiting_signature: Boolean(data.requesting_doctor_awaiting_signature),
            physician_mismatch_warning: data.physician_mismatch_warning,
        };
        if (window.showSuccessToast) window.showSuccessToast(tt('report_saved', 'Laudo salvo com sucesso.'));
        emit('saved', data);
    } catch (e) {
        if (sessionId !== draftSaveSessionId) return; // idem — erro obsoleto, nunca mostra na tela nova
        const payload = e?.response?.data;

        if (e?.response?.status === 422 && payload?.physician_mismatch_warning) {
            const confirmed = await confirmPhysicianMismatch(payload.physician_mismatch_warning);
            // O diálogo de confirmação pode ficar aberto por tempo
            // arbitrário — a tela pode ter trocado de paciente ENQUANTO o
            // médico ainda olhava pro aviso da sessão anterior (achado via
            // revisão adversarial Codex, 4ª rodada: "check them after
            // every await, including confirmation dialogs").
            if (sessionId !== draftSaveSessionId) return;
            if (!confirmed) {
                saving.value = false;
                return;
            }
            // saving.value = false só pra não travar na guarda do topo —
            // save() já religa pra true antes de qualquer outro código
            // rodar (nenhum await entre as duas linhas). O await aqui é
            // obrigatório: sem ele, o finally abaixo roda imediatamente
            // (retornar uma Promise de dentro de um catch não basta — só
            // esperar por ela de fato atrasa o finally), zerando saving
            // ENQUANTO a emissão confirmada ainda está em voo e permitindo
            // emissão duplicada com um segundo clique (achado na 8ª rodada
            // de revisão adversarial Codex).
            saving.value = false;
            return await save(confirmOpen, true, confirmImageIncomplete, confirmInlineIncomplete);
        }

        if (e?.response?.status === 422 && payload?.requires_record_confirmation) {
            const confirmed = await confirmOpenRecord(payload.consultation_date);
            if (sessionId !== draftSaveSessionId) return;
            if (!confirmed) {
                saving.value = false;
                return;
            }
            saving.value = false;
            return await save(true, confirmMismatch, confirmImageIncomplete, confirmInlineIncomplete);
        }

        if (e?.response?.status === 422 && payload?.image_report_incomplete_warning) {
            const confirmed = await confirmImageReportIncomplete(payload.image_report_incomplete_warning);
            if (sessionId !== draftSaveSessionId) return;
            if (!confirmed) {
                saving.value = false;
                return;
            }
            saving.value = false;
            return await save(confirmOpen, confirmMismatch, true, confirmInlineIncomplete);
        }

        if (e?.response?.status === 422 && payload?.inline_images_incomplete_warning) {
            const confirmed = await confirmInlineImagesIncomplete(payload.inline_images_incomplete_warning);
            if (sessionId !== draftSaveSessionId) return;
            if (!confirmed) {
                saving.value = false;
                return;
            }
            saving.value = false;
            return await save(confirmOpen, confirmMismatch, confirmImageIncomplete, true);
        }

        // Rascunho obsoleto (409 — ver draft_revision acima): nunca reenvia
        // sozinho, diferente dos 422 acima. A correção alheia precisa ser
        // LIDA pelo médico, não só "confirmada" com um clique — fechar e
        // reabrir o rascunho é o único jeito de ver o texto atual.
        if (e?.response?.status === 409) {
            error.value =
                payload?.message ??
                tt(
                    'draft_revision_conflict',
                    'Este rascunho foi alterado por outra pessoa. Feche e abra de novo para ver a versão mais recente.',
                );
            return;
        }

        error.value = payload?.message ?? tt('report_save_failed', 'Não foi possível salvar o laudo.');
    } finally {
        // Guardado (ver sessionId acima): uma emissão da sessão ANTERIOR
        // que só termina agora nunca pode zerar o "salvando" da sessão
        // ATUAL nem destravar os botões de uma tela que nem é mais esta
        // (achado via revisão adversarial Codex, 4ª rodada — mesmo padrão
        // já usado em saveDraft()).
        if (sessionId === draftSaveSessionId) saving.value = false;
    }
}

// Rascunho (Fase 5 — fluxo secretária-rascunha/médico-emite): secretária/
// admin salvam sem emitir. 1ª chamada cria (sem draftId ainda); as
// seguintes, na MESMA sessão do modal, atualizam o mesmo rascunho.
function draftPayload() {
    return {
        exam_ids: selectedExamIds.value,
        report_setting_content_id: form.report_setting_content_id || null,
        resolved_doctor_id: resolvedDoctorId.value,
        title: form.title || null,
        content: form.content,
        clinical_indication: form.clinical_indication || null,
        observations: form.observations || null,
        ...documentDatePayload(form.document_date, props.dateConfig?.today),
        requesting_doctor_name: form.requesting_doctor_name || null,
        requesting_doctor_email: form.requesting_doctor_email || null,
        image_report: imageReportPayload(),
    };
}

async function saveDraft(submit = false) {
    if (!props.patient?.id) return;

    if (submit && !form.content.replace(/<[^>]*>/g, '').trim()) {
        error.value = tt('report_content_required', 'Escreva o conteúdo do laudo antes de salvar.');
        return;
    }

    const sessionId = draftSaveSessionId;
    // Capturado UMA vez, antes do await: se o usuário digitar MAIS enquanto
    // esta chamada está em voo, o snapshot "salvo" abaixo precisa refletir o
    // que foi de fato ENVIADO/persistido, nunca um draftPayload() novo lido
    // DEPOIS da resposta — senão a edição feita durante o voo seria marcada
    // como salva sem nunca ter chegado ao servidor (achado via revisão
    // adversarial Codex: "preserve dirty status... while a save request is
    // pending").
    const payload = draftPayload();
    // Reenvio de um rascunho devolvido: a tela pai troca o selo "devolvidos"
    // por "pendentes" (ver onDraftSaved em Index.vue).
    const wasReturned = draftStatus.value === 'returned';
    savingDraft.value = true;
    error.value = '';

    try {
        if (draftId.value) {
            const { data } = await window.axios.put(
                props.urls.draftUpdate.replace('__ID__', draftId.value),
                // draft_revision (ver ref acima): o backend rejeita com 409
                // se outra pessoa salvou uma versão mais nova deste MESMO
                // rascunho desde que esta tela abriu/recarregou-o (achado
                // via revisão adversarial Codex).
                { ...payload, draft_revision: draftRevision.value || undefined },
            );
            if (sessionId !== draftSaveSessionId) return; // modal fechado/reaberto (outro paciente) — resposta obsoleta
            draftStatus.value = data.status;
            draftRevision.value = data.revision;
            draftReturnReason.value = data.status === 'returned' ? (data.return_reason ?? '') : '';
        } else {
            const { data } = await window.axios.post(props.urls.draftStore, {
                patient_id: props.patient.id,
                ...payload,
            });
            if (sessionId !== draftSaveSessionId) return; // idem — nunca atribui o draftId do paciente ERRADO
            draftId.value = data.id;
            draftStatus.value = data.status;
            draftRevision.value = data.revision;
        }

        if (submit) {
            const { data } = await window.axios.post(props.urls.draftSubmit.replace('__ID__', draftId.value), {
                draft_revision: draftRevision.value,
            });
            if (sessionId !== draftSaveSessionId) return;
            draftStatus.value = data.status;
            draftReturnReason.value = ''; // reenviar limpa o motivo (o servidor também)
            // NUNCA draftRevision.value = data.revision aqui: submit() não
            // escreve conteúdo (só troca status), então o revision de
            // verdade é o que a ATUALIZAÇÃO anterior (update()/store(),
            // acima) já deixou certo. Se outra pessoa salvar uma correção
            // ENQUANTO este submit() está em voo, a resposta dele reflete
            // o revision NOVO (de um conteúdo que esta tela nunca carregou)
            // — adotar esse valor "lavava" o token: o próximo save desta
            // tela passaria a checagem de obsolescência mesmo com texto
            // velho, sobrescrevendo a correção alheia sem 409 (achado na
            // 5ª rodada de revisão adversarial Codex).
        }

        // Snapshot do que foi ENVIADO nesta chamada (não um draftPayload()
        // novo) — ver comentário acima de `payload`. isDirty() volta a achar
        // dirty assim que o form atual deixar de bater com isto, mesmo já
        // tendo salvo antes nesta sessão (achado via revisão adversarial
        // Codex: draftSavedOnce era um boolean fixo, nunca reavaliado).
        lastSavedDraftSnapshot.value = JSON.stringify(payload);
        if (window.showSuccessToast) {
            window.showSuccessToast(
                submit
                    ? tt('draft_submitted', 'Rascunho enviado para o médico.')
                    : tt('draft_saved', 'Rascunho salvo.'),
            );
        }
        emit('draft-saved', { id: draftId.value, submitted: submit, wasReturned });
        if (submit) {
            // isDirty() compara o form ATUAL contra lastSavedDraftSnapshot,
            // já atualizado acima pro `payload` ENVIADO (não um
            // draftPayload() novo) — se o usuário editou DURANTE o(s)
            // await(s) desta função (o conteúdo ainda fica editável
            // enquanto savingDraft está true), o servidor nunca viu essa
            // edição, mas o rascunho já virou Submitted. Fechar aqui
            // perderia essa edição sem aviso (achado via revisão
            // adversarial Codex: "saveDraft(true) captures the payload
            // before awaiting... this unconditional close discards the
            // correction"). Em vez de fechar, avisa e deixa a tela aberta
            // pra salvar de novo.
            if (isDirty()) {
                if (window.showErrorToast) {
                    window.showErrorToast(
                        tt(
                            'draft_submitted_with_pending_edit',
                            'Rascunho enviado, mas há uma edição mais recente ainda não salva. Salve novamente antes de fechar.',
                        ),
                    );
                }
            } else {
                emit('close');
            }
        }
    } catch (e) {
        if (sessionId !== draftSaveSessionId) return;
        // 409 (draft_revision obsoleto, ver ref acima): mesmo raciocínio de
        // save() — nunca reenvia sozinho, o médico/secretária precisa fechar
        // e reabrir pra ver o conteúdo atual antes de tentar de novo.
        error.value =
            e?.response?.data?.message ??
            (e?.response?.status === 409
                ? tt(
                      'draft_revision_conflict',
                      'Este rascunho foi alterado por outra pessoa. Feche e abra de novo para ver a versão mais recente.',
                  )
                : tt('report_save_failed', 'Não foi possível salvar o laudo.'));
    } finally {
        if (sessionId === draftSaveSessionId) savingDraft.value = false;
    }
}

// Médico devolve o rascunho enviado ao técnico, com o motivo (Fase 5). O
// motivo é obrigatório: devolver sem dizer o que ajustar só gera idas e vindas.
async function askReturnReason() {
    const unsaved = isDirty()
        ? tt('draft_return_unsaved', 'As alterações feitas nesta tela não serão enviadas ao técnico.')
        : '';
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'question',
            title: tt('draft_return_title', 'Devolver ao técnico?'),
            text: unsaved,
            input: 'textarea',
            inputLabel: tt('draft_return_reason_label', 'O que precisa ser ajustado?'),
            inputPlaceholder: tt(
                'draft_return_reason_placeholder',
                'Ex.: falta a imagem do OE; revisar a lateralidade.',
            ),
            inputAttributes: { maxlength: 1000 },
            showCancelButton: true,
            confirmButtonText: tt('draft_return_confirm', 'Devolver'),
            cancelButtonText: tt('cancel', 'Cancelar'),
            inputValidator: (value) =>
                !value || !value.trim()
                    ? tt('draft_return_reason_required', 'Informe o motivo da devolução.')
                    : undefined,
        });
        return result.isConfirmed ? String(result.value).trim() : null;
    }
    const typed = window.prompt(tt('draft_return_reason_label', 'O que precisa ser ajustado?'));
    return typed && typed.trim() ? typed.trim() : null;
}

async function returnDraft() {
    if (!draftId.value || returningDraft.value || dictationBusy.value || !props.urls.draftReturn) return;

    // Mesmo raciocínio de discardDraft(): o diálogo espera o usuário e o modal
    // pode fechar/reabrir em outro rascunho enquanto isso.
    const sessionId = draftSaveSessionId;
    const returnedId = draftId.value;
    const returnedRevision = draftRevision.value;

    const reason = await askReturnReason();
    if (reason === null) return;
    // O diálogo fica aberto enquanto o usuário decide. Nesse intervalo a
    // sessão pode ter sido fechada e reaberta para outro rascunho; nunca
    // deixe a decisão antiga alterar ou travar a sessão nova.
    if (sessionId !== draftSaveSessionId || !props.open) return;

    returningDraft.value = true;
    error.value = '';
    try {
        await window.axios.post(props.urls.draftReturn.replace('__ID__', returnedId), {
            draft_revision: returnedRevision,
            reason,
        });
        if (window.showSuccessToast) {
            window.showSuccessToast(tt('draft_returned', 'Rascunho devolvido ao técnico.'));
        }
        emit('draft-returned', { id: returnedId });
        if (sessionId === draftSaveSessionId) emit('close');
    } catch (e) {
        if (sessionId !== draftSaveSessionId) return;
        error.value =
            e?.response?.data?.errors?.reason?.[0] ??
            e?.response?.data?.message ??
            tt('draft_return_failed', 'Não foi possível devolver o rascunho.');
    } finally {
        if (sessionId === draftSaveSessionId) returningDraft.value = false;
    }
}

async function confirmDiscardDraft() {
    const message = tt('draft_discard_text', 'O rascunho será descartado e não poderá mais ser editado ou emitido.');
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            title: tt('draft_discard_title', 'Descartar este rascunho?'),
            text: message,
            showCancelButton: true,
            confirmButtonText: tt('draft_discard_confirm', 'Descartar rascunho'),
            cancelButtonText: tt('cancel', 'Cancelar'),
            confirmButtonColor: '#dc3545',
        });
        return result.isConfirmed;
    }
    return window.confirm(message);
}

async function discardDraft() {
    if (!draftId.value) return;

    // Capturado ANTES do confirm() (modal, aguarda o usuário — pode levar
    // qualquer tempo): se o modal fechar e reabrir pra OUTRO rascunho
    // enquanto o diálogo ou o DELETE ainda estão em voo, a resposta
    // atrasada precisa continuar se referindo ao rascunho que o usuário
    // CLICOU pra descartar, nunca ao que o modal mostra quando ela chega —
    // sem isto, draftId.value/draftStatus.value já eram os da sessão NOVA
    // quando a resposta chegava (achado via revisão Codex).
    const sessionId = draftSaveSessionId;
    const discardedId = draftId.value;
    const discardedStatus = draftStatus.value;
    const discardedRevision = draftRevision.value;

    if (!(await confirmDiscardDraft())) return;

    try {
        // draft_revision: backend agora rejeita descarte obsoleto (409) —
        // mesma proteção de save()/saveDraft(), achado via revisão
        // adversarial Codex (destroy() só checava isEditable(), nunca
        // comparava revision como update()/submit() já faziam).
        await window.axios.delete(props.urls.draftDestroy.replace('__ID__', discardedId), {
            data: { draft_revision: discardedRevision },
        });
        if (window.showSuccessToast) window.showSuccessToast(tt('draft_discarded', 'Rascunho descartado.'));
        // draft-discarded é só informativo pro pai (decrementa contador de
        // pendentes por status — Index.vue::onDraftRemovedFromPending() nem
        // lê o id) e sempre correto com discardedId/discardedStatus
        // capturados acima. close() é diferente: fechar a sessão NOVA (outro
        // rascunho, talvez com edição não salva) sem confirmação seria
        // destrutivo — só fecha se ainda for a MESMA sessão que pediu o
        // descarte.
        emit('draft-discarded', { id: discardedId, status: discardedStatus });
        if (sessionId === draftSaveSessionId) emit('close');
    } catch (e) {
        if (sessionId !== draftSaveSessionId) return;
        if (window.showErrorToast) {
            window.showErrorToast(
                e?.response?.data?.message ?? tt('report_save_failed', 'Não foi possível descartar.'),
            );
        }
    }
}

// Reenvio ao médico solicitante (Fase 5) — usa sempre o e-mail já gravado
// na emissão (sem campo de correção aqui; ver Riscos no plano).
const resending = ref(false);

async function resendToRequestingDoctor() {
    if (!savedResult.value?.id || !props.urls.reportResend) return;

    // Capturados ANTES do await: se o modal fechar e reabrir pra OUTRO
    // laudo (reset() zera savedResult e incrementa draftSaveSessionId)
    // enquanto o reenvio está em voo, a resposta atrasada não pode nem
    // lançar (savedResult.value já seria null) nem gravar
    // requesting_doctor_sent_at no laudo ERRADO (achado via revisão Codex).
    const sessionId = draftSaveSessionId;
    const targetId = savedResult.value.id;
    // Mesma condição nos 3 pontos abaixo — true só enquanto esta ainda for
    // a sessão/resultado que pediu o reenvio.
    const stillCurrent = () => sessionId === draftSaveSessionId && savedResult.value?.id === targetId;

    resending.value = true;
    try {
        const { data } = await window.axios.post(props.urls.reportResend.replace('__ID__', targetId));
        if (!stillCurrent()) return;
        savedResult.value.requesting_doctor_sent_at = data.requesting_doctor_sent_at;
        if (window.showSuccessToast)
            window.showSuccessToast(tt('requesting_doctor_resent', 'Laudo reenviado ao médico solicitante.'));
    } catch (e) {
        if (!stillCurrent()) return;
        if (window.showErrorToast) {
            window.showErrorToast(
                e?.response?.data?.message ??
                    tt('requesting_doctor_resend_failed', 'Não foi possível reenviar o laudo.'),
            );
        }
    } finally {
        // SEMPRE desliga, mesmo numa resposta obsoleta — diferente do
        // savedResult acima, resending é uma única flag da TELA (nunca
        // zerada por reset()); guardar isto também deixaria "Reenviar"
        // travado desabilitado pra sempre na sessão nova, já que nada mais
        // a desligaria depois.
        resending.value = false;
    }
}

// Conteúdo digitado e ainda não salvo não pode desaparecer num clique
// acidental no backdrop/Esc — mesmo risco que confirmOpenRecord já cobre pro
// caso de prontuário ausente, mas aqui é perda de digitação mesmo. Compara o
// payload ATUAL (completo, não só form.content) contra o snapshot do ÚLTIMO
// save confirmado (lastSavedDraftSnapshot, atualizado em reset()/saveDraft())
// — nunca um boolean fixo: isto reavalia a cada chamada, então uma edição
// feita DEPOIS de um "Salvar rascunho" bem-sucedido continua contando como
// não salva (achado via revisão adversarial Codex).
function isDirty() {
    if (savedResult.value) return false; // laudo já emitido nesta sessão — fechar não perde nada
    if (lastSavedDraftSnapshot.value === null) return !!form.content.replace(/<[^>]*>/g, '').trim();
    return JSON.stringify(draftPayload()) !== lastSavedDraftSnapshot.value;
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
    // A devolução só pode fechar depois que seu POST terminar. Além do botão
    // no rodapé, esta guarda cobre X, Escape e o clique no backdrop.
    if (returningDraft.value) return;
    if (isDirty() && !(await confirmDiscard())) return;
    emit('close');
}

useEscapeToClose(() => props.open, close);

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
            data-testid="eye-image-report-modal"
            :aria-modal="docked ? 'false' : 'true'"
            aria-labelledby="eyeReportModalTitle"
            @click.self="docked || close()"
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
                        <button
                            type="button"
                            class="btn-close"
                            :disabled="returningDraft"
                            data-testid="report-close-button"
                            @click="close"
                        ></button>
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
                                        :disabled="saving || !groupState(group).enabled.length"
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
                                            :disabled="saving || !eye.examIds.length"
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
                        <div
                            v-if="draftStatus === 'returned' && draftReturnReason"
                            class="alert alert-warning py-2 small"
                            role="status"
                            data-testid="draft-return-banner"
                        >
                            <strong>{{ tt('draft_returned_banner', 'Devolvido pelo médico:') }}</strong>
                            {{ draftReturnReason }}
                        </div>
                        <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

                        <template v-if="!savedResult">
                            <!-- Durante a emissão ("Salvar laudo"/"Revisar e emitir") NADA do que entra no
                                 payload pode mudar: o que for digitado depois que o POST saiu não está
                                 nele, o sucesso esconde este formulário e (num rascunho) o servidor já o
                                 marcou como emitido — a correção sumiria em silêncio (achado via revisão
                                 adversarial Codex, 21ª rodada). O fieldset desabilita todo controle nativo
                                 daqui (campos, seletores, botões, ordem/legenda das imagens); o TinyMCE
                                 recebe o mesmo bloqueio pelo :disabled abaixo. -->
                            <fieldset
                                :disabled="saving || returningDraft"
                                class="border-0 p-0 m-0"
                                data-testid="report-form-fieldset"
                            >
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

                                <div class="row g-2 mb-3">
                                    <div class="col-sm-7">
                                        <label class="form-label small fw-semibold">{{
                                            tt('report_clinical_indication_label', 'Indicação clínica')
                                        }}</label>
                                        <textarea
                                            v-model="form.clinical_indication"
                                            class="form-control form-control-sm"
                                            rows="2"
                                            maxlength="2000"
                                            :placeholder="
                                                tt('report_clinical_indication_placeholder', 'Motivo do exame/laudo…')
                                            "
                                        ></textarea>
                                    </div>
                                    <div class="col-sm-5">
                                        <DocumentDateField
                                            :model-value="form.document_date"
                                            :config="dateConfig"
                                            @update:model-value="onDocumentDateInput"
                                        />
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-semibold">{{
                                            tt('report_observations_label', 'Observações')
                                        }}</label>
                                        <textarea
                                            v-model="form.observations"
                                            class="form-control form-control-sm"
                                            rows="2"
                                            maxlength="4000"
                                            :placeholder="
                                                tt('report_observations_placeholder', 'Observações adicionais…')
                                            "
                                        ></textarea>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label small fw-semibold">{{
                                            tt('requesting_doctor_name_label', 'Nome do médico solicitante')
                                        }}</label>
                                        <input
                                            v-model="form.requesting_doctor_name"
                                            type="text"
                                            class="form-control form-control-sm"
                                            maxlength="255"
                                            :placeholder="
                                                tt('requesting_doctor_name_placeholder', 'Nome do médico (opcional)')
                                            "
                                        />
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label small fw-semibold">{{
                                            tt('requesting_doctor_email_label', 'E-mail do médico solicitante')
                                        }}</label>
                                        <input
                                            v-model="form.requesting_doctor_email"
                                            type="email"
                                            class="form-control form-control-sm"
                                            maxlength="255"
                                            :placeholder="
                                                tt(
                                                    'requesting_doctor_email_placeholder',
                                                    'Para enviar o laudo por e-mail',
                                                )
                                            "
                                        />
                                        <div class="form-text" data-testid="requesting-doctor-email-hint">
                                            {{
                                                tt(
                                                    'requesting_doctor_email_hint',
                                                    'O envio por e-mail só é liberado depois que o prontuário for assinado.',
                                                )
                                            }}
                                        </div>
                                    </div>
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
                                                            style="
                                                                object-fit: cover;
                                                                border-radius: 3px;
                                                                flex-shrink: 0;
                                                            "
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

                                            <DictationButton
                                                v-if="dictation.available && urls.dictate"
                                                :url="urls.dictate"
                                                :max-seconds="dictation.max_seconds"
                                                :disabled="saving || savingDraft || returningDraft || previewing"
                                                :t="t"
                                                @text="onDictated"
                                                @error="onDictationError"
                                                @busy="dictationBusy = $event"
                                            />

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
                                        data-testid="report-editor"
                                        :height="360"
                                        :disabled="previewing || saving || returningDraft"
                                        :placeholder="tt('report_content_label', 'Conteúdo do laudo')"
                                    />
                                    <div
                                        v-if="dictation.available && urls.dictate"
                                        class="form-text"
                                        data-testid="dictation-notice"
                                    >
                                        <i class="ti ti-shield-lock me-1"></i
                                        >{{
                                            tt(
                                                'dictation_notice',
                                                'O áudio é enviado a um provedor de IA aprovado para virar texto e não é guardado. Não cite o nome do paciente.',
                                            )
                                        }}
                                    </div>
                                </div>

                                <!-- Imagens no laudo (Fase 5 / 5b) — bloco opcional embutido no PDF
                                 assinado, separado do conteúdo narrativo acima. -->
                                <div class="mt-3 pt-3 border-top">
                                    <label class="form-check form-switch mb-0" style="cursor: pointer">
                                        <input
                                            v-model="imageReport.enabled"
                                            class="form-check-input"
                                            type="checkbox"
                                            data-testid="image-report-toggle"
                                            @change="onImageReportToggle"
                                        />
                                        <span class="form-check-label small fw-semibold">{{
                                            tt('image_report_enabled_label', 'Incluir galeria de imagens no laudo')
                                        }}</span>
                                    </label>

                                    <div v-if="imageReport.enabled" class="mt-2">
                                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                            <span class="small text-muted">{{
                                                tt('image_report_columns_label', 'Colunas:')
                                            }}</span>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button
                                                    v-for="n in [1, 2, 4, 6]"
                                                    :key="n"
                                                    type="button"
                                                    class="btn btn-sm"
                                                    :class="
                                                        imageReport.columns === n
                                                            ? 'btn-primary'
                                                            : 'btn-outline-secondary'
                                                    "
                                                    style="font-size: 0.7rem; min-width: 26px"
                                                    @click="imageReport.columns = n"
                                                >
                                                    {{ n }}
                                                </button>
                                            </div>

                                            <div class="vr"></div>

                                            <label class="form-check form-switch mb-0" style="cursor: pointer">
                                                <input
                                                    v-model="imageReport.pair_od_oe"
                                                    class="form-check-input"
                                                    type="checkbox"
                                                />
                                                <span class="form-check-label small">{{
                                                    tt('image_report_pair_od_oe', 'Agrupar OD/OE')
                                                }}</span>
                                            </label>

                                            <span
                                                v-if="imageReportAvailableToAdd.length"
                                                ref="imageReportPickerRef"
                                                class="position-relative ms-auto"
                                            >
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                    style="font-size: 0.72rem"
                                                    @click="showImageReportPicker = !showImageReportPicker"
                                                >
                                                    <i class="ti ti-plus me-1"></i
                                                    >{{ tt('image_report_add', 'Adicionar imagem') }}
                                                </button>
                                                <div
                                                    v-if="showImageReportPicker"
                                                    class="position-absolute end-0 mt-1 bg-body border rounded shadow-sm py-1"
                                                    style="z-index: 20; min-width: 220px"
                                                >
                                                    <button
                                                        v-for="img in imageReportAvailableToAdd"
                                                        :key="img.id"
                                                        type="button"
                                                        class="dropdown-item d-flex align-items-center gap-2 py-1 px-2 small w-100 text-start border-0 bg-transparent"
                                                        @click="addImageReportImage(img)"
                                                    >
                                                        <img
                                                            :src="img.url"
                                                            :alt="img.label"
                                                            width="36"
                                                            height="28"
                                                            style="
                                                                object-fit: cover;
                                                                border-radius: 3px;
                                                                flex-shrink: 0;
                                                            "
                                                        />
                                                        <span class="text-truncate">{{ img.label }}</span>
                                                    </button>
                                                </div>
                                            </span>
                                        </div>

                                        <p v-if="!imageReport.images.length" class="text-muted small mb-0">
                                            {{ tt('image_report_empty', 'Nenhuma imagem adicionada.') }}
                                        </p>

                                        <ul
                                            v-else
                                            class="list-unstyled d-flex flex-column gap-2 mb-0"
                                            data-testid="image-report-list"
                                        >
                                            <li
                                                v-for="(img, idx) in imageReport.images"
                                                :key="img.exam_id"
                                                class="d-flex align-items-start gap-2 border rounded p-2"
                                                data-testid="image-report-item"
                                            >
                                                <img
                                                    :src="img.url"
                                                    :alt="img.label"
                                                    width="64"
                                                    height="48"
                                                    style="object-fit: cover; border-radius: 3px; flex-shrink: 0"
                                                />
                                                <div class="flex-grow-1" style="min-width: 0">
                                                    <div class="small text-truncate fw-semibold">{{ img.label }}</div>
                                                    <input
                                                        v-model="img.caption"
                                                        type="text"
                                                        class="form-control form-control-sm mt-1"
                                                        maxlength="255"
                                                        :placeholder="
                                                            tt('image_report_caption_placeholder', 'Legenda (opcional)')
                                                        "
                                                    />
                                                </div>
                                                <div class="d-flex flex-column gap-1">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                        style="font-size: 0.65rem"
                                                        :disabled="idx === 0"
                                                        :title="tt('print_move_up', 'Mover para cima')"
                                                        @click="moveImageReportImage(idx, -1)"
                                                    >
                                                        <i class="ti ti-arrow-up"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                        style="font-size: 0.65rem"
                                                        :disabled="idx === imageReport.images.length - 1"
                                                        :title="tt('print_move_down', 'Mover para baixo')"
                                                        @click="moveImageReportImage(idx, 1)"
                                                    >
                                                        <i class="ti ti-arrow-down"></i>
                                                    </button>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger py-0 px-1"
                                                    style="font-size: 0.65rem"
                                                    :title="tt('image_report_remove', 'Remover')"
                                                    @click="removeImageReportImage(idx)"
                                                >
                                                    <i class="ti ti-x"></i>
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </fieldset>
                        </template>

                        <!-- Pós-salvar: confirmação + link do PDF já gerado -->
                        <div v-else class="text-center py-4">
                            <i class="ti ti-circle-check text-success" style="font-size: 2.6rem"></i>
                            <p class="fw-semibold mt-2 mb-1">{{ tt('report_saved', 'Laudo salvo com sucesso.') }}</p>
                            <p class="text-muted small mb-3">{{ savedResult.title }}</p>
                            <a :href="savedResult.pdf_url" target="_blank" class="btn btn-primary btn-sm">
                                <i class="ti ti-file-download me-1"></i>{{ tt('download_pdf', 'Baixar PDF') }}
                            </a>

                            <!-- Rascunho previsto por OUTRO médico que não quem emitiu agora
                                 (Fase 5 / revisão Codex) — texto já resolvido pode citar o nome/CRM
                                 de quem previu; nunca editado automaticamente, só revisão manual. -->
                            <div
                                v-if="savedResult.physician_mismatch_warning"
                                class="alert alert-warning text-start py-2 px-3 small mt-3 mb-0"
                                role="alert"
                            >
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                >{{ savedResult.physician_mismatch_warning }}
                            </div>

                            <div v-if="savedResult.requesting_doctor_email" class="mt-3 small">
                                <!-- Prontuário ainda não assinado: nada foi enviado e o envio nem existe
                                     aqui — vai para a lista de Documentações do prontuário depois da
                                     assinatura (o servidor recusaria o clique de qualquer jeito). -->
                                <span
                                    v-if="savedResult.requesting_doctor_awaiting_signature"
                                    class="text-muted d-block"
                                    data-testid="requesting-doctor-awaiting-signature"
                                >
                                    <i class="ti ti-clock me-1" aria-hidden="true"></i
                                    >{{
                                        tt(
                                            'requesting_doctor_awaiting_signature',
                                            'Destinatário salvo. O envio ao médico solicitante é liberado depois que o prontuário for assinado — faça o envio na lista de Documentações do prontuário.',
                                        )
                                    }}
                                </span>
                                <template v-else>
                                    <span v-if="savedResult.requesting_doctor_sent_at" class="text-success d-block">
                                        <i class="ti ti-mail-forward me-1"></i
                                        >{{
                                            fill(tt('requesting_doctor_sent_at', 'Enviado em :date'), {
                                                date: savedResult.requesting_doctor_sent_at,
                                            })
                                        }}
                                    </span>
                                    <span v-else class="text-muted d-block">{{
                                        tt('requesting_doctor_not_sent', 'Ainda não enviado')
                                    }}</span>
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm p-0 mt-1"
                                        :disabled="resending"
                                        @click="resendToRequestingDoctor"
                                    >
                                        <span v-if="resending" class="spinner-border spinner-border-sm me-1"></span>
                                        {{ tt('requesting_doctor_resend', 'Reenviar') }}
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer py-2">
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            :disabled="returningDraft"
                            @click="close"
                        >
                            {{ tt('close', 'Fechar') }}
                        </button>

                        <template v-if="!savedResult">
                            <!-- Médico: emite direto OU revisa/emite um rascunho (Fase 5) -->
                            <template v-if="isDoctor">
                                <button
                                    v-if="draftId && draftStatus === 'submitted'"
                                    type="button"
                                    class="btn btn-outline-warning btn-sm"
                                    :disabled="saving || returningDraft || dictationBusy"
                                    data-testid="draft-return-button"
                                    @click="returnDraft"
                                >
                                    <span v-if="returningDraft" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ti ti-arrow-back-up me-1"></i>
                                    {{ tt('draft_return', 'Devolver ao técnico') }}
                                </button>
                                <button
                                    v-if="draftId && draftIsEditable"
                                    type="button"
                                    class="btn btn-outline-danger btn-sm"
                                    :disabled="saving || returningDraft"
                                    @click="discardDraft"
                                >
                                    {{ tt('draft_discard', 'Descartar rascunho') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    data-testid="report-issue-button"
                                    :disabled="
                                        saving ||
                                        returningDraft ||
                                        previewing ||
                                        extractingPdf ||
                                        dictationBusy ||
                                        noExamChecked
                                    "
                                    @click="save(false)"
                                >
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ti ti-device-floppy me-1"></i>
                                    {{
                                        draftId
                                            ? tt('draft_review_issue', 'Revisar e emitir')
                                            : tt('report_save', 'Salvar laudo')
                                    }}
                                </button>
                            </template>

                            <!-- Secretária/admin: só preparam o rascunho, nunca emitem (Fase 5) -->
                            <template v-else>
                                <button
                                    v-if="draftId && draftIsEditable"
                                    type="button"
                                    class="btn btn-outline-danger btn-sm"
                                    :disabled="savingDraft || returningDraft"
                                    @click="discardDraft"
                                >
                                    {{ tt('draft_discard', 'Descartar rascunho') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-primary btn-sm"
                                    :disabled="
                                        savingDraft || returningDraft || previewing || dictationBusy || noExamChecked
                                    "
                                    @click="saveDraft(false)"
                                >
                                    <span v-if="savingDraft" class="spinner-border spinner-border-sm me-1"></span>
                                    {{ tt('draft_save', 'Salvar rascunho') }}
                                </button>
                                <button
                                    v-if="draftStatus !== 'submitted'"
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    data-testid="draft-submit-button"
                                    :disabled="
                                        savingDraft || returningDraft || previewing || dictationBusy || noExamChecked
                                    "
                                    @click="saveDraft(true)"
                                >
                                    {{
                                        draftStatus === 'returned'
                                            ? tt('draft_resubmit', 'Reenviar para o médico')
                                            : tt('draft_submit', 'Enviar para o médico')
                                    }}
                                </button>
                            </template>
                        </template>
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
