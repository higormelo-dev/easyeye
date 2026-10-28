<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { safeMarkdown } from '@/Support/safeMarkdown';
import { useFinanceAiRun } from './useFinanceAiRun';

/**
 * "Converse com os dados": perguntas livres sobre o período da tela, em
 * conversa (o servidor manda o histórico ao modelo). Respostas formatadas com
 * markdown seguro, copiar resposta, tentar de novo e aviso quando o período
 * muda no meio da conversa. A conversa fica guardada só nesta aba
 * (sessionStorage) — recarregar a página não perde o que foi perguntado.
 */
const props = defineProps({
    t: { type: Object, required: true }, // manager_finance.ai
    period: { type: Object, required: true }, // {preset, from, to}
    urls: { type: Object, required: true },
    maxLength: { type: Number, default: 4000 },
});

/** Mesmo mínimo da validação do servidor (FinanceController::chat). */
const MIN_LENGTH = 4;
const STORAGE_KEY = 'easyeye.manager.finance.chat';

const { date, number, locale } = useLocaleFormat();
const { start } = useFinanceAiRun(props.urls);

const messages = ref([]); // {id, role: user|assistant|notice, content, at, pending, error, prompt}
const conversationId = ref(newId());
const input = ref('');
const busy = ref(false);
const log = ref(null);
const field = ref(null);
const copiedId = ref(null);

function newId() {
    return window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function tr(key, replace = {}) {
    let text = String(props.t[key] ?? key);
    for (const [k, v] of Object.entries(replace)) text = text.replaceAll(`:${k}`, v);
    return text;
}

const timeFormat = computed(() => new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }));

function time(iso) {
    const value = new Date(iso);
    return Number.isNaN(value.getTime()) ? '' : timeFormat.value.format(value);
}

const length = computed(() => input.value.length);
const canSend = computed(() => !busy.value && input.value.trim().length >= MIN_LENGTH);
const suggestions = computed(() => (Array.isArray(props.t.chat_suggestions) ? props.t.chat_suggestions : []));

function render(content) {
    return safeMarkdown(content);
}

function scrollDown() {
    nextTick(() => {
        if (log.value) log.value.scrollTop = log.value.scrollHeight;
    });
}

function persist() {
    try {
        sessionStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
                conversationId: conversationId.value,
                messages: messages.value.filter((m) => !m.pending),
            }),
        );
    } catch {
        // Sem armazenamento (modo privado/bloqueado): a conversa segue só em memória.
    }
}

function restore() {
    try {
        const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? 'null');
        if (saved?.conversationId && Array.isArray(saved.messages)) {
            conversationId.value = saved.conversationId;
            messages.value = saved.messages.filter((m) => m && typeof m.content === 'string');
        }
    } catch {
        // Conteúdo inválido: começa do zero.
    }
}

restore();
onMounted(scrollDown);

// Período trocado com a conversa aberta: as próximas respostas usam o novo.
watch(
    () => [props.period.from, props.period.to],
    ([from, to], [oldFrom, oldTo]) => {
        if (!messages.value.length || (from === oldFrom && to === oldTo)) return;
        messages.value.push({
            id: newId(),
            role: 'notice',
            content: tr('chat_period_changed', { from: date(from), to: date(to) }),
        });
        persist();
        scrollDown();
    },
);

async function request(prompt) {
    const answer = reactive({
        id: newId(),
        role: 'assistant',
        content: '',
        pending: true,
        error: false,
        prompt,
        at: null,
    });
    messages.value.push(answer);
    busy.value = true;
    scrollDown();

    try {
        const run = await start('chat', {
            user_prompt: prompt,
            conversation_id: conversationId.value,
            preset: props.period.preset,
            from: props.period.from,
            to: props.period.to,
        });

        if (run.status === 'approved') {
            answer.content = run.final_output ?? '';
        } else if (run.status !== 'stopped') {
            answer.error = true;
            answer.content = run.status === 'timeout' ? props.t.error_timeout : props.t.chat_error;
        }
    } catch (e) {
        answer.error = true;
        answer.content = e.response?.data?.message ?? props.t.chat_error;
    } finally {
        answer.pending = false;
        answer.at = new Date().toISOString();
        busy.value = false;
        persist();
        scrollDown();
    }
}

