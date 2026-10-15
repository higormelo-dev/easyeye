<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useId, watch } from 'vue';
import axios from 'axios';
import { Head, router, usePage } from '@inertiajs/vue3';
import ReviewLayout from '@/Layouts/ReviewLayout.vue';
import ReviewItemCard from '@/Components/ClinicalReview/ReviewItemCard.vue';
import ReviewAttestationFields from '@/Components/ClinicalReview/ReviewAttestationFields.vue';
import ReviewSummary from '@/Components/ClinicalReview/ReviewSummary.vue';
import { makeTx } from '@/Support/recallRule.js';
import {
    draftPayload,
    initialState,
    orderedErrorPaths,
    submitPayload,
    validateReview,
} from '@/Support/clinicalReview.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { focusHeading } from '@/Support/clinicalReviewFocus.js';

/**
 * A página PÚBLICA do Revisor Clínico (ADR 0002): lê as regras de cada versão do convite,
 * decide, se identifica e envia o parecer UMA vez. Sem conta, sem dado de paciente.
 *
 * O texto digitado fica no SERVIDOR (rascunho cifrado, salvo sozinho) — nunca em
 * localStorage/sessionStorage de um computador que pode não ser dele. Enviar é em duas
 * etapas: preencher → conferir o resumo → confirmar (depois do POST não há como alterar).
 */
const props = defineProps({
    reviewer: { type: Object, required: true },
    invitation: { type: Object, required: true },
    items: { type: Array, required: true },
    available: { type: Boolean, default: true },
    exampleDate: { type: String, default: '' },
    statement: { type: Object, required: true },
    draft: { type: Object, default: null },
    draftSavedAt: { type: String, default: null },
    options: { type: Object, required: true },
    t: { type: Object, required: true },
    ruleT: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const page = usePage();
const tx = makeTx(() => props.t);
const { dateTime } = useLocaleFormat();
const uid = useId();

/** Id estável do campo de um caminho de erro (`decisions.<id>.comment`) — para rótulo e foco. */
const idFor = (path) => `rv-${uid}-${String(path).replace(/[^A-Za-z0-9]+/g, '-')}`;

// ── estado do formulário ────────────────────────────────────────────────────
const state = reactive(initialState(props.items, props.draft));

// A concordância vale para o texto que estava na tela quando foi marcada. Se a declaração muda com o formulário aberto,
// o servidor devolve `statement_changed` com o texto novo (e `preserveState` mantém o que foi digitado): a marca antiga
// NÃO vale para o texto novo — sem zerá-la, um 2º envio aceitaria a declaração nova com a concordância dada à velha.
// Só a concordância volta a desmarcada; as demais respostas ficam.
watch([() => props.statement.term_version_id, () => props.statement.sha256], () => {
    state.attestation.agreed = false;
});
const step = ref('form'); // form | confirm
const submitting = ref(false);
const showErrors = ref(false);
const clientErrors = ref({}); // caminho → código (errors.*)
const remoteErrors = ref({}); // caminho → texto do servidor

const heading = ref(null);
const errorSummary = ref(null);
const confirmHeading = ref(null);
const flashAlert = ref(null);

const flashError = computed(() => page.props.flash?.error ?? null);

const FIELD_KEYS = {
    name: 'attestation.name',
    email: 'attestation.email',
    crm_number: 'attestation.crm_number',
    crm_uf: 'attestation.crm_uf',
    rqe: 'attestation.rqe',
    specialty: 'attestation.specialty',
    has_conflict: 'attestation.conflict_title',
    conflict_description: 'attestation.conflict_description',
    allow_identity_display: 'attestation.display_title',
    agreed: 'attestation.agreed',
};
const ITEM_FIELD_KEYS = {
    decision: 'item.field_decision',
    comment: 'item.field_comment',
    proposed_changes: 'item.field_proposed',
};

/** Todos os erros, em texto: os do servidor valem até o campo ser editado; os daqui, até corrigir. */
const messages = computed(() => {
    const out = { ...remoteErrors.value };

    for (const [path, code] of Object.entries(clientErrors.value)) {
        out[path] = tx(`errors.${code}`);
    }

    return out;
});
const visibleErrors = computed(() => (showErrors.value ? messages.value : {}));
const errorPaths = computed(() => orderedErrorPaths(visibleErrors.value, props.items));

function labelOf(path) {
    const [scope, first, second] = String(path).split('.');

    if (scope === 'decisions') {
        const item = props.items.find((candidate) => candidate.id === first);

        return `${item?.title ?? ''} — ${tx(ITEM_FIELD_KEYS[second] ?? 'item.field_decision')}`.trim();
    }

    return FIELD_KEYS[first] ? tx(FIELD_KEYS[first]) : String(path);
}

function focusField(path) {
    const element = document.getElementById(idFor(path));

    element?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
    element?.focus?.({ preventScroll: true });
}

async function focusSummary() {
    await nextTick();
    errorSummary.value?.focus?.();
}

// ── edição ──────────────────────────────────────────────────────────────────
function clearRemote(prefixOrPath) {
    const next = { ...remoteErrors.value };

    for (const path of Object.keys(next)) {
        if (path === prefixOrPath || path.startsWith(`${prefixOrPath}.`)) delete next[path];
    }

    remoteErrors.value = next;
}

function updateItem(id, value) {
    state.decisions[id] = value;
    clearRemote(`decisions.${id}`);
}

function updateAttestation(value) {
    for (const key of Object.keys(value)) {
        if (value[key] !== state.attestation[key]) clearRemote(`attestation.${key}`);
    }

    state.attestation = value;
}

watch(
    state,
    () => {
        if (showErrors.value) clientErrors.value = validateReview(state, props.items, props.options.ufs);
    },
    { deep: true },
);

// ── rascunho (no servidor) ──────────────────────────────────────────────────
const draftStatus = ref(props.draftSavedAt ? 'saved' : 'idle'); // idle|saving|saved|error|ended
const savedAt = ref(props.draftSavedAt);
const endedUrl = ref(null);
// Reativo: `dirty` precisa recalcular quando uma gravação conclui (não só quando o formulário muda).
const lastSaved = ref(JSON.stringify(draftPayload(state)));
let timer = null;
let inflight = false;

const dirty = computed(() => JSON.stringify(draftPayload(state)) !== lastSaved.value);

function scheduleSave(delay = 1500) {
    clearTimeout(timer);
    timer = setTimeout(saveDraft, delay);
}

async function saveDraft() {
    clearTimeout(timer);

    const body = draftPayload(state);
    const json = JSON.stringify(body);

    if (inflight || draftStatus.value === 'ended' || json === lastSaved.value) return;

    inflight = true;
    draftStatus.value = 'saving';

    try {
        const { data } = await axios.put(props.urls.draft, body, { headers: { Accept: 'application/json' } });

        lastSaved.value = json;
        savedAt.value = data?.saved_at ?? new Date().toISOString();
        draftStatus.value = 'saved';
    } catch (error) {
        const status = error?.response?.status;

        if ([401, 409, 419].includes(status)) {
            // O convite saiu do ar (revogado, vencido, respondido) ou a sessão acabou: não adianta insistir.
            endedUrl.value = error.response?.data?.redirect ?? null;
            draftStatus.value = 'ended';
        } else {
            draftStatus.value = 'error';
            scheduleSave(status === 429 ? 20000 : 15000);
        }
    } finally {
        inflight = false;

        if (draftStatus.value === 'saved' && dirty.value) scheduleSave();
    }
}

watch(
    state,
    () => {
        if (draftStatus.value !== 'ended') scheduleSave();
    },
    { deep: true },
);

const locale = computed(() => String(page.props.locale ?? 'pt_BR').replace('_', '-'));

const draftText = computed(() => {
    switch (draftStatus.value) {
        case 'saving':
            return tx('draft.saving');
        case 'saved':
            return tx('draft.saved', {
                time: new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }).format(
                    new Date(savedAt.value ?? Date.now()),
                ),
            });
        case 'error':
            return tx('draft.error');
        case 'ended':
            return tx('draft.ended_short');
        default:
            return tx('draft.auto');
    }
});

