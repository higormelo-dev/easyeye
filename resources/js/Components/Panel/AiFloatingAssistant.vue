<script setup>
/**
 * AiFloatingAssistant — Assistente Virtual de IA, disponível em qualquer tela
 * do painel (montado uma única vez em AppLayout.vue, então sobrevive à
 * navegação SPA do Inertia entre Prontuário/Agenda/Pacientes/Gerenciador de
 * Imagens/etc. sem perder a conversa em andamento).
 *
 * Reaproveita o backbone transacional de IA já existente (AiRun: créditos,
 * multi-provider, circuit breaker, auditoria) via workflow=assistant_chat —
 * ver App\Domains\AI\Services\AiPayloadEnricher e AiRunDocumentationService.
 * Não duplica o painel estruturado (AiAssistantPanel.vue, usado no Prontuário/
 * Eye Image para o fluxo gerar→revisar→aprovar campo a campo): esta é uma
 * interação de CHAT LIVRE, mais leve, sem CID picker nem diff.
 *
 * Estados: closed (ícone flutuante) → open (janela compacta) → minimized
 * (ícone com badge de não lidas). `expanded` alterna janela compacta/ampla
 * dentro do estado open.
 *
 * Contexto de paciente/prontuário é SEMPRE opt-in — nunca enviado sem o
 * médico ativar o toggle "Usar contexto desta tela" (ver aiAssistantContext.js).
 */
