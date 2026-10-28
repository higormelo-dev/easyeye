<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useFinanceAiRun } from './useFinanceAiRun';

/**
 * Análise por IA do período (ganhando, perdendo, oportunidades, ações): cada
 * conclusão traz o dado que a sustenta e pode virar pergunta no chat
 * ("Perguntar sobre isto"). Mostra de que período e quando foi gerada e avisa
 * quando a tela mudou de período. A última análise do mesmo período vem do
 * servidor — reabrir a tela não paga de novo.
 */
const props = defineProps({
    t: { type: Object, required: true }, // manager_finance.ai
    period: { type: Object, required: true }, // {preset, from, to}
    urls: { type: Object, required: true },
    initial: { type: Object, default: null }, // {result, from, to, created_at}
});

const emit = defineEmits(['ask']);

const { date, dateTime, number } = useLocaleFormat();
const { start } = useFinanceAiRun(props.urls);

const SECTIONS = [
    { key: 'ganhando', label: 'section_winning', preview: 'preview_winning', icon: 'ti-arrow-up-circle', tone: 'good' },
    { key: 'perdendo', label: 'section_losing', preview: 'preview_losing', icon: 'ti-arrow-down-circle', tone: 'bad' },
    {
        key: 'oportunidades',
        label: 'section_opportunities',
        preview: 'preview_opportunities',
        icon: 'ti-bulb',
        tone: 'opp',
    },
    {
        key: 'acoes_sugeridas',
        label: 'section_actions',
        preview: 'preview_actions',
        icon: 'ti-checklist',
        tone: 'action',
    },
];

/** Etapa exibida a cada STEP_SECONDS enquanto a IA trabalha (fica na última). */
const STEP_SECONDS = 8;

const busy = ref(false);
const digest = ref(props.initial?.result ? { ...props.initial } : null);

// Trocou o período e o servidor tem análise salva para ele: mostra essa. Sem
// análise salva, a anterior continua visível com o aviso de período diferente.
watch(
    () => props.initial?.run_id,
    (runId) => {
        if (runId && props.initial?.result && !busy.value) digest.value = { ...props.initial };
    },
);
const error = ref('');
const elapsed = ref(0);
const copied = ref(false);
let ticker = null;

const result = computed(() => digest.value?.result ?? null);
const stale = computed(
    () => !!digest.value && (digest.value.from !== props.period.from || digest.value.to !== props.period.to),
);
const shownFrom = computed(() => digest.value?.from ?? props.period.from);
const shownTo = computed(() => digest.value?.to ?? props.period.to);
const step = computed(() => {
    const steps = Array.isArray(props.t.steps) ? props.t.steps : [];
    return steps.length
        ? steps[Math.min(Math.floor(elapsed.value / STEP_SECONDS), steps.length - 1)]
        : props.t.thinking;
});

function tr(key, replace = {}) {
    let text = String(props.t[key] ?? key);
    for (const [k, v] of Object.entries(replace)) text = text.replaceAll(`:${k}`, v);
    return text;
}

function items(key) {
    const list = result.value?.[key];
    return Array.isArray(list) ? list.filter((item) => item && (item.titulo || item.detalhe)) : [];
}

function stopTicker() {
    clearInterval(ticker);
    ticker = null;
}

onBeforeUnmount(stopTicker);

async function generate() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    elapsed.value = 0;
    ticker = setInterval(() => (elapsed.value += 1), 1000);

    try {
        const run = await start('digest', {
            preset: props.period.preset,
            from: props.period.from,
            to: props.period.to,
        });

        if (run.status === 'approved' && run.result) {
            digest.value = {
                result: run.result,
                from: run.period?.from ?? props.period.from,
                to: run.period?.to ?? props.period.to,
                created_at: run.created_at ?? new Date().toISOString(),
            };
        } else if (run.status !== 'stopped') {
            error.value = run.status === 'timeout' ? props.t.error_timeout : props.t.error;
        }
    } catch (e) {
        error.value = e.response?.data?.message ?? props.t.error;
    } finally {
        busy.value = false;
        stopTicker();
    }
}