function warnUnsaved(event) {
    if (!dirty.value || draftStatus.value === 'ended' || submitting.value) return;

    event.preventDefault();
    event.returnValue = '';
}

// ── sinal de vida ───────────────────────────────────────────────────────────
// A sessão dura 2 h sem atividade e uma regra longa pode levar mais que isso para ler. Um sinal de vida
// a cada 10 min a renova — e, se ela JÁ acabou (ou o convite saiu do ar), avisa o Revisor enquanto o
// texto dele ainda está na tela, em vez de só no "enviar".
const KEEPALIVE_MS = 10 * 60 * 1000;
let keepAliveTimer = null;

async function keepAlive() {
    if (draftStatus.value === 'ended' || !props.urls.keepalive) return;

    try {
        await axios.get(props.urls.keepalive, { headers: { Accept: 'application/json' } });
    } catch (error) {
        if ([401, 409, 419].includes(error?.response?.status)) {
            endedUrl.value = error.response?.data?.redirect ?? null;
            draftStatus.value = 'ended';
        }
    }
}

function onVisibility() {
    if (document.visibilityState === 'hidden') {
        saveDraft();
    } else {
        keepAlive();
    }
}

onMounted(() => {
    window.addEventListener('beforeunload', warnUnsaved);
    document.addEventListener('visibilitychange', onVisibility);
    keepAliveTimer = setInterval(keepAlive, KEEPALIVE_MS);
    nextTick(() => focusHeading(heading.value));
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    window.removeEventListener('beforeunload', warnUnsaved);
    document.removeEventListener('visibilitychange', onVisibility);
    clearInterval(keepAliveTimer);
});

// ── enviar ──────────────────────────────────────────────────────────────────
function review() {
    showErrors.value = true;
    remoteErrors.value = {};
    clientErrors.value = validateReview(state, props.items, props.options.ufs);

    if (Object.keys(clientErrors.value).length > 0) {
        focusSummary();

        return;
    }

    step.value = 'confirm';
    nextTick(() => confirmHeading.value?.focus?.());
}