import { ref, reactive, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { aiAssistantContext } from '@/Support/aiAssistantContext';

const props = defineProps({
    ai: { type: Object, required: true },
});

const t = computed(() => props.ai?.t ?? {});
const tt = (key, fallback = '') => t.value?.[key] ?? fallback;

// ── Estado da janela ─────────────────────────────────────────────────────────
const windowState = ref('closed'); // closed | open | minimized
const expanded    = ref(false);
const unreadCount = ref(0);

function openWidget() {
    windowState.value = 'open';
    unreadCount.value = 0;
    nextTick(() => textareaEl.value?.focus());
}
function minimizeWidget() { windowState.value = 'minimized'; }
function closeWidget() {
    windowState.value = 'closed';
    cancelPolling();
}
function toggleExpand() { expanded.value = !expanded.value; }

// ── Conversa ──────────────────────────────────────────────────────────────────
function newConversationId() {
    return (window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`);
}

const conversationId = ref(newConversationId());
const messages       = ref([]); // [{ id, role: 'user'|'assistant'|'error', content, pending, runId, usedContext, medicalRecordId, patientId }]
const userPrompt     = ref('');
const textareaEl     = ref(null);
const sending        = ref(false);

function startNewConversation() {
    cancelPolling();
    conversationId.value = newConversationId();
    messages.value = [];
    userPrompt.value = '';
}

// ── Contexto opt-in ───────────────────────────────────────────────────────────
const hasContextAvailable = computed(() => Boolean(
    aiAssistantContext.patient_id || aiAssistantContext.medical_record_id,
));
const useContext = ref(false);
// Se a tela mudar (ex.: saiu do prontuário do paciente A, entrou no B), o
// contexto disponível muda — desliga o toggle pra não vazar contexto de uma
// tela pro pedido feito em outra sem o médico perceber.
watch(() => aiAssistantContext.patient_id, () => { useContext.value = false; });
watch(() => aiAssistantContext.medical_record_id, () => { useContext.value = false; });

// ── Quick prompts ─────────────────────────────────────────────────────────────
const quickPrompts = computed(() => {
    const raw = tt('quick_prompts', {});
    return typeof raw === 'object' && raw ? Object.entries(raw) : [];
});
function applyQuickPrompt(prefix) {
    userPrompt.value = prefix;
    nextTick(() => {
        textareaEl.value?.focus();
        textareaEl.value?.setSelectionRange(prefix.length, prefix.length);
    });
}

// ── Envio + polling (mesmo padrão de backoff do AiAssistantPanel) ─────────────
let pollController = null;
let pollTimer       = null;

function cancelPolling() {
    pollController?.abort();
    pollController = null;
    clearTimeout(pollTimer);
    pollTimer = null;
}

function url(key, id) {
    const raw = props.ai?.urls?.[key];
    return id ? raw?.replace('__ID__', id) : raw;
}

async function sendMessage() {
    const prompt = userPrompt.value.trim();
    if (!prompt || sending.value) return;
    if (prompt.length < 12) {
        pushMessage('error', tt('min_chars_hint', 'Descreva a pergunta com pelo menos 12 caracteres.'));
        return;
    }

    sending.value = true;
    const contextActive = useContext.value && hasContextAvailable.value;

    pushMessage('user', prompt);
    userPrompt.value = '';

    const assistantMsg = pushMessage('assistant', '', { pending: true });

    const payload = {
        workflow: 'assistant_chat',
        mode: props.ai?.mode ?? 'economy',
        risk_level: 'low',
        user_prompt: prompt,
        conversation_id: conversationId.value,
        expects_json: false,
    };

    if (contextActive) {
        if (aiAssistantContext.patient_id) payload.patient_id = aiAssistantContext.patient_id;
        if (aiAssistantContext.medical_record_id) payload.medical_record_id = aiAssistantContext.medical_record_id;
        assistantMsg.usedContext = true;
        assistantMsg.medicalRecordId = aiAssistantContext.medical_record_id;
        assistantMsg.patientId = aiAssistantContext.patient_id;
    }

    try {
        const { data } = await window.axios.post(url('store'), payload);
        assistantMsg.runId = data.run_id;
        await pollRun(assistantMsg);
    } catch (e) {
        finishWithError(assistantMsg, e);
    } finally {
        sending.value = false;
    }
}

function pushMessage(role, content, extra = {}) {
    const msg = reactive({ id: newConversationId(), role, content, pending: false, ...extra });
    messages.value.push(msg);
    if (windowState.value === 'minimized' && role === 'assistant') unreadCount.value += 1;
    nextTick(scrollToBottom);
    return msg;
}

function finishWithError(msg, error) {
    msg.pending = false;
    msg.role = 'error';
    const status = error?.response?.status;
    if (status === 422 && error.response.data?.details) {
        msg.content = tt('error_generic', 'Não foi possível obter resposta. Tente novamente.')
            + ` (${error.response.data.message ?? ''})`;
    } else if (status === 403) {
        msg.content = error.response.data?.message ?? tt('error_generic', 'Não foi possível obter resposta.');
    } else {
        msg.content = tt('error_generic', 'Não foi possível obter resposta. Tente novamente.');
    }
    if (windowState.value === 'minimized') unreadCount.value += 1;
}

const BACKOFF_MS = [1500, 2000, 3000, 4500, 6000, 8000];
const DEADLINE_MS = 120000;

async function pollRun(msg) {
    pollController = new AbortController();
    const startedAt = Date.now();
    let attempt = 0;

    const tick = async () => {
        if (pollController.signal.aborted) return;

        if (Date.now() - startedAt > DEADLINE_MS) {
            finishWithError(msg, { response: { status: 0 } });
            return;
        }

        try {
            const { data } = await window.axios.get(url('show', msg.runId), { signal: pollController.signal });

            if (data.status === 'waiting_approval') {
                await autoApprove(msg, data.final_output ?? '');
                return;
            }

            if (data.status === 'approved') {
                msg.pending = false;
                msg.content = data.final_output ?? '';
                if (windowState.value === 'minimized') unreadCount.value += 1;
                nextTick(scrollToBottom);
                return;
            }

            if (['rejected', 'failed', 'cancelled'].includes(data.status)) {
                finishWithError(msg, { response: { status: 0, data: { message: data.error_message } } });
                return;
            }

            // pending/reserved/running — continua o poll com backoff.
            const delay = BACKOFF_MS[Math.min(attempt, BACKOFF_MS.length - 1)];
            attempt += 1;
            pollTimer = setTimeout(tick, delay);
        } catch (e) {
            if (pollController?.signal.aborted) return;
            finishWithError(msg, e);
        }
    };

    await tick();
}

// Chat livre não passa pela revisão manual do médico campo a campo (não é
// documento clínico estruturado) — aprova automaticamente assim que a
// execução termina, só pra liberar os créditos reservados e fechar o AiRun.
// Reaproveita 100% do endpoint approve() já existente (mesmo gate/ledger/
// auditoria); a guarda em AiRunDocumentationService impede que isso vire
// documentação no prontuário.
async function autoApprove(msg, finalOutput) {
    try {
        await window.axios.post(url('approve', msg.runId), { final_output: finalOutput });
        msg.pending = false;
        msg.content = finalOutput;
        if (windowState.value === 'minimized') unreadCount.value += 1;
        nextTick(scrollToBottom);
    } catch (e) {
        finishWithError(msg, e);
    }
}

// ── Inserir como evolução (integração citada no pedido: "monte uma evolução
//    com essas informações" dentro do prontuário) ───────────────────────────
const insertingEvolution = ref(null);
async function insertAsEvolution(msg) {
    if (!msg.medicalRecordId || !msg.patientId || insertingEvolution.value) return;
    insertingEvolution.value = msg.id;
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const storeUrl = route('panel.patients.medicalrecords.evolutions.store', [msg.patientId, msg.medicalRecordId]);
        await window.axios.post(storeUrl, { content: msg.content }, {
            headers: { 'X-CSRF-TOKEN': csrfToken },
        });
        msg.insertedAsEvolution = true;
    } catch (e) {
        // Erro silencioso na UI de chat — o médico ainda tem o texto pra copiar manualmente.
        console.error('Failed to insert evolution from AI chat:', e);
    } finally {
        insertingEvolution.value = null;
    }
}

const messagesEl = ref(null);
function scrollToBottom() {
    if (messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
}

function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

onBeforeUnmount(cancelPolling);
</script>

<template>
    <Teleport to="body">
        <div class="ai-floating-assistant">
            <!-- Ícone flutuante (fechado) -->
            <button v-if="windowState === 'closed'" type="button"
                    class="ai-fab" :title="tt('title', 'Assistente virtual')"
                    @click="openWidget">
                <i class="fas fa-wand-magic-sparkles"></i>
            </button>

            <!-- Minimizado: pill pequeno com badge -->
            <button v-else-if="windowState === 'minimized'" type="button"
                    class="ai-fab ai-fab--minimized" :title="tt('title', 'Assistente virtual')"
                    @click="openWidget">
                <i class="fas fa-wand-magic-sparkles"></i>
                <span v-if="unreadCount > 0" class="ai-fab-badge">{{ unreadCount }}</span>
            </button>

            <!-- Janela aberta -->
            <div v-else class="ai-chat-window" :class="{ 'ai-chat-window--expanded': expanded }">
                <div class="ai-chat-header">
                    <div class="ai-chat-header-title">
                        <i class="fas fa-wand-magic-sparkles me-2"></i>
                        <span>{{ tt('title', 'Assistente virtual') }}</span>
                    </div>
                    <div class="ai-chat-header-actions">
                        <button type="button" class="ai-icon-btn" :title="tt('new_conversation', 'Nova conversa')" @click="startNewConversation">
                            <i class="fas fa-rotate-left"></i>
                        </button>
                        <button type="button" class="ai-icon-btn" :title="expanded ? tt('collapse', 'Compactar') : tt('expand', 'Ampliar')" @click="toggleExpand">
                            <i :class="expanded ? 'fas fa-compress' : 'fas fa-expand'"></i>
                        </button>
                        <button type="button" class="ai-icon-btn" :title="tt('minimize', 'Minimizar')" @click="minimizeWidget">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" class="ai-icon-btn" :title="tt('close', 'Fechar')" @click="closeWidget">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <div class="ai-chat-disclaimer">
                    <i class="fas fa-shield-halved me-1"></i>{{ tt('disclaimer', 'Apoio à decisão — não substitui julgamento clínico.') }}
                </div>

                <!-- Toggle de contexto (só aparece quando a tela atual oferece contexto) -->
                <div v-if="hasContextAvailable" class="ai-chat-context">
                    <label class="ai-context-toggle">
                        <input type="checkbox" v-model="useContext">
                        <span>{{ tt('context_available', 'Usar contexto desta tela') }}</span>
                    </label>
                    <span v-if="aiAssistantContext.label" class="ai-context-label">{{ aiAssistantContext.label }}</span>
                    <i class="fas fa-circle-info ai-context-hint" :title="tt('context_hint', '')"></i>
                </div>

                <!-- Mensagens -->
                <div ref="messagesEl" class="ai-chat-messages">
                    <div v-if="messages.length === 0" class="ai-chat-empty">
                        {{ tt('empty_state', 'Envie uma pergunta para começar.') }}
                    </div>

                    <template v-for="msg in messages" :key="msg.id">
                        <div class="ai-msg" :class="`ai-msg--${msg.role}`">
                            <div class="ai-msg-bubble">
                                <span v-if="msg.pending" class="ai-msg-thinking">
                                    <span class="spinner-border spinner-border-sm me-1"></span>{{ tt('thinking', 'Pensando...') }}
                                </span>
                                <span v-else class="ai-msg-content">{{ msg.content }}</span>

                                <div v-if="msg.role === 'assistant' && !msg.pending && msg.medicalRecordId" class="ai-msg-actions">
                                    <button type="button" class="btn btn-outline-secondary btn-sm"
                                            :disabled="insertingEvolution === msg.id || msg.insertedAsEvolution"
                                            @click="insertAsEvolution(msg)">
                                        <i class="fas fa-notes-medical me-1"></i>
                                        {{ msg.insertedAsEvolution ? tt('inserted_as_evolution', 'Adicionado às evoluções.') : tt('insert_as_evolution', 'Inserir como evolução') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Quick prompts -->
                <div v-if="messages.length === 0 && quickPrompts.length" class="ai-chat-quick-prompts">
                    <button v-for="[label, prefix] in quickPrompts" :key="label" type="button"
                            class="ai-quick-chip" @click="applyQuickPrompt(prefix)">
                        {{ label }}
                    </button>
                </div>

                <!-- Composer -->
                <div class="ai-chat-composer">
                    <textarea ref="textareaEl" v-model="userPrompt" rows="2"
                              class="form-control form-control-sm"
                              :placeholder="tt('placeholder', 'Pergunte algo...')"
                              :disabled="sending"
                              @keydown="onKeydown"></textarea>
                    <button type="button" class="btn btn-primary btn-sm ai-send-btn"
                            :disabled="sending || userPrompt.trim().length === 0"
                            @click="sendMessage">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.ai-floating-assistant {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 1200;
}

.ai-fab {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    border: none;
    background: linear-gradient(135deg, #6c5ce7, #00b894);
    color: #fff;
    font-size: 1.25rem;
    box-shadow: 0 4px 14px rgba(0, 0, 0, .25);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: transform .15s ease;
}
.ai-fab:hover { transform: scale(1.06); }
.ai-fab--minimized { position: relative; }
.ai-fab-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #f62d51;
    color: #fff;
    font-size: .65rem;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
}

.ai-chat-window {
    width: 360px;
    height: 480px;
    max-height: 75vh;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, .25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.ai-chat-window--expanded {
    width: 520px;
    height: 640px;
}

.ai-chat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .6rem .8rem;
    background: linear-gradient(135deg, #6c5ce7, #00b894);
    color: #fff;
}
.ai-chat-header-title { font-weight: 600; font-size: .9rem; display: flex; align-items: center; }
.ai-chat-header-actions { display: flex; gap: .25rem; }
.ai-icon-btn {
    border: none;
    background: rgba(255, 255, 255, .18);
    color: #fff;
    width: 26px;
    height: 26px;
    border-radius: 6px;
    font-size: .7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
.ai-icon-btn:hover { background: rgba(255, 255, 255, .3); }

.ai-chat-disclaimer {
    font-size: .68rem;
    color: #856404;
    background: #fff8e1;
    padding: .35rem .7rem;
    border-bottom: 1px solid #f0e6c8;
}

.ai-chat-context {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .35rem .7rem;
    background: #f4f2ff;
    border-bottom: 1px solid #e8e4fb;
    font-size: .72rem;
    flex-wrap: wrap;
}
.ai-context-toggle { display: flex; align-items: center; gap: .3rem; cursor: pointer; margin: 0; }
.ai-context-label { color: #6c5ce7; font-weight: 600; }
.ai-context-hint { color: #9b95d6; cursor: help; }

.ai-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: .7rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    background: #fafafc;
}
.ai-chat-empty { color: #94a3b8; font-size: .8rem; text-align: center; margin-top: 1.5rem; }

.ai-msg { display: flex; }
.ai-msg--user { justify-content: flex-end; }
.ai-msg--assistant, .ai-msg--error { justify-content: flex-start; }
.ai-msg-bubble {
    max-width: 84%;
    padding: .5rem .7rem;
    border-radius: 10px;
    font-size: .82rem;
    line-height: 1.4;
    white-space: pre-wrap;
    word-break: break-word;
}
.ai-msg--user .ai-msg-bubble { background: #6c5ce7; color: #fff; border-bottom-right-radius: 2px; }
.ai-msg--assistant .ai-msg-bubble { background: #fff; border: 1px solid #e5e7eb; border-bottom-left-radius: 2px; }
.ai-msg--error .ai-msg-bubble { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6c2; }
.ai-msg-thinking { color: #94a3b8; font-size: .78rem; display: inline-flex; align-items: center; }
.ai-msg-actions { margin-top: .4rem; }

.ai-chat-quick-prompts {
    display: flex;
    flex-wrap: wrap;
    gap: .3rem;
    padding: 0 .7rem .5rem;
}
.ai-quick-chip {
    border: 1px solid #d8d4f7;
    background: #f4f2ff;
    color: #5a4fcf;
    font-size: .7rem;
    padding: .25rem .55rem;
    border-radius: 20px;
    cursor: pointer;
}
.ai-quick-chip:hover { background: #e8e4fb; }

.ai-chat-composer {
    display: flex;
    gap: .4rem;
    padding: .6rem .7rem;
    border-top: 1px solid #eee;
    background: #fff;
}
.ai-chat-composer textarea { resize: none; flex: 1; font-size: .82rem; }
.ai-send-btn { align-self: flex-end; }
</style>