function send(text = null) {
    const fromInput = text === null;
    const prompt = String(fromInput ? input.value : text).trim();

    if (busy.value || prompt.length < MIN_LENGTH || prompt.length > props.maxLength) return;
    if (fromInput) input.value = '';

    messages.value.push({ id: newId(), role: 'user', content: prompt, at: new Date().toISOString() });
    request(prompt);
}

function retry(message) {
    if (busy.value) return;
    messages.value = messages.value.filter((m) => m.id !== message.id);
    request(message.prompt);
}

function newConversation() {
    conversationId.value = newId();
    messages.value = [];
    input.value = '';
    try {
        sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // Sem armazenamento: nada a limpar.
    }
}

function onKeydown(event) {
    // Enter envia; Shift+Enter quebra linha; durante composição (IME) não envia.
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
}

function autosize() {
    const el = field.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

watch(input, () => nextTick(autosize));

async function copy(message) {
    try {
        await navigator.clipboard.writeText(message.content);
        copiedId.value = message.id;
        setTimeout(() => {
            if (copiedId.value === message.id) copiedId.value = null;
        }, 2000);
    } catch {
        copiedId.value = null;
    }
}

/** "Perguntar sobre isto" da análise: preenche a pergunta (o admin revisa e envia). */
function ask(text) {
    input.value = text;
    nextTick(() => {
        field.value?.focus();
        field.value?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
    });
}

defineExpose({ ask });
</script>

<template>
    <section class="pfa-card h-100 d-flex flex-column" aria-labelledby="pfa-chat-title" data-ai-chat>
        <header class="pfa-header">
            <div class="min-w-0">
                <h2 id="pfa-chat-title" class="pfa-title">
                    <i class="ti ti-messages me-1 text-primary" aria-hidden="true"></i>{{ t.chat_title }}
                </h2>
                <p class="pfa-subtitle">{{ t.chat_subtitle }}</p>
            </div>
            <button
                v-if="messages.length"
                type="button"
                class="btn btn-sm btn-outline-secondary flex-shrink-0"
                :disabled="busy"
                data-chat-new
                @click="newConversation"
            >
                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.chat_new }}
            </button>
        </header>

        <div class="pfa-body d-flex flex-column flex-grow-1">
            <div class="mb-2">
                <span class="pfa-chip" data-chat-period
                    ><i class="ti ti-calendar me-1" aria-hidden="true"></i
                    >{{ tr('period_chip', { from: date(period.from), to: date(period.to) }) }}</span
                >
            </div>

            <div ref="log" class="pfa-log" role="log" aria-live="polite" :aria-label="t.chat_title" data-chat-log>
                <div v-if="!messages.length" class="pfa-chat-empty" data-chat-empty>
                    <i class="ti ti-message-chatbot pfa-chat-empty-icon" aria-hidden="true"></i>
                    <p class="pfa-chat-empty-title">{{ t.chat_suggestions_title }}</p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <button
                            v-for="s in suggestions"
                            :key="s"
                            type="button"
                            class="btn btn-sm pfa-suggestion"
                            :disabled="busy"
                            data-chat-suggestion
                            @click="send(s)"
                        >
                            {{ s }}
                        </button>
                    </div>
                </div>

                <template v-for="m in messages" :key="m.id">
                    <div v-if="m.role === 'notice'" class="pfa-notice" data-chat-notice>
                        <i class="ti ti-calendar-event me-1" aria-hidden="true"></i>{{ m.content }}
                    </div>
                    <div v-else class="pfa-msg" :class="`pfa-msg--${m.role}`" :data-chat-msg="m.role">
                        <div class="pfa-msg-meta">
                            <span>{{ m.role === 'user' ? t.chat_you : t.chat_ai }}</span>
                            <span v-if="m.at">· {{ time(m.at) }}</span>
                        </div>
                        <div v-if="m.pending" class="pfa-typing" data-chat-typing>
                            <span class="pfa-dots" aria-hidden="true"><i></i><i></i><i></i></span>{{ t.chat_typing }}
                        </div>
                        <template v-else-if="m.error">
                            <div class="pfa-msg-error" data-chat-error>{{ m.content }}</div>
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 pfa-action"
                                :disabled="busy"
                                data-chat-retry
                                @click="retry(m)"
                            >
                                <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ t.retry }}
                            </button>
                        </template>
                        <template v-else-if="m.role === 'assistant'">
                            <!-- safeMarkdown escapa todo o HTML antes de formatar -->
                            <div class="pfa-md" data-chat-answer v-html="render(m.content)"></div>
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 pfa-action"
                                :aria-label="t.chat_copy_answer"
                                data-chat-copy
                                @click="copy(m)"
                            >
                                <i
                                    class="ti"
                                    :class="copiedId === m.id ? 'ti-check' : 'ti-copy'"
                                    aria-hidden="true"
                                ></i>
                                {{ copiedId === m.id ? t.copied : t.copy }}
                            </button>
                        </template>
                        <div v-else class="pfa-msg-text">{{ m.content }}</div>
                    </div>
                </template>
            </div>

            <form class="pfa-composer" @submit.prevent="send()">
                <label for="pfa-chat-input" class="visually-hidden">{{ t.chat_input_label }}</label>
                <textarea
                    id="pfa-chat-input"
                    ref="field"
                    v-model="input"
                    rows="1"
                    class="form-control"
                    :placeholder="t.chat_placeholder"
                    :maxlength="maxLength"
                    aria-describedby="pfa-chat-hint"
                    data-chat-input
                    @keydown="onKeydown"
                ></textarea>
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="!canSend"
                    :aria-label="t.chat_send"
                    :title="t.chat_send"
                    data-chat-send
                >
                    <span v-if="busy" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <i v-else class="ti ti-send" aria-hidden="true"></i>
                </button>
            </form>
            <div id="pfa-chat-hint" class="pfa-hint">
                <span>{{ t.chat_hint }}</span>
                <span v-if="length > maxLength * 0.8" data-chat-counter>{{
                    tr('chat_counter', { count: number(length), max: number(maxLength) })
                }}</span>
            </div>
        </div>
    </section>
