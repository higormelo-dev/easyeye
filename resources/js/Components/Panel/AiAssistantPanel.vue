<script setup>
/**
 * AiAssistantPanel — Painel único do Assistente de IA, reutilizado no módulo
 * Eye Image e no Prontuário.
 *
 * Onda 1: cancel cooperativo + polling com backoff + step label dinâmico +
 *         parseStructured robusto + aria-live.
 *
 * Onda 2 (UX clínica):
 *   F1 — split view (preview lateral das imagens no fluxo eye_image)
 *   F2 — quick picks categorizados por patologia
 *   F3 — diff visual (rascunho IA vs edição médica) antes de aprovar
 *   F4 — histórico dos últimos 5 runs do paciente (re-abre laudo em view)
 *   F5 — safety flags visíveis no review (alerta amarelo/vermelho)
 *   F6 — progress ring da cota mensal (cor por threshold)
 *   F7 — autofocus no textarea de resultado + Ctrl/Cmd+Enter aprova
 */
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { diffWords } from 'diff';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';

const props = defineProps({
    open:      { type: Boolean, required: true },
    ai:        { type: Object,  required: true },
    context:   { type: Object,  default: () => ({}) },
    viewReport: { type: Object, default: null },
});

const emit = defineEmits(['close', 'approved', 'inserted', 'needs-record']);

// ── Labels (do backend, com fallback PT) ────────────────────────────────────
const L = computed(() => props.ai?.assistant ?? {});
const lbl = (key, fallback = '') => L.value?.[key] ?? fallback;

const workflowLabels = computed(() => props.ai?.workflow_labels ?? {});
function workflowLabel(w) { return workflowLabels.value?.[w] ?? w; }

const PROVIDER_LABEL = { openai: 'ChatGPT', anthropic: 'Claude', gemini: 'Gemini' };
function providerLabel(code) { return PROVIDER_LABEL[code] ?? (code || ''); }

// ── Contexto ────────────────────────────────────────────────────────────────
const examIds      = computed(() => Array.isArray(props.context?.exam_ids) ? props.context.exam_ids : []);
const isImageFlow  = computed(() => (props.context?.workflow_default ?? '') === 'eye_image_analysis');
const isRecordFlow = computed(() => !!props.context?.medical_record_id && !isImageFlow.value);
const canInsert    = computed(() => !!props.context?.can_insert && isRecordFlow.value);
const patientId    = computed(() => props.context?.patient_id ?? null);

const availableWorkflows = computed(() => {
    const list = Array.isArray(props.ai?.workflows) ? props.ai.workflows : [];
    if (isImageFlow.value) return ['eye_image_analysis'];
    return list.length ? list : [props.context?.workflow_default].filter(Boolean);
});

// F1 — largura dinâmica: 1200px no fluxo eye_image, 720px nos demais.
const panelWidth = computed(() => (isImageFlow.value ? 1200 : 720));
const showSidePreview = computed(() => isImageFlow.value && examIds.value.length > 0 && step.value !== 'failed');

// ── F2 — Quick picks normalizados (aceita string[] OU Record<string,string[]>) ──
const rawQuickPicks = computed(() => L.value?.quick_picks ?? []);
const isCategorizedPicks = computed(() => rawQuickPicks.value
    && !Array.isArray(rawQuickPicks.value)
    && typeof rawQuickPicks.value === 'object');

const categories = computed(() => isCategorizedPicks.value ? Object.keys(rawQuickPicks.value) : []);
const activeCategory = ref('');
const effectiveCategory = computed(() => {
    if (!isCategorizedPicks.value) return '';
    if (activeCategory.value && categories.value.includes(activeCategory.value)) return activeCategory.value;
    return categories.value[0] ?? '';
});
const activePrompts = computed(() => {
    if (!isCategorizedPicks.value) {
        return Array.isArray(rawQuickPicks.value) ? rawQuickPicks.value : [];
    }
    return rawQuickPicks.value[effectiveCategory.value] ?? [];
});

const form = reactive({
    workflow: props.context?.workflow_default ?? props.ai?.default_workflow ?? 'record_assist',
    user_prompt: '',
});

const mode = computed(() => {
    const modes = Array.isArray(props.ai?.modes) ? props.ai.modes : [];
    return modes[0]?.value ?? 'economy';
});

const ETA_BY_MODE = { economy: 10, validated: 25, consensus: 45 };
const etaSeconds = computed(() => ETA_BY_MODE[mode.value] ?? 25);

// ── F6 — Cota mensal ────────────────────────────────────────────────────────
const quota = computed(() => props.ai?.quota ?? {});
const quotaPercent = computed(() => {
    const p = quota.value?.usage_percent;
    return typeof p === 'number' ? Math.min(100, Math.max(0, p)) : null;
});
const quotaColor = computed(() => {
    const p = quotaPercent.value;
    if (p === null) return 'var(--bs-secondary, #6c757d)';
    if (p >= 90) return 'var(--bs-danger, #dc3545)';
    if (p >= 70) return 'var(--bs-warning, #ffc107)';
    return 'var(--bs-success, #198754)';
});
const showQuotaAlert = computed(() => quotaPercent.value !== null && quotaPercent.value >= 80);
const quotaAlertText = computed(() => {
    const tpl = quotaPercent.value >= 90 ? lbl('quota_critical', '') : lbl('quota_warning', '');
    return tpl.replace(':percent', quotaPercent.value);
});
// Saldo de créditos avulsos (comprados/cortesia) — separado da cota mensal.
// balance.balance = avulso; balance.available = quota_remaining + avulso.
const purchasedCredits = computed(() => Number(props.ai?.balance?.balance ?? 0));

// Cota mensal totalmente consumida (consumed >= quota), independente de avulso.
const quotaFullyUsed = computed(() => {
    const q = Number(quota.value?.monthly_quota ?? 0);
    const c = Number(quota.value?.consumed_credits ?? 0);
    return q > 0 && c >= q;
});

// Onda 4, C4 — bloqueio do botão Analisar quando a cota mensal estoura.
// MAS: créditos avulsos (cortesia/comprados) continuam permitindo análise — o
// backend consome a cota primeiro e depois o saldo avulso. Só trava de fato
// quando a cota está ≥95% E não há avulso para cobrir.
const quotaExhausted = computed(() =>
    quotaPercent.value !== null
    && quotaPercent.value >= 95
    && purchasedCredits.value <= 0,
);