function ask(item) {
    emit(
        'ask',
        item.evidencia
            ? tr('ask_template', { title: item.titulo, evidence: item.evidencia })
            : tr('ask_template_no_evidence', { title: item.titulo }),
    );
}

function plainText() {
    const lines = [result.value?.resumo ?? ''];

    for (const section of SECTIONS) {
        const list = items(section.key);
        if (!list.length) continue;
        lines.push('', tr(section.label));
        for (const item of list) {
            lines.push(`- ${item.titulo}: ${item.detalhe}${item.evidencia ? ` (${item.evidencia})` : ''}`);
        }
    }

    return lines.join('\n').trim();
}

async function copy() {
    try {
        await navigator.clipboard.writeText(plainText());
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <section class="pfa-card h-100" aria-labelledby="pfa-digest-title" data-ai-digest>
        <header class="pfa-header">
            <div class="min-w-0">
                <h2 id="pfa-digest-title" class="pfa-title">
                    <i class="ti ti-sparkles me-1 text-primary" aria-hidden="true"></i>{{ t.title }}
                </h2>
                <p class="pfa-subtitle">{{ t.subtitle }}</p>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button
                    v-if="result && !busy"
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :title="copied ? t.copied : t.copy_digest"
                    :aria-label="t.copy_digest"
                    data-digest-copy
                    @click="copy"
                >
                    <i class="ti" :class="copied ? 'ti-check' : 'ti-copy'" aria-hidden="true"></i>
                </button>
                <button
                    type="button"
                    class="btn btn-sm"
                    :class="result ? 'btn-outline-secondary' : 'btn-primary'"
                    :disabled="busy"
                    data-digest-generate
                    @click="generate"
                >
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    <i v-else class="ti ti-sparkles me-1" aria-hidden="true"></i
                    >{{ result ? t.regenerate : t.generate }}
                </button>
            </div>
        </header>

        <div class="pfa-body">
            <div class="pfa-meta">
                <span class="pfa-chip" data-digest-period
                    ><i class="ti ti-calendar me-1" aria-hidden="true"></i
                    >{{ tr('period_chip', { from: date(shownFrom), to: date(shownTo) }) }}</span
                >
                <span v-if="digest?.created_at && !busy" class="pfa-muted" data-digest-generated-at>{{
                    tr('generated_at', { date: dateTime(digest.created_at) })
                }}</span>
            </div>

            <div
                v-if="stale && !busy"
                class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2 small"
                role="status"
                data-digest-stale
            >
                <i class="ti ti-clock-exclamation" aria-hidden="true"></i>
                <span class="flex-grow-1">{{
                    tr('stale', {
                        from: date(digest.from),
                        to: date(digest.to),
                        cfrom: date(period.from),
                        cto: date(period.to),
                    })
                }}</span>
                <button type="button" class="btn btn-sm btn-warning" @click="generate">{{ t.stale_action }}</button>
            </div>

            <div
                v-if="error"
                class="alert alert-danger d-flex flex-wrap align-items-center gap-2 py-2 small"
                role="alert"
                data-digest-error
            >
                <span class="flex-grow-1">{{ error }}</span>
                <button type="button" class="btn btn-sm btn-outline-danger" :disabled="busy" @click="generate">
                    {{ t.retry }}
                </button>
            </div>

            <!-- Gerando: prévia das seções + etapa atual -->
            <div v-if="busy" aria-live="polite" data-digest-busy>
                <p class="pfa-step">
                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>{{ step }}
                    <span class="pfa-muted">· {{ tr('elapsed', { seconds: number(elapsed) }) }}</span>
                </p>
                <div class="row g-2">
                    <div v-for="s in SECTIONS" :key="s.key" class="col-sm-6">
                        <div class="pfa-section" :class="`pfa-section--${s.tone}`" aria-hidden="true">
                            <div class="pfa-section-title"><i :class="`ti ${s.icon}`"></i>{{ tr(s.label) }}</div>
                            <div class="pfa-skeleton"></div>
                            <div class="pfa-skeleton pfa-skeleton--short"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vazio: o que a análise traz -->
            <div v-else-if="!result" data-digest-empty>
                <p class="pfa-empty-title">{{ t.empty_title }}</p>
                <div class="row g-2 mb-3">
                    <div v-for="s in SECTIONS" :key="s.key" class="col-sm-6">
                        <div class="pfa-preview" :class="`pfa-section--${s.tone}`">
                            <i :class="`ti ${s.icon} pfa-preview-icon`" aria-hidden="true"></i>
                            <div>
                                <div class="pfa-preview-title">{{ tr(s.label) }}</div>
                                <div class="pfa-muted">{{ tr(s.preview) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-center">
                    <button type="button" class="btn btn-primary" data-digest-empty-generate @click="generate">
                        <i class="ti ti-sparkles me-1" aria-hidden="true"></i>{{ t.generate }}
                    </button>
                    <p class="pfa-muted mt-2 mb-0">{{ t.empty_hint }}</p>
                </div>
            </div>

            <!-- Resultado -->
            <div v-else data-digest-result>
                <p class="pfa-summary">{{ result.resumo }}</p>
                <div class="row g-2">
                    <div v-for="s in SECTIONS" :key="s.key" class="col-sm-6">
                        <div class="pfa-section h-100" :class="`pfa-section--${s.tone}`" :data-digest-section="s.key">
                            <h3 class="pfa-section-title">
                                <i :class="`ti ${s.icon}`" aria-hidden="true"></i>{{ tr(s.label) }}
                            </h3>
                            <p v-if="!items(s.key).length" class="pfa-muted mb-0">—</p>
                            <ul v-else class="pfa-items">
                                <li v-for="(item, i) in items(s.key)" :key="i" class="pfa-item">
                                    <div class="pfa-item-title">{{ item.titulo }}</div>
                                    <div class="pfa-item-detail">{{ item.detalhe }}</div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                        <span v-if="item.evidencia" class="pfa-evidence"
                                            ><i class="ti ti-database me-1" aria-hidden="true"></i
                                            >{{ t.evidence_label }}: {{ item.evidencia }}</span
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-link btn-sm p-0 pfa-ask"
                                            data-digest-ask
                                            @click="ask(item)"
                                        >
                                            <i class="ti ti-message-question me-1" aria-hidden="true"></i
                                            >{{ t.ask_about }}
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
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
.pfa-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
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
.pfa-muted {
    font-size: 0.75rem;
    color: #64748b;
}
.pfa-step {
    font-size: 0.85rem;
    color: #334155;
    margin-bottom: 0.75rem;
}
.pfa-summary {
    font-weight: 600;
    font-size: 0.92rem;
    color: #1e293b;
    margin-bottom: 0.85rem;
}
.pfa-empty-title {
    font-weight: 600;
    font-size: 0.85rem;
    color: #334155;
    margin-bottom: 0.6rem;
}

.pfa-section,
.pfa-preview {
    border: 1px solid #e2e8f0;
    border-left-width: 3px;
    border-radius: 0.6rem;
    padding: 0.75rem 0.8rem;
}
.pfa-preview {
    display: flex;
    gap: 0.6rem;
    align-items: flex-start;
    height: 100%;
}
.pfa-preview-icon {
    font-size: 1.15rem;
    line-height: 1.2;
}
.pfa-preview-title {
    font-weight: 600;
    font-size: 0.82rem;
    color: #1e293b;
}
.pfa-section--good {
    border-left-color: #16a34a;
}
.pfa-section--good .ti {
    color: #16a34a;
}
.pfa-section--bad {
    border-left-color: #dc2626;
}
.pfa-section--bad .ti {
    color: #dc2626;
}
.pfa-section--opp {
    border-left-color: #7c3aed;
}
.pfa-section--opp .ti {
    color: #7c3aed;
}
.pfa-section--action {
    border-left-color: #0891b2;
}
.pfa-section--action .ti {
    color: #0891b2;
}
.pfa-section-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 700;
    font-size: 0.82rem;
    color: #1e293b;
    margin-bottom: 0.5rem;
}
.pfa-items {
    list-style: none;
    padding: 0;
    margin: 0;
}
.pfa-item + .pfa-item {
    margin-top: 0.7rem;
    padding-top: 0.7rem;
    border-top: 1px dashed #e2e8f0;
}
.pfa-item-title {
    font-weight: 600;
    font-size: 0.82rem;
    color: #1e293b;
}
.pfa-item-detail {
    font-size: 0.8rem;
    color: #475569;
}
.pfa-evidence {
    font-size: 0.72rem;
    color: #6d28d9;
    background: #f5f3ff;
    border-radius: 4px;
    padding: 2px 6px;
}
.pfa-evidence .ti {
    color: inherit;
}
.pfa-ask {
    font-size: 0.75rem;
    text-decoration: none;
}
.pfa-ask .ti {
    color: inherit;
}

.pfa-skeleton {
    height: 0.7rem;
    border-radius: 4px;
    margin-top: 0.5rem;
    background: linear-gradient(90deg, #eef2f7 25%, #f8fafc 50%, #eef2f7 75%);
    background-size: 200% 100%;
    animation: pfa-shimmer 1.4s ease-in-out infinite;
}
.pfa-skeleton--short {
    width: 60%;
}
@keyframes pfa-shimmer {
    from {
        background-position: 200% 0;
    }
    to {
        background-position: -200% 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .pfa-skeleton {
        animation: none;
    }
}

/* Modo escuro — mesma paleta da página (Finance/Index.vue). */
:root[data-bs-theme='dark'] .pfa-card {
    background: #121a26;
    border-color: #384559;
}
:root[data-bs-theme='dark'] .pfa-header {
    border-color: #384559;
}
/* Só as três bordas neutras: a lateral fica com a cor da seção. */
:root[data-bs-theme='dark'] .pfa-section,
:root[data-bs-theme='dark'] .pfa-preview {
    border-top-color: #384559;
    border-right-color: #384559;
    border-bottom-color: #384559;
}
:root[data-bs-theme='dark'] .pfa-title,
:root[data-bs-theme='dark'] .pfa-summary,
:root[data-bs-theme='dark'] .pfa-section-title,
:root[data-bs-theme='dark'] .pfa-item-title,
:root[data-bs-theme='dark'] .pfa-preview-title {
    color: #dbe4ef;
}
:root[data-bs-theme='dark'] .pfa-subtitle,
:root[data-bs-theme='dark'] .pfa-muted,
:root[data-bs-theme='dark'] .pfa-empty-title,
:root[data-bs-theme='dark'] .pfa-step {
    color: #8695a8;
}
:root[data-bs-theme='dark'] .pfa-item-detail {
    color: #9fb0c7;
}
:root[data-bs-theme='dark'] .pfa-chip {
    background: #18212f;
    color: #c3cfde;
}
:root[data-bs-theme='dark'] .pfa-item + .pfa-item {
    border-color: #384559;
}
:root[data-bs-theme='dark'] .pfa-evidence {
    color: #c4b5fd;
    background: rgba(124, 58, 237, 0.18);
}
:root[data-bs-theme='dark'] .pfa-skeleton {
    background: linear-gradient(90deg, #18212f 25%, #222d3d 50%, #18212f 75%);
    background-size: 200% 100%;
}
</style>