</template>

<style scoped>
.pfa-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    overflow: hidden;
}
.pfa-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e2e8f0;
}
/* Celular: os botões descem para a linha de baixo em vez de espremer o título. */
.pfa-header > :first-child {
    flex: 1 1 16rem;
    min-width: 0;
}
.pfa-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}
.pfa-subtitle {
    font-size: 0.78rem;
    color: #64748b;
    margin: 0.15rem 0 0;
}
.pfa-body {
    padding: 1rem;
}
.pfa-chip {
    display: inline-flex;
    align-items: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: #334155;
    background: #f1f5f9;
    border-radius: 999px;
    padding: 0.2rem 0.65rem;
}

.pfa-log {
    flex: 1 1 auto;
    min-height: 260px;
    max-height: 520px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    padding: 0.75rem;
    background: #f8fafc;
    border-radius: 0.6rem;
    margin-bottom: 0.6rem;
}
.pfa-chat-empty {
    margin: auto 0;
    text-align: center;
    padding: 0.5rem;
}
.pfa-chat-empty-icon {
    font-size: 1.8rem;
    color: #94a3b8;
}
.pfa-chat-empty-title {
    font-weight: 600;
    font-size: 0.85rem;
    color: #334155;
    margin: 0.35rem 0 0.6rem;
}
.pfa-suggestion {
    font-size: 0.78rem;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    padding: 0.25rem 0.75rem;
}
.pfa-suggestion:hover:not(:disabled),
.pfa-suggestion:focus-visible {
    border-color: var(--primary, #4f46e5);
    color: var(--primary, #4f46e5);
}

.pfa-msg {
    max-width: 88%;
    padding: 0.55rem 0.75rem;
    border-radius: 0.75rem;
    font-size: 0.85rem;
    line-height: 1.45;
}
.pfa-msg--user {
    align-self: flex-end;
    background: var(--primary, #4f46e5);
    color: #fff;
    border-bottom-right-radius: 0.25rem;
}
.pfa-msg--assistant {
    align-self: flex-start;
    background: #fff;
    border: 1px solid #e2e8f0;
    color: #1e293b;
    border-bottom-left-radius: 0.25rem;
}
.pfa-msg-meta {
    font-size: 0.68rem;
    opacity: 0.75;
    margin-bottom: 0.15rem;
}
.pfa-msg-text {
    white-space: pre-wrap;
    word-break: break-word;
}
.pfa-msg-error {
    color: #b91c1c;
}
.pfa-md :deep(p) {
    margin: 0 0 0.45rem;
}
.pfa-md :deep(p:last-child) {
    margin-bottom: 0;
}
.pfa-md :deep(ul),
.pfa-md :deep(ol) {
    margin: 0 0 0.45rem;
    padding-left: 1.2rem;
}
/* O template zera o marcador (ul li { list-style: none }); nas respostas ele ajuda a ler. */
.pfa-md :deep(ul > li) {
    list-style: disc;
}
.pfa-md :deep(ol > li) {
    list-style: decimal;
}
.pfa-md :deep(code) {
    font-size: 0.8em;
    background: #f1f5f9;
    border-radius: 3px;
    padding: 0 3px;
}
.pfa-action {
    font-size: 0.72rem;
    text-decoration: none;
    margin-top: 0.25rem;
}
.pfa-notice {
    align-self: center;
    font-size: 0.72rem;
    color: #64748b;
    background: #eef2f7;
    border-radius: 999px;
    padding: 0.2rem 0.75rem;
    text-align: center;
}
.pfa-typing {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #64748b;
}
.pfa-dots {
    display: inline-flex;
    gap: 3px;
}
.pfa-dots i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    animation: pfa-blink 1.2s infinite ease-in-out;
}
.pfa-dots i:nth-child(2) {
    animation-delay: 0.2s;
}
.pfa-dots i:nth-child(3) {
    animation-delay: 0.4s;
}
@keyframes pfa-blink {
    0%,
    80%,
    100% {
        opacity: 0.25;
    }
    40% {
        opacity: 1;
    }
}
@media (prefers-reduced-motion: reduce) {
    .pfa-dots i {
        animation: none;
        opacity: 0.6;
    }
}

.pfa-composer {
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
}
.pfa-composer textarea {
    resize: none;
    max-height: 160px;
    font-size: 0.85rem;
}
.pfa-hint {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.7rem;
    color: #64748b;
    margin-top: 0.3rem;
}

/* Modo escuro — mesma paleta da página (Finance/Index.vue). */
:root[data-bs-theme='dark'] .pfa-card {
    background: #121a26;
    border-color: #384559;
}
:root[data-bs-theme='dark'] .pfa-header {
    border-color: #384559;
}
:root[data-bs-theme='dark'] .pfa-title,
:root[data-bs-theme='dark'] .pfa-chat-empty-title {
    color: #dbe4ef;
}
:root[data-bs-theme='dark'] .pfa-subtitle,
:root[data-bs-theme='dark'] .pfa-hint,
:root[data-bs-theme='dark'] .pfa-typing {
    color: #8695a8;
}
:root[data-bs-theme='dark'] .pfa-chip {
    background: #18212f;
    color: #c3cfde;
}
:root[data-bs-theme='dark'] .pfa-log {
    background: #0d1219;
}
:root[data-bs-theme='dark'] .pfa-msg--assistant {
    background: #18212f;
    border-color: #384559;
    color: #dbe4ef;
}
:root[data-bs-theme='dark'] .pfa-md :deep(code) {
    background: #0d1219;
}
:root[data-bs-theme='dark'] .pfa-suggestion {
    background: #121a26;
    border-color: #384559;
    color: #c3cfde;
}
:root[data-bs-theme='dark'] .pfa-notice {
    background: #18212f;
    color: #8695a8;
}
:root[data-bs-theme='dark'] .pfa-msg-error {
    color: #fca5a5;
}
</style>