// Cota esgotada mas há avulso → segue funcionando consumindo avulso (aviso, não bloqueio).
const usingPurchasedCredits = computed(() => quotaFullyUsed.value && purchasedCredits.value > 0);

const quotaExhaustedText = computed(() => {
    const tpl = lbl('quota_exhausted', '');
    return tpl
        .replace(':consumed', quota.value?.consumed_credits ?? 0)
        .replace(':quota', quota.value?.monthly_quota ?? 0);
});

const usingPurchasedText = computed(() => {
    const tpl = lbl('quota_spillover', '');
    return tpl
        .replace(':consumed', quota.value?.consumed_credits ?? 0)
        .replace(':quota', quota.value?.monthly_quota ?? 0)
        .replace(':available', purchasedCredits.value);
});

// ── Estado do run ───────────────────────────────────────────────────────────
const step       = ref('idle');           // idle | processing | review | failed | view
const runId      = ref(null);
const runStatus  = ref('');
const runMode    = ref('');               // Onda 3: mode do run atual (para escalate)
const alertMsg   = ref('');
const alertType  = ref('');
const submitting = ref(false);
const acting     = ref(false);
const cancelling = ref(false);
const escalating = ref(false);

// ── Onda 3, P1 — Meus prompts ──────────────────────────────────────────────
const myPrompts = ref([]);

// ── Onda 3, P5 — Feedback inline ───────────────────────────────────────────
const FEEDBACK_TAGS = ['diagnosis_wrong', 'language', 'missing_context', 'excess', 'other'];
const feedbackPanel = ref(false);
const feedbackTags  = ref([]);
const feedbackNote  = ref('');

// F5 — Safety notes vindas do show()
const safetyNotes = ref([]);
const isHighRisk = computed(() => safetyNotes.value.some(
    n => typeof n === 'string' && /risco alto|high risk|high_risk/i.test(n),
));

const currentRole     = ref('');
const currentProvider = ref('');

const elapsed    = ref(0);
let elapsedTimer = null;
let pollAbort    = null;
let pollActive   = false;

const reviewText  = ref('');
const originalDraft = ref('');    // F3 — capturado em ingestResult, congelado durante review
const showDiff    = ref(false);
const suggestions = reactive({ diagnosis: '', conduct: '', observations: '' });
const isStructured = computed(() => form.workflow === 'record_assist');

// ── F3 — Diff visual ───────────────────────────────────────────────────────
const hasEdits = computed(() => originalDraft.value && reviewText.value !== originalDraft.value);
const diffParts = computed(() => {
    if (!hasEdits.value) return [];
    return diffWords(originalDraft.value, reviewText.value);
});

// ── Onda 3, P5 — Edit ratio (% de palavras alteradas pelo médico) ──────────
const editRatioPercent = computed(() => {
    if (!hasEdits.value || !originalDraft.value) return 0;
    const totalWords = (originalDraft.value.match(/\S+/g) || []).length || 1;
    const changed = diffParts.value
        .filter(p => p.added || p.removed)
        .reduce((sum, p) => sum + ((p.value.match(/\S+/g) || []).length), 0);
    return Math.min(100, Math.round((changed / totalWords) * 100));
});

const shouldAskFeedback = computed(() => editRatioPercent.value > 30);

// ── Onda 3, P2 — Escalate (reanalisar com modo superior) ───────────────────
const NEXT_MODE_BY_CURRENT = { economy: 'validated', validated: 'consensus' };
const TERMINAL_STATUSES = ['approved', 'rejected', 'failed', 'cancelled'];

const nextMode = computed(() => NEXT_MODE_BY_CURRENT[runMode.value] ?? null);
const canEscalate = computed(() => {
    if (!props.ai?.urls?.escalate || !runId.value) return false;
    if (!nextMode.value) return false;
    return TERMINAL_STATUSES.includes(runStatus.value);
});
const escalateLabel = computed(() => {
    const m = nextMode.value;
    if (!m) return lbl('escalate_in_top', 'Já está no modo superior');
    return m === 'consensus' ? lbl('escalate_consensus', 'Reanalisar com Consensus')
        : lbl('escalate_validated', 'Reanalisar com Validated');
});

// ── F4 — Histórico do paciente ──────────────────────────────────────────────
const history = ref([]);
const historyLoading = ref(false);
const historyOpen = ref(false);

// ── F1 — Preview lateral ────────────────────────────────────────────────────
const examUrls = reactive({});
const activeExamId = ref('');
const examsLoading = ref(false);

const textareaResultRef = ref(null);

function setAlert(type, msg) { alertType.value = type; alertMsg.value = msg; }
function clearAlert() { alertType.value = ''; alertMsg.value = ''; }

function resetRun() {
    stopTimers();
    step.value = 'idle';
    runId.value = null;
    runStatus.value = '';
    reviewText.value = '';
    originalDraft.value = '';
    showDiff.value = false;
    safetyNotes.value = [];
    currentRole.value = '';
    currentProvider.value = '';
    suggestions.diagnosis = suggestions.conduct = suggestions.observations = '';
    viewedRunMeta.value = null;
}

watch(() => props.open, (v) => {
    if (v) {
        clearAlert();
        resetRun();
        if (props.viewReport?.content) {
            reviewText.value = mdToHtml(props.viewReport.content);
            step.value = 'view';
            return;
        }
        form.workflow = props.context?.workflow_default ?? props.ai?.default_workflow ?? form.workflow;
        if (isCategorizedPicks.value && !activeCategory.value) {
            activeCategory.value = categories.value[0] ?? '';
        }
        if (!form.user_prompt) {
            form.user_prompt = activePrompts.value[0] ?? '';
        }
        fetchHistory();
        fetchMyPrompts();
        if (isImageFlow.value) fetchExamUrls();
    } else {
        stopTimers();
    }
});

// F7 — autofocus no resultado ao entrar em review
watch(step, async (s) => {
    if (s === 'review') {
        await nextTick();
        textareaResultRef.value?.focus();
    }
});

const elapsedFmt = computed(() => {
    const m = Math.floor(elapsed.value / 60);
    const s = elapsed.value % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
});