function backToForm() {
    step.value = 'form';
    nextTick(() => heading.value?.focus?.());
}

async function send() {
    if (submitting.value || !props.available) return;

    submitting.value = true;
    await saveDraft(); // o que está na tela vai para o servidor antes do envio: se o envio voltar com aviso, nada se perde

    router.post(props.urls.submit, submitPayload(state, props.statement), {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            remoteErrors.value = errors;
            step.value = 'form';
            showErrors.value = true;
            focusSummary();
        },
        onSuccess: () => {
            // Aviso do servidor (conjunto ou declaração mudou): volta ao formulário com o aviso à vista.
            if (page.props.flash?.error) {
                step.value = 'form';
                nextTick(() => flashAlert.value?.focus?.());
            }
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="tx('show.title')" />

    <ReviewLayout :t="t" current="show" :locale-url="urls.locale">
        <h1 ref="heading" tabindex="-1" class="h3">{{ tx('show.title') }}</h1>
        <p class="lead mb-1">{{ tx('show.greeting', { name: reviewer.name }) }}</p>
        <p>{{ tx('show.lead') }}</p>
        <p class="small text-body-secondary" data-testid="valid-until">
            {{ tx('show.valid_until', { date: dateTime(invitation.expires_at) }) }}
        </p>

        <div class="review-note rounded border p-3 mb-3" role="note" data-testid="privacy-note">
            <strong>{{ tx('show.privacy_title') }}</strong> {{ tx('show.privacy_body') }}
        </div>

        <div
            v-if="flashError"
            ref="flashAlert"
            class="review-alert mb-3"
            role="alert"
            tabindex="-1"
            data-testid="flash-error"
        >
            {{ flashError }}
        </div>

        <div
            v-if="!available"
            class="review-alert review-alert--warning mb-3"
            role="alert"
            data-testid="set-unavailable"
        >
            {{ tx('show.unavailable') }}
        </div>

        <div
            v-if="draftStatus === 'ended'"
            class="review-alert review-alert--warning mb-3"
            role="alert"
            data-testid="draft-ended"
        >
            {{ tx('draft.ended') }}
            <a v-if="endedUrl" :href="endedUrl">{{ tx('draft.ended_action') }}</a>
        </div>

        <!-- 1) preencher -->
        <form v-if="step === 'form'" novalidate data-testid="review-form" @submit.prevent="review">
            <div
                v-if="errorPaths.length"
                ref="errorSummary"
                class="review-alert mb-3"
                role="alert"
                tabindex="-1"
                data-testid="error-summary"
            >
                <strong>{{ tx('show.errors_title', { count: errorPaths.length }) }}</strong>
                <ul class="mb-0 mt-1">
                    <li v-for="path in errorPaths" :key="path">
                        <a :href="`#${idFor(path)}`" @click.prevent="focusField(path)">{{ labelOf(path) }}</a
                        >: {{ visibleErrors[path] }}
                    </li>
                </ul>
            </div>

            <ReviewItemCard
                v-for="item in items"
                :key="item.id"
                :item="item"
                :model-value="state.decisions[item.id]"
                :decisions="options.decisions"
                :errors="visibleErrors"
                :example-date="exampleDate"
                :rule-t="ruleT"
                :t="t"
                :id-for="idFor"
                :disabled="submitting"
                @update:model-value="updateItem(item.id, $event)"
            />

            <ReviewAttestationFields
                :model-value="state.attestation"
                :ufs="options.ufs"
                :statement="statement"
                :errors="visibleErrors"
                :t="t"
                :id-for="idFor"
                :disabled="submitting"
                @update:model-value="updateAttestation"
            />

            <div class="d-flex flex-wrap align-items-center gap-3 mb-5">
                <button type="submit" class="btn btn-primary btn-lg" :disabled="!available" data-testid="review-button">
                    {{ tx('show.review') }}
                </button>
                <button type="button" class="btn btn-outline-primary" data-testid="save-draft" @click="saveDraft">
                    {{ tx('draft.save_now') }}
                </button>
                <span class="small text-body-secondary" role="status" aria-live="polite" data-testid="draft-status">{{
                    draftText
                }}</span>
            </div>
        </form>

        <!-- 2) conferir e confirmar -->
        <div v-else data-testid="review-confirm">
            <h2 ref="confirmHeading" tabindex="-1" class="h4">{{ tx('show.confirm_title') }}</h2>
            <p>{{ tx('show.confirm_lead') }}</p>

            <ReviewSummary :items="items" :state="state" :decisions="options.decisions" :statement="statement" :t="t" />

            <div class="d-flex flex-wrap align-items-center gap-3 mb-5">
                <button
                    type="button"
                    class="btn btn-outline-primary"
                    :disabled="submitting"
                    data-testid="back-button"
                    @click="backToForm"
                >
                    {{ tx('show.back') }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary btn-lg"
                    :disabled="submitting || !available"
                    data-testid="send-button"
                    @click="send"
                >
                    <span v-if="submitting" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                    {{ submitting ? tx('show.sending') : tx('show.send') }}
                </button>
            </div>
        </div>
    </ReviewLayout>
</template>