const stepLabel = computed(() => {
    // Caixa-preta: o usuário clínico nunca vê o provedor de IA.
    const key = currentRole.value === 'generator' ? 'step_generating'
        : currentRole.value === 'reviewer' ? 'step_reviewing'
            : currentRole.value === 'adjudicator' ? 'step_consolidating'
                : 'step_starting';
    const text = lbl(key, lbl('processing', 'Processando análise...')).toString();

    return text.replace(/:provider/g, '').replace(/\s{2,}/g, ' ').replace(/\s+…/g, '…').trim();
});

function url(key, id = null) {
    let u = props.ai?.urls?.[key] ?? '';
    if (id) u = u.replace('__ID__', id);
    return u;
}

function stopTimers() {
    pollActive = false;
    if (pollAbort) { try { pollAbort.abort(); } catch { /* noop */ } pollAbort = null; }
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
}

onBeforeUnmount(stopTimers);

// Quando o componente é montado já com props.open === true (caso comum em
// testes e em painéis abertos imediatamente após navegação), o watch(open)
// não dispara — então buscamos prompts/histórico/exames aqui também.
onMounted(() => {
    if (props.open) {
        fetchHistory();
        fetchMyPrompts();
        if (isImageFlow.value) fetchExamUrls();
    }
});

function buildPayload() {
    return {
        workflow:          form.workflow,
        mode:              mode.value,
        risk_level:        'medium',
        patient_id:        props.context?.patient_id ?? null,
        medical_record_id: props.context?.medical_record_id ?? null,
        user_prompt:       form.user_prompt,
        exam_ids:          isImageFlow.value ? examIds.value : [],
        max_output_tokens: 900,
    };
}

function validate() {
    if (isImageFlow.value && examIds.value.length === 0) {
        setAlert('warning', lbl('no_images', 'Selecione ao menos uma imagem para analisar.'));
        return false;
    }
    if (!form.user_prompt || form.user_prompt.trim().length < 12) {
        setAlert('warning', lbl('min_chars', 'Descreva o pedido com pelo menos 12 caracteres.'));
        return false;
    }
    return true;
}

async function analyze() {
    clearAlert();
    if (!validate()) return;
    submitting.value = true;
    resetRun();
    step.value = 'processing';
    startElapsed();
    try {
        const { data } = await window.axios.post(url('store'), buildPayload());
        runId.value = data?.run_id ?? null;
        if (!runId.value) { fail(lbl('failed', 'Não foi possível iniciar a análise.')); return; }
        await poll();
    } catch (error) {
        const m = error?.response?.data?.message;
        const details = error?.response?.data?.details;
        fail(details
            ? `${m} (${details.requested}/${details.available})`
            : (m ?? lbl('failed', 'Não foi possível iniciar a análise.')));
    } finally {
        submitting.value = false;
    }
}

function startElapsed() {
    elapsed.value = 0;
    if (elapsedTimer) clearInterval(elapsedTimer);
    elapsedTimer = setInterval(() => { elapsed.value += 1; }, 1000);
}

const POLL_MIN_MS   = 1500;
const POLL_MAX_MS   = 8000;
const POLL_FACTOR   = 1.5;
const POLL_DEADLINE = 5 * 60 * 1000;

async function poll() {
    pollActive = true;
    const showUrl = url('show', runId.value);
    let delay = POLL_MIN_MS;
    const deadline = Date.now() + POLL_DEADLINE;

    while (pollActive && Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, delay));
        if (!pollActive) return;
        try {
            pollAbort = new AbortController();
            const { data } = await window.axios.get(showUrl, {
                signal: pollAbort.signal,
                headers: { Accept: 'application/json' },
            });
            const run = data?.data ?? {};
            runStatus.value       = run.status ?? '';
            runMode.value         = run.mode ?? '';
            currentRole.value     = run.current_role ?? '';
            currentProvider.value = run.current_provider ?? '';

            if (run.status === 'waiting_approval') {
                safetyNotes.value = Array.isArray(run.safety_notes) ? run.safety_notes : [];
                ingestResult(run.final_output ?? '');
                stopTimers();
                step.value = 'review';
                return;
            }
            if (run.status === 'cancelled') {
                stopTimers();
                step.value = 'idle';
                setAlert('info', lbl('cancelled', 'Análise cancelada. Créditos não consumidos foram devolvidos.'));
                return;
            }
            if (['failed', 'rejected'].includes(run.status)) {
                fail(run.error_message ?? lbl('failed', 'A análise não pôde ser concluída.'));
                return;
            }
            delay = POLL_MIN_MS;
        } catch (e) {
            if (e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED') return;
            delay = Math.min(POLL_MAX_MS, Math.ceil(delay * POLL_FACTOR));
        }
    }

    if (pollActive) {
        stopTimers();
        step.value = 'failed';
        setAlert('warning', lbl('timeout', 'A análise está demorando mais que o esperado. Você pode tentar novamente.'));
    }
}

// ── Markdown → HTML ────────────────────────────────────────────────────────
// O provedor devolve o laudo em markdown (## títulos, **negrito**, - listas).
// O TinyMCE renderiza HTML, então convertemos antes de exibir/editar. A saída
// é re-sanitizada no servidor (Purifier 'medical') na aprovação.
function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function inlineMd(s) {
    return s
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>')
        .replace(/`([^`]+)`/g, '<code>$1</code>');
}

function looksLikeHtml(s) {
    return /<\/?(p|div|ul|ol|li|h[1-6]|strong|em|br|table|span)\b/i.test(s);
}

function mdToHtml(raw) {
    const text = String(raw ?? '').trim();
    if (!text) return '';
    if (looksLikeHtml(text)) return text; // já é HTML (documentação salva) — preserva

    const lines = escapeHtml(text).split(/\r?\n/);
    const out = [];
    let listType = null;
    let paragraph = [];
    const closeList = () => { if (listType) { out.push(`</${listType}>`); listType = null; } };
    const flushP = () => {
        if (paragraph.length) { out.push('<p>' + inlineMd(paragraph.join('<br>')) + '</p>'); paragraph = []; }
    };

    for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line) { flushP(); closeList(); continue; }

        const heading = line.match(/^(#{1,6})\s+(.*)$/);
        if (heading) {
            flushP(); closeList();
            const lvl = heading[1].length;
            out.push(`<h${lvl}>` + inlineMd(heading[2]) + `</h${lvl}>`);
            continue;
        }

        const ul = line.match(/^[-*]\s+(.*)$/);
        if (ul) {
            flushP();
            if (listType !== 'ul') { closeList(); out.push('<ul>'); listType = 'ul'; }
            out.push('<li>' + inlineMd(ul[1]) + '</li>');
            continue;
        }

        const ol = line.match(/^\d+[.)]\s+(.*)$/);
        if (ol) {
            flushP();
            if (listType !== 'ol') { closeList(); out.push('<ol>'); listType = 'ol'; }
            out.push('<li>' + inlineMd(ol[1]) + '</li>');
            continue;
        }

        closeList();
        paragraph.push(line);
    }
    flushP(); closeList();
    return out.join('');
}

function ingestResult(output) {
    // F3 — preserva o rascunho original (já em HTML) para diff posterior contra a edição médica.
    if (isStructured.value) {
        const parsed = parseStructured(output);
        if (parsed) {
            const html = mdToHtml(parsed.summary || output);
            reviewText.value          = html;
            originalDraft.value       = html;
            suggestions.diagnosis     = parsed.suggestions.diagnosis;
            suggestions.conduct       = parsed.suggestions.conduct;
            suggestions.observations  = parsed.suggestions.observations;
        } else {
            const html = mdToHtml(output);
            reviewText.value          = html;
            originalDraft.value       = html;
            suggestions.diagnosis     = '';
            suggestions.conduct       = '';
            suggestions.observations  = '';
        }
    } else {
        const html = mdToHtml(output);
        reviewText.value    = html;
        originalDraft.value = html;
    }
}

function parseStructured(raw) {
    if (!raw) return null;
    const candidate = stripFence(String(raw).trim());

    let obj = safeParse(candidate);
    if (!obj) obj = safeParse(extractFirstJsonObject(candidate));

    if (!obj || typeof obj !== 'object') return null;

    const sug = (obj.suggestions && typeof obj.suggestions === 'object') ? obj.suggestions : {};
    return {
        summary: typeof obj.summary === 'string' ? obj.summary : '',
        suggestions: {
            diagnosis:    typeof sug.diagnosis    === 'string' ? sug.diagnosis    : '',
            conduct:      typeof sug.conduct      === 'string' ? sug.conduct      : '',
            observations: typeof sug.observations === 'string' ? sug.observations : '',
        },
    };
}

function stripFence(text) {
    const fence = text.match(/```(?:json)?\s*([\s\S]*?)```/i);
    return fence ? fence[1].trim() : text;
}

function safeParse(text) {
    if (!text) return null;
    try { return JSON.parse(text); } catch { return null; }
}

function extractFirstJsonObject(text) {
    if (!text) return null;
    const start = text.indexOf('{');
    if (start === -1) return null;

    let depth = 0;
    let inString = false;
    let escape = false;
    for (let i = start; i < text.length; i++) {
        const ch = text[i];
        if (escape) { escape = false; continue; }
        if (ch === '\\') { escape = true; continue; }
        if (ch === '"') { inString = !inString; continue; }
        if (inString) continue;

        if (ch === '{') depth++;
        else if (ch === '}') {
            depth--;
            if (depth === 0) return text.slice(start, i + 1);
        }
    }
    return null;
}

function fail(msg) {
    stopTimers();
    step.value = 'failed';
    setAlert('danger', msg);
}

async function cancel() {
    if (!runId.value) {
        stopTimers();
        step.value = 'idle';
        clearAlert();
        return;
    }
    cancelling.value = true;
    try {
        await window.axios.post(url('cancel', runId.value), {});
        stopTimers();
        step.value = 'idle';
        setAlert('info', lbl('cancelled', 'Análise cancelada. Créditos não consumidos foram devolvidos.'));
        runId.value = null;
    } catch (error) {
        setAlert('danger', error?.response?.data?.message ?? lbl('cancel_failed', 'Não foi possível cancelar a análise.'));
    } finally {
        cancelling.value = false;
    }
}

function retry() {
    if (runId.value && step.value === 'failed' && (runStatus.value === 'running' || runStatus.value === 'reserved' || runStatus.value === 'pending')) {
        clearAlert();
        step.value = 'processing';
        startElapsed();
        poll();
        return;
    }
    analyze();
}

async function approve() {
    if (!runId.value) return;
    if (acting.value) return;
    if (!reviewText.value.trim()) return;
    acting.value = true;
    clearAlert();
    try {
        const payload = { final_output: reviewText.value };
        if (hasEdits.value) payload.original_draft = originalDraft.value;
        const { data } = await window.axios.post(url('approve', runId.value), payload);
        emit('approved', { run_id: runId.value, output: reviewText.value, data });
        if (data?.requires_record_confirmation) emit('needs-record', { run_id: runId.value });
        emit('close');
    } catch (error) {
        setAlert('danger', error?.response?.data?.message ?? lbl('failed', 'Falha ao registrar a decisão.'));
    } finally {
        acting.value = false;
    }
}

async function reject() {
    if (!runId.value) return;
    acting.value = true;
    clearAlert();
    try {
        await window.axios.post(url('reject', runId.value), {});
        resetRun();
        setAlert('info', lbl('rejected', 'Sugestão descartada. Ajuste o pedido e analise novamente, se quiser.'));
    } catch (error) {
        setAlert('danger', error?.response?.data?.message ?? lbl('failed', 'Falha ao rejeitar.'));
    } finally {
        acting.value = false;
    }
}

function insert(field) {
    if (!canInsert.value) return;
    const value = field === 'diagnosis' ? suggestions.diagnosis
        : field === 'conduct' ? suggestions.conduct
            : suggestions.observations;
    if (!value) return;
    emit('inserted', { field, value });
    setAlert('success', lbl('inserted', 'Inserido no prontuário.'));
}

// ── F4 — Histórico ─────────────────────────────────────────────────────────
async function fetchHistory() {
    history.value = [];
    if (!patientId.value || !props.ai?.urls?.by_patient) return;
    historyLoading.value = true;
    try {
        const { data } = await window.axios.get(url('by_patient', patientId.value));
        history.value = Array.isArray(data?.data) ? data.data : [];
    } catch {
        // silencioso: histórico é melhoria, não bloqueia análise
    } finally {
        historyLoading.value = false;
    }
}

// Onda 4, C3 — linhagem do run aberto pelo histórico, exibida no step view.
const viewedRunMeta = ref(null);

function openHistory(item) {
    reviewText.value = mdToHtml(item.final_output ?? item.preview ?? '');
    viewedRunMeta.value = item;
    step.value = 'view';
}

// ── F1 — URLs das imagens ──────────────────────────────────────────────────
async function fetchExamUrls() {
    if (!props.ai?.urls?.image_url) return;
    const ids = examIds.value;
    if (!ids.length) return;
    examsLoading.value = true;
    try {
        const results = await Promise.allSettled(
            ids.map(async (id) => {
                const res = await window.axios.get(url('image_url', id), { headers: { Accept: 'application/json' } });
                const u = res?.data?.url ?? res?.data?.urls?.image_url ?? '';
                return [id, u];
            }),
        );
        results.forEach((r) => {
            if (r.status === 'fulfilled' && r.value[1]) examUrls[r.value[0]] = r.value[1];
        });
        activeExamId.value = ids[0];
    } catch {
        // silencioso
    } finally {
        examsLoading.value = false;
    }
}

// ── F7 — Ctrl/Cmd+Enter aprova no review ───────────────────────────────────
function onKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        if (step.value === 'review' && !acting.value && reviewText.value.trim()) {
            e.preventDefault();
            maybeApprove();
        }
    }
}

const balance = computed(() => props.ai?.balance ?? {});

// ── Onda 3, P1 — Meus prompts (inline) ─────────────────────────────────────
async function fetchMyPrompts() {
    myPrompts.value = [];
    if (!props.ai?.urls?.my_prompts_index) return;
    try {
        const { data } = await window.axios.get(url('my_prompts_index'));
        myPrompts.value = Array.isArray(data?.data) ? data.data : [];
    } catch {
        // silencioso
    }
}

async function saveCurrentPrompt() {
    if (!form.user_prompt || form.user_prompt.trim().length < 12) {
        setAlert('warning', lbl('min_chars', 'Descreva o pedido com pelo menos 12 caracteres.'));
        return;
    }
    if (!props.ai?.urls?.my_prompts_store) return;
    const userLabel = window.prompt(lbl('save_prompt_label', 'Como você quer chamar este prompt?'), '');
    try {
        const { data } = await window.axios.post(url('my_prompts_store'), {
            label:  userLabel ?? '',
            prompt: form.user_prompt,
        });
        myPrompts.value = [...myPrompts.value, data];
        setAlert('success', lbl('saved', 'Prompt salvo.'));
    } catch (error) {
        setAlert('danger', error?.response?.data?.message ?? lbl('failed', 'Falha ao salvar.'));
    }
}

async function deleteMyPrompt(prompt) {
    if (!window.confirm(lbl('confirm_delete', 'Excluir este prompt?'))) return;
    try {
        await window.axios.delete(url('my_prompts_destroy', prompt.id));
        myPrompts.value = myPrompts.value.filter(p => p.id !== prompt.id);
    } catch (error) {
        setAlert('danger', error?.response?.data?.message ?? lbl('failed', 'Falha ao excluir.'));
    }
}

function applyMyPrompt(p) {
    form.user_prompt = p.prompt;
}

// ── Onda 3, P2 — Reanalisar com modo superior ──────────────────────────────
async function escalate() {
    if (!canEscalate.value || escalating.value) return;
    escalating.value = true;
    clearAlert();
    try {
        const { data } = await window.axios.post(url('escalate', runId.value), {});
        const newId = data?.run_id;
        if (!newId) {
            setAlert('danger', lbl('failed', 'Falha ao reanalisar.'));
            return;
        }
        runId.value     = newId;
        runStatus.value = data?.status ?? 'reserved';
        runMode.value   = data?.mode ?? '';
        resetReviewState();
        step.value = 'processing';
        startElapsed();
        poll();
    } catch (error) {
        setAlert('danger', error?.response?.data?.message ?? lbl('failed', 'Falha ao reanalisar.'));
    } finally {
        escalating.value = false;
    }
}

function resetReviewState() {
    reviewText.value = '';
    originalDraft.value = '';
    showDiff.value = false;
    safetyNotes.value = [];
    suggestions.diagnosis = suggestions.conduct = suggestions.observations = '';
    feedbackPanel.value = false;
    feedbackTags.value = [];
    feedbackNote.value = '';
}

// ── Onda 3, P5 — Feedback inline (edit ratio > 30%) ────────────────────────
function toggleFeedbackTag(tag) {
    if (feedbackTags.value.includes(tag)) {
        feedbackTags.value = feedbackTags.value.filter(t => t !== tag);
    } else {
        feedbackTags.value = [...feedbackTags.value, tag];
    }
}

async function submitFeedbackAndApprove() {
    if (!runId.value) return;
    feedbackPanel.value = false;
    // Aprovar primeiro; feedback é fire-and-forget (best effort)
    await approve();
    // Após o approve emitir close, este componente desmonta. Mas se aprovar
    // falhar, mantém feedbackPanel aberto.
    sendFeedback();
}

function skipFeedbackAndApprove() {
    feedbackPanel.value = false;
    approve();
}

async function sendFeedback() {
    if (!runId.value || !props.ai?.urls?.feedback) return;
    try {
        await window.axios.post(url('feedback', runId.value), {
            edit_ratio_percent: editRatioPercent.value,
            tags: feedbackTags.value,
            note: feedbackNote.value || null,
        });
    } catch {
        // silencioso: feedback é melhoria, não bloqueia aprovação
    }
}

// Wrap do approve do template para perguntar feedback quando ratio > 30%.
function maybeApprove() {
    if (shouldAskFeedback.value && !feedbackPanel.value) {
        feedbackPanel.value = true;
        return;
    }
    approve();
}

defineExpose({ parseStructured, extractFirstJsonObject, stripFence });
</script>

<template>
    <OffcanvasPanel :open="open" :width="panelWidth" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0">
                <i class="ti ti-robot me-1 text-info" aria-hidden="true"></i>{{ lbl('title', 'Assistente de IA') }}
            </h5>
        </template>

        <div class="ai-panel-body" :class="{ 'd-flex gap-3': showSidePreview }" @keydown="onKeydown" tabindex="-1">

            <!-- F1 — Preview lateral de imagens (eye_image flow) -->
            <aside v-if="showSidePreview" class="ai-side-preview" style="width:560px; flex-shrink:0;">
                <h6 class="text-muted small mb-2">
                    <i class="ti ti-photo me-1" aria-hidden="true"></i>{{ lbl('images_preview', 'Imagens para análise') }}
                </h6>
                <div v-if="examsLoading" class="text-center text-muted small py-3">
                    <div class="spinner-border spinner-border-sm me-1" aria-hidden="true"></div>{{ lbl('images_loading', 'Carregando imagens…') }}
                </div>
                <template v-else>
                    <div v-if="examUrls[activeExamId]" class="border rounded bg-light d-flex align-items-center justify-content-center mb-2"
                         style="height: 60vh; overflow: hidden;">
                        <img :src="examUrls[activeExamId]" alt=""
                             style="max-width:100%; max-height:100%; object-fit: contain;" />
                    </div>
                    <div v-if="examIds.length > 1" class="d-flex gap-1 flex-wrap">
                        <button v-for="id in examIds" :key="id" type="button"
                                class="btn btn-sm p-0 border rounded overflow-hidden"
                                :class="{ 'border-info border-2': id === activeExamId }"
                                style="width: 64px; height: 64px;"
                                @click="activeExamId = id">
                            <img v-if="examUrls[id]" :src="examUrls[id]" alt=""
                                 style="width:100%; height:100%; object-fit: cover;" />
                            <span v-else class="text-muted small">…</span>
                        </button>
                    </div>
                </template>
            </aside>

            <div class="ai-panel-main flex-grow-1 d-flex flex-column gap-3" style="min-width: 0;">

                <div class="alert alert-info py-2 small mb-0">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ lbl('support_notice', 'A IA é apoio clínico. A decisão final é sempre do médico responsável.') }}
                </div>

                <!-- F6 — Saldo + progress ring de cota -->
                <div class="d-flex flex-wrap align-items-center gap-3 small text-muted">
                    <span><strong>{{ balance.available ?? '—' }}</strong> {{ lbl('credits_available', 'Créditos disponíveis') }}</span>
                    <div v-if="quota.monthly_quota > 0" class="d-flex align-items-center gap-2"
                         :title="lbl('quota_used','').replace(':consumed', quota.consumed_credits).replace(':quota', quota.monthly_quota).replace(':percent', quotaPercent ?? 0)">
                        <svg width="32" height="32" viewBox="0 0 36 36" aria-hidden="true">
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e9ecef" stroke-width="3.5" />
                            <circle cx="18" cy="18" r="15.9" fill="none" :stroke="quotaColor" stroke-width="3.5"
                                    stroke-linecap="round"
                                    :stroke-dasharray="`${(quotaPercent ?? 0) * 0.999} 100`"
                                    transform="rotate(-90 18 18)" />
                        </svg>
                        <span>{{ lbl('quota_label', 'Cota mensal') }}: <strong>{{ quotaPercent ?? 0 }}%</strong></span>
                    </div>
                </div>

                <div v-if="alertMsg" :class="`alert alert-${alertType} py-2 small mb-0`" role="alert">{{ alertMsg }}</div>

                <div v-if="step === 'idle' && quotaExhausted" class="alert alert-danger py-2 small mb-0">
                    <i class="ti ti-circle-off me-1" aria-hidden="true"></i>{{ quotaExhaustedText }}
                </div>
                <div v-else-if="step === 'idle' && usingPurchasedCredits" class="alert alert-info py-2 small mb-0">
                    <i class="ti ti-wallet me-1" aria-hidden="true"></i>{{ usingPurchasedText }}
                </div>
                <div v-else-if="step === 'idle' && showQuotaAlert" :class="quotaPercent >= 90 ? 'alert alert-danger py-2 small mb-0' : 'alert alert-warning py-2 small mb-0'">
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ quotaAlertText }}
                </div>

                <!-- ── PASSO 1/2: contexto + pedido (idle) ── -->
                <template v-if="step === 'idle'">

                    <!-- F4 — Histórico de runs do paciente -->
                    <details v-if="history.length || historyLoading" class="border rounded p-2 small"
                             :open="historyOpen" @toggle="historyOpen = $event.target.open">
                        <summary class="fw-semibold text-muted" style="cursor:pointer;">
                            <i class="ti ti-history me-1" aria-hidden="true"></i>{{ lbl('history_title', 'Análises anteriores deste paciente') }}
                            <span v-if="history.length"> ({{ history.length }})</span>
                        </summary>
                        <div v-if="historyLoading" class="text-center text-muted py-2">{{ lbl('history_loading', 'Carregando…') }}</div>
                        <ul v-else class="list-unstyled mb-0 mt-2">
                            <li v-for="h in history" :key="h.id"
                                class="d-flex justify-content-between align-items-start border-bottom py-1">
                                <div class="me-2" style="min-width:0;">
                                    <div class="fw-semibold">
                                        {{ h.workflow_label }}
                                        <span v-if="h.is_escalation"
                                              class="badge bg-info-subtle text-info ms-1"
                                              :title="lbl('escalation_badge_title', 'Reanálise com modo superior')">
                                            <i class="ti ti-arrow-up" aria-hidden="true"></i> {{ lbl('escalation_badge', 'Reanálise') }}
                                        </span>
                                        <span class="text-muted">· {{ h.created_at }}</span>
                                    </div>
                                    <div class="text-muted text-truncate" :title="h.preview">{{ h.preview }}</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0" @click="openHistory(h)">
                                    <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ lbl('history_view', 'Ver') }}
                                </button>
                            </li>
                        </ul>
                    </details>

                    <div class="border rounded p-2 bg-light small d-flex align-items-center gap-2">
                        <i class="ti" :class="isImageFlow ? 'ti-photo' : 'ti-file-text'" aria-hidden="true"></i>
                        <span v-if="isImageFlow">{{ lbl('context_images', 'Imagens selecionadas') }}: <strong>{{ examIds.length }}</strong></span>
                        <span v-else>{{ lbl('context_record', 'Analisando o prontuário atual') }}</span>
                    </div>

                    <div v-if="availableWorkflows.length > 1">
                        <label class="form-label small fw-semibold">{{ lbl('title', 'Tipo') }}</label>
                        <select v-model="form.workflow" class="form-select form-select-sm">
                            <option v-for="w in availableWorkflows" :key="w" :value="w">{{ workflowLabel(w) }}</option>
                        </select>
                    </div>

                    <!-- F2 — Quick picks (chips de categoria + prompts) -->
                    <div v-if="isCategorizedPicks && categories.length">
                        <label class="form-label small fw-semibold">{{ lbl('quick_picks_label', 'Sugestões rápidas') }}</label>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <button v-for="cat in categories" :key="cat" type="button"
                                    class="btn btn-sm"
                                    :class="cat === effectiveCategory ? 'btn-info text-white' : 'btn-outline-secondary'"
                                    @click="activeCategory = cat">
                                {{ cat }}
                            </button>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <button v-for="(q, i) in activePrompts" :key="i" type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    :class="{ active: form.user_prompt === q }"
                                    @click="form.user_prompt = q">
                                {{ q }}
                            </button>
                        </div>
                    </div>
                    <div v-else-if="activePrompts.length">
                        <label class="form-label small fw-semibold">{{ lbl('quick_picks_label', 'Sugestões rápidas') }}</label>
                        <div class="d-flex flex-wrap gap-1">
                            <button v-for="(q, i) in activePrompts" :key="i" type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    :class="{ active: form.user_prompt === q }"
                                    @click="form.user_prompt = q">
                                {{ q }}
                            </button>
                        </div>
                    </div>

                    <!-- Onda 3, P1 — Meus prompts (chips do médico) -->
                    <div v-if="myPrompts.length">
                        <label class="form-label small fw-semibold">
                            <i class="ti ti-bookmark me-1" aria-hidden="true"></i>{{ lbl('my_prompts', 'Meus prompts') }}
                        </label>
                        <div class="d-flex flex-wrap gap-1">
                            <div v-for="p in myPrompts" :key="p.id" class="btn-group btn-group-sm" role="group">
                                <button type="button"
                                        class="btn btn-outline-info"
                                        :class="{ active: form.user_prompt === p.prompt }"
                                        :title="p.prompt"
                                        @click="applyMyPrompt(p)">
                                    {{ p.label }}
                                </button>
                                <button type="button"
                                        class="btn btn-outline-danger"
                                        :title="lbl('delete', 'Excluir')"
                                        @click="deleteMyPrompt(p)">
                                    <i class="ti ti-x" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="form-label small fw-semibold d-flex justify-content-between align-items-center">
                            <span>{{ lbl('prompt_label', 'O que você quer da IA?') }}</span>
                            <button v-if="ai?.urls?.my_prompts_store"
                                    type="button" class="btn btn-sm btn-link p-0 small"
                                    :disabled="!form.user_prompt || form.user_prompt.trim().length < 12 || myPrompts.length >= 5"
                                    @click="saveCurrentPrompt">
                                <i class="ti ti-bookmark-plus me-1" aria-hidden="true"></i>{{ lbl('save_as_my_prompt', 'Salvar como meu prompt') }}
                            </button>
                        </label>
                        <textarea v-model="form.user_prompt" class="form-control" rows="3"
                                  maxlength="30000"
                                  :placeholder="lbl('prompt_placeholder', 'Descreva o objetivo clínico.')"></textarea>
                    </div>
                </template>

                <template v-else-if="step === 'processing'">
                    <div class="text-center py-4" role="status" aria-live="polite" aria-busy="true">
                        <div class="spinner-border text-info mb-2" aria-hidden="true"></div>
                        <div class="fw-semibold">{{ stepLabel }}</div>
                        <div class="text-muted small mt-1">
                            {{ lbl('elapsed', 'Tempo decorrido') }}: {{ elapsedFmt }}
                            <span class="ms-2">·</span>
                            <span class="ms-2">{{ lbl('eta_hint', 'Tempo estimado: ~:eta s').replace(':eta', etaSeconds) }}</span>
                        </div>
                    </div>
                </template>

                <template v-else-if="step === 'failed'">
                    <div class="text-center py-3 text-danger">
                        <i class="ti ti-alert-triangle fs-2 d-block mb-1" aria-hidden="true"></i>
                        <div class="small">{{ alertMsg || lbl('failed', 'Não foi possível concluir a análise.') }}</div>
                    </div>
                </template>

                <template v-else-if="step === 'review'">

                    <!-- F5 — Safety flags -->
                    <div v-if="safetyNotes.length"
                         :class="isHighRisk ? 'alert alert-danger py-2 small mb-0' : 'alert alert-warning py-2 small mb-0'"
                         role="status">
                        <div class="fw-semibold mb-1">
                            <i class="ti ti-shield-check me-1" aria-hidden="true"></i>{{ lbl('safety_title', 'Verificações de segurança aplicadas pela IA') }}
                        </div>
                        <ul class="mb-0 ps-3">
                            <li v-for="(n, i) in safetyNotes" :key="i">{{ n }}</li>
                        </ul>
                    </div>

                    <div>
                        <label class="form-label fw-semibold">
                            <i class="ti ti-file-text me-1 text-info" aria-hidden="true"></i>{{ isStructured ? lbl('summary', 'Análise de apoio') : lbl('report', 'Laudo') }}
                        </label>
                        <TinyMceEditor v-model="reviewText" />
                    </div>

                    <!-- F3 — Diff visual -->
                    <div v-if="originalDraft">
                        <button type="button" class="btn btn-sm btn-link p-0"
                                :disabled="!hasEdits"
                                @click="showDiff = !showDiff">
                            <i class="ti" :class="showDiff ? 'ti-eye-off' : 'ti-git-compare'" aria-hidden="true"></i>
                            {{ hasEdits ? (showDiff ? lbl('hide_diff', 'Ocultar edições') : lbl('show_diff', 'Ver edições')) : lbl('no_changes', 'Sem alterações pelo médico.') }}
                        </button>
                        <div v-if="showDiff && hasEdits" class="border rounded p-2 mt-1 small bg-light"
                             style="white-space: pre-wrap; line-height: 1.5;">
                            <template v-for="(part, i) in diffParts" :key="i">
                                <ins v-if="part.added" class="diff-added" :aria-label="lbl('diff_added','adicionado:') + ' ' + part.value">{{ part.value }}</ins>
                                <del v-else-if="part.removed" class="diff-removed" :aria-label="lbl('diff_removed','removido:') + ' ' + part.value">{{ part.value }}</del>
                                <span v-else>{{ part.value }}</span>
                            </template>
                        </div>
                    </div>

                    <div v-if="isStructured && (suggestions.diagnosis || suggestions.conduct || suggestions.observations)">
                        <label class="form-label fw-semibold">{{ lbl('suggestions', 'Sugestões para o prontuário') }}</label>
                        <div v-if="!canInsert" class="alert alert-warning py-1 px-2 small mb-2">{{ lbl('locked_notice', 'Prontuário bloqueado: as sugestões não podem ser inseridas.') }}</div>
                        <div v-for="f in ['diagnosis','conduct','observations']" :key="f">
                            <div v-if="suggestions[f]" class="border rounded p-2 mb-2 small">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold text-muted">{{ lbl('field_' + f, f) }}</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0"
                                            :disabled="!canInsert" @click="insert(f)">
                                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ lbl('insert', 'Inserir') }}
                                    </button>
                                </div>
                                <div style="white-space:pre-wrap;">{{ suggestions[f] }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light border py-1 px-2 small mb-0">
                        <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ lbl('approve_hint', 'Ao aprovar, o laudo é registrado no prontuário do paciente.') }}
                    </div>

                    <!-- Onda 3, P5 — Feedback inline (edit_ratio > 30%) -->
                    <div v-if="feedbackPanel" class="border border-warning rounded p-2 small bg-light">
                        <div class="fw-semibold mb-1">
                            <i class="ti ti-message-circle-question me-1" aria-hidden="true"></i>{{ lbl('feedback_title', 'Você fez muitas alterações no rascunho. O que faltou?') }}
                        </div>
                        <div class="text-muted small mb-2">{{ lbl('feedback_subtitle', 'Sua resposta vira dados para melhorar a IA.') }}</div>

                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <label v-for="t in FEEDBACK_TAGS" :key="t" class="form-check form-check-inline mb-0">
                                <input type="checkbox" class="form-check-input"
                                       :checked="feedbackTags.includes(t)"
                                       @change="toggleFeedbackTag(t)" />
                                <span class="form-check-label">{{ lbl('feedback_tag_' + t, t) }}</span>
                            </label>
                        </div>
                        <textarea v-model="feedbackNote" class="form-control form-control-sm mb-2" rows="2"
                                  maxlength="2000"
                                  :placeholder="lbl('feedback_note_placeholder', 'Explique brevemente (opcional)…')"></textarea>
                        <div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="skipFeedbackAndApprove">
                                {{ lbl('feedback_skip', 'Pular feedback') }}
                            </button>
                            <button type="button" class="btn btn-sm btn-primary" @click="submitFeedbackAndApprove">
                                {{ lbl('feedback_submit_and_approve', 'Enviar e aprovar') }}
                            </button>
                        </div>
                    </div>
                </template>

                <template v-else-if="step === 'view'">
                    <label class="form-label fw-semibold">
                        <i class="ti ti-robot me-1 text-info" aria-hidden="true"></i>{{ lbl('report', 'Laudo') }}
                    </label>
                    <div v-if="viewedRunMeta?.is_escalation" class="alert alert-info py-1 px-2 small mb-2">
                        <i class="ti ti-arrow-up me-1" aria-hidden="true"></i>{{ lbl('parent_run_label', 'Esta análise é uma reanálise do run anterior.') }}
                    </div>
                    <div class="border rounded p-2 bg-light" style="white-space:pre-wrap;">{{ reviewText }}</div>
                </template>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="$emit('close')">
                {{ lbl('close', 'Fechar') }}
            </button>

            <template v-if="step === 'idle'">
                <button type="button" class="btn btn-info btn-sm text-white"
                        :disabled="submitting || quotaExhausted"
                        :title="quotaExhausted ? lbl('quota_exhausted_hint', '') : ''"
                        @click="analyze">
                    <span v-if="submitting" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-sparkles me-1" aria-hidden="true"></i>{{ lbl('analyze', 'Analisar com IA') }}
                </button>
            </template>

            <template v-else-if="step === 'processing'">
                <button type="button" class="btn btn-outline-danger btn-sm" :disabled="cancelling" @click="cancel">
                    <span v-if="cancelling" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-x me-1" aria-hidden="true"></i>{{ cancelling ? lbl('cancelling', 'Cancelando…') : lbl('cancel', 'Cancelar') }}
                </button>
            </template>

            <template v-else-if="step === 'failed'">
                <button v-if="canEscalate"
                        type="button" class="btn btn-outline-info btn-sm" :disabled="escalating" @click="escalate">
                    <span v-if="escalating" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-arrow-up me-1" aria-hidden="true"></i>{{ escalateLabel }}
                </button>
                <button type="button" class="btn btn-warning btn-sm text-dark" @click="retry">
                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ lbl('retry', 'Tentar novamente') }}
                </button>
            </template>

            <template v-else-if="step === 'review'">
                <button v-if="canEscalate"
                        type="button" class="btn btn-outline-info btn-sm" :disabled="escalating" @click="escalate">
                    <span v-if="escalating" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-arrow-up me-1" aria-hidden="true"></i>{{ escalateLabel }}
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" :disabled="acting" @click="reject">
                    <i class="ti ti-x me-1" aria-hidden="true"></i>{{ lbl('reject', 'Rejeitar') }}
                </button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="acting || !reviewText.trim() || feedbackPanel" @click="maybeApprove">
                    <span v-if="acting" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-check me-1" aria-hidden="true"></i>{{ lbl('approve', 'Aprovar') }}
                    <span class="d-none d-md-inline ms-1 text-white-50 small"
                          :title="lbl('kbd_hint', 'Aprovar com :keys').replace(':keys', 'Ctrl+Enter')">
                        <kbd class="bg-transparent border-0 small">Ctrl+↵</kbd>
                    </span>
                </button>
            </template>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.diff-added {
    background-color: rgba(40, 167, 69, 0.15);
    text-decoration: none;
    color: #0a4720;
    padding: 0 2px;
    border-radius: 2px;
}
.diff-removed {
    background-color: rgba(220, 53, 69, 0.12);
    text-decoration: line-through;
    color: #5a1a23;
    padding: 0 2px;
    border-radius: 2px;
}
</style>
