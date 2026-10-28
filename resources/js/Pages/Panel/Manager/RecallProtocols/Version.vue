<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import RecallRuleCard from '@/Components/Recall/RecallRuleCard.vue';
import RecallContentDiff from '@/Components/Recall/RecallContentDiff.vue';
import RecallTimelineExamples from '@/Components/Recall/RecallTimelineExamples.vue';
import RecallProtocolEditor from '@/Components/Recall/RecallProtocolEditor.vue';
import { makeTx, stateBadgeClass, cloneContent, NEUTRAL_BADGE } from '@/Support/recallRule.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard.js';

/**
 * Manager → uma VERSÃO de protocolo de retorno. Rascunho: editor estruturado com
 * validação do servidor enquanto digita (prévia), salvar e congelar. Versão congelada:
 * leitura da regra, das alterações em relação à versão anterior, dos exemplos de datas e
 * da integridade (hash × texto gravado). O renderizador da regra é o mesmo do Revisor
 * Clínico e da clínica (RecallRuleCard).
 */
defineOptions({ name: 'ManagerRecallProtocolVersion' });

const props = defineProps({
    protocol: { type: Object, required: true },
    version: { type: Object, required: true },
    content: { type: Object, default: null },
    canonical: { type: String, default: '' },
    diff: { type: Array, default: () => [] },
    examples: { type: Array, default: () => [] },
    exampleDate: { type: String, default: '' },
    validation: { type: Object, default: () => ({ invitations: [] }) },
    currentReleased: { type: Object, default: null },
    events: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
    ruleT: { type: Object, default: () => ({}) },
});

const page = usePage();
const tx = makeTx(() => props.t);
const { date, dateTime } = useLocaleFormat();

const TABS = ['rule', 'changes', 'examples', 'validation', 'integrity'];
const tab = ref('rule');

// ── formulário do rascunho ──────────────────────────────────────────────────

const form = useForm({
    content: cloneContent(props.content),
    lock_version: props.version.lock_version,
});

const editable = computed(() => props.version.editable && props.content !== null);
const dirty = computed(() => editable.value && form.isDirty);
const previewErrors = ref({});
const previewHash = ref('');

/** Erros do servidor (salvar/congelar) + os da prévia em tempo real; o salvar vence. */
const errors = computed(() => ({ ...(page.props.errors ?? {}), ...previewErrors.value, ...form.errors }));
const hasErrors = computed(() => Object.keys(errors.value).length > 0);

const { bypass } = useUnsavedChangesGuard({
    isDirty: () => dirty.value,
    message: () => tx('version.leave_confirm'),
});

function resetFromProps() {
    form.defaults({ content: cloneContent(props.content), lock_version: props.version.lock_version });
    form.reset();
    form.clearErrors();
    previewErrors.value = {};
}

// O servidor devolveu outra versão do rascunho (salvou, ou outra sessão salvou): o formulário acompanha.
watch(
    () => props.version.lock_version,
    () => {
        if (props.version.editable) resetFromProps();
    },
);

function save() {
    if (!editable.value || form.processing) return;

    bypass(() =>
        form.put(route('manager.recall-protocols.versions.update', [props.protocol.id, props.version.id]), {
            preserveScroll: true,
            onSuccess: resetFromProps,
        }),
    );
}

// ── prévia (normaliza + valida no servidor enquanto digita) ──────────────────

let previewTimer = null;
let previewSeq = 0;
let previewAbort = null;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function runPreview() {
    const seq = ++previewSeq;
    previewAbort?.abort();
    previewAbort = new AbortController();

    try {
        const response = await fetch(route('manager.recall-protocols.preview', props.protocol.id), {
            method: 'POST',
            signal: previewAbort.signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ content: form.content, example_date: props.exampleDate || null }),
        });
        const json = await response.json().catch(() => ({}));

        if (seq !== previewSeq) return; // chegou depois de uma digitação mais nova

        if (response.ok && json.valid) {
            previewErrors.value = {};
            previewHash.value = json.hash;
        } else if (response.status === 422 && json.errors) {
            previewErrors.value = json.errors;
            previewHash.value = '';
        }
    } catch (error) {
        if (error?.name !== 'AbortError') previewHash.value = '';
    }
}

watch(
    () => form.content,
    () => {
        if (!editable.value || !form.isDirty) {
            previewErrors.value = {};
            previewHash.value = '';
            return;
        }
        clearTimeout(previewTimer);
        previewTimer = setTimeout(runPreview, 500);
    },
    { deep: true },
);

onBeforeUnmount(() => {
    clearTimeout(previewTimer);
    previewAbort?.abort();
});

// ── congelar ────────────────────────────────────────────────────────────────

const freezing = ref({ open: false, busy: false });

function askFreeze() {
    if (dirty.value) return;
    freezing.value = { open: true, busy: false };
}

function freeze() {
    freezing.value.busy = true;
    router.post(
        route('manager.recall-protocols.versions.freeze', [props.protocol.id, props.version.id]),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                freezing.value = { open: false, busy: false };
            },
        },
    );
}

// ── governança (liberar, suspender, retomar, aposentar, retirar) ─────────────

const GOVERNANCE_MIN = { suspend: 20, retire: 20, withdraw: 10 };
const governing = ref({ kind: null, busy: false });

const actions = computed(() => props.version.actions ?? {});
const marks = computed(() => props.version.marks ?? {});
const reasonError = computed(() => page.props.errors?.reason ?? '');

function askGovern(kind) {
    governing.value = { kind, busy: false };
}

function closeGovern() {
    if (!governing.value.busy) governing.value = { kind: null, busy: false };
}

function govern(reason = null) {
    const { kind } = governing.value;

    governing.value.busy = true;
    router.post(
        route(`manager.recall-protocols.versions.${kind}`, [props.protocol.id, props.version.id]),
        reason === null ? {} : { reason },
        {
            preserveScroll: true,
            onFinish: () => {
                governing.value = { kind: null, busy: false };
            },
        },
    );
}

/** Estilos dos avisos: cores do Bootstrap (contraste garantido nos dois temas), não as do alerta do template. */
const NOTE = {
    info: 'bg-info-subtle text-info-emphasis border-info-subtle',
    success: 'bg-success-subtle text-success-emphasis border-success-subtle',
    warning: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    neutral: 'bg-body-tertiary text-body-emphasis',
};

const decisionClass = {
    approved: 'text-bg-success',
    changes_requested: 'text-bg-warning',
    rejected: 'text-bg-danger',
};

// ── exemplos: data do último atendimento ─────────────────────────────────────

const exampleDateInput = ref(props.exampleDate);

watch(
    () => props.exampleDate,
    (value) => {
        exampleDateInput.value = value;
    },
);

function refreshExamples() {
    router.get(
        route('manager.recall-protocols.versions.show', [props.protocol.id, props.version.id]),
        { example_date: exampleDateInput.value },
        { preserveState: true, preserveScroll: true, only: ['examples', 'exampleDate'], replace: true },
    );
}

// ── abas e integridade ───────────────────────────────────────────────────────

function onTabKey(event, index) {
    const delta = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (!delta) return;
    event.preventDefault();
    const next = TABS[(index + delta + TABS.length) % TABS.length];
    tab.value = next;
    document.getElementById(`version-tab-${next}`)?.focus();
}

const copied = ref(false);

async function copyHash() {
    try {
        await navigator.clipboard.writeText(props.version.content_hash);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

const milestones = computed(() => [
    ['frozen', props.version.frozen_at, marks.value.frozen_by],
    ['validated', props.version.validated_at, null],
    ['released', props.version.released_at, marks.value.released_by],
    ['suspended', props.version.suspended_at, marks.value.suspended_by],
    ['retired', props.version.retired_at, marks.value.retired_by],
]);
</script>

<template>
    <AppLayout :title="tx('version.heading', { protocol: protocol.title, version: version.version })" :breadcrumbs="[]">
        <div class="container-fluid py-3">
            <PageHeader :title="tx('version.heading', { protocol: protocol.title, version: version.version })">
                <template #actions>
                    <Link :href="route('manager.recall-protocols.show', protocol.id)" class="btn btn-light btn-sm">
                        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ tx('version.back') }}
                    </Link>
                    <template v-if="editable">
                        <span
                            class="small me-1"
                            :class="dirty ? 'text-warning-emphasis' : 'text-body-secondary'"
                            role="status"
                            data-testid="dirty-status"
                            >{{ dirty ? tx('version.dirty') : tx('version.clean') }}</span
                        >
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            :disabled="form.processing || !dirty"
                            data-testid="save-draft"
                            @click="save"
                        >
                            <span
                                v-if="form.processing"
                                class="spinner-border spinner-border-sm me-1"
                                aria-hidden="true"
                            ></span>
                            {{ form.processing ? tx('version.saving') : tx('version.save') }}
                        </button>
                        <span :title="dirty ? tx('version.freeze_unsaved') : ''">
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm"
                                :disabled="dirty || form.processing"
                                data-testid="freeze"
                                @click="askFreeze"
                            >
                                <i class="ti ti-snowflake me-1" aria-hidden="true"></i>{{ tx('version.freeze') }}
                            </button>
                        </span>
                    </template>
                    <template v-else>
                        <Link
                            v-if="version.state === 'in_review'"
                            :href="route('manager.clinical-reviews.index')"
                            class="btn btn-outline-primary btn-sm"
                            data-testid="send-to-review"
                        >
                            <i class="ti ti-send me-1" aria-hidden="true"></i>{{ tx('governance.send_to_review') }}
                        </Link>
                        <button
                            v-if="actions.release"
                            type="button"
                            class="btn btn-success btn-sm"
                            data-testid="release"
                            @click="askGovern('release')"
                        >
                            <i class="ti ti-rocket me-1" aria-hidden="true"></i>{{ tx('governance.release.button') }}
                        </button>
                        <button
                            v-if="actions.resume"
                            type="button"
                            class="btn btn-primary btn-sm"
                            data-testid="resume"
                            @click="askGovern('resume')"
                        >
                            <i class="ti ti-player-play me-1" aria-hidden="true"></i
                            >{{ tx('governance.resume.button') }}
                        </button>
                        <button
                            v-if="actions.suspend"
                            type="button"
                            class="btn btn-outline-danger btn-sm"
                            data-testid="suspend"
                            @click="askGovern('suspend')"
                        >
                            <i class="ti ti-player-pause me-1" aria-hidden="true"></i
                            >{{ tx('governance.suspend.button') }}
                        </button>
                        <button
                            v-if="actions.withdraw"
                            type="button"
                            class="btn btn-outline-danger btn-sm"
                            data-testid="withdraw"
                            @click="askGovern('withdraw')"
                        >
                            <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i
                            >{{ tx('governance.withdraw.button') }}
                        </button>
                        <button
                            v-if="actions.retire"
                            type="button"
                            class="btn btn-outline-danger btn-sm"
                            data-testid="retire"
                            @click="askGovern('retire')"
                        >
                            <i class="ti ti-archive me-1" aria-hidden="true"></i>{{ tx('governance.retire.button') }}
                        </button>
                    </template>
                </template>
            </PageHeader>

            <p class="mb-3 d-flex flex-wrap align-items-center gap-2">
                <span class="badge fw-normal" :class="stateBadgeClass(version.state)" data-testid="state-badge">{{
                    version.state_label
                }}</span>
                <code :title="version.content_hash" data-testid="short-hash">{{ version.short_hash }}</code>
                <span v-if="version.parent" class="small text-body-secondary">
                    {{ tx('version.derived_from', { version: version.parent.version }) }}
                    <Link :href="route('manager.recall-protocols.versions.show', [protocol.id, version.parent.id])">
                        ({{ version.parent.state_label }})
                    </Link>
                </span>
            </p>

            <div
                v-if="!version.editable"
                class="rounded border p-2 px-3 small mb-3"
                :class="NOTE.neutral"
                role="note"
                data-testid="frozen-notice"
            >
                <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ tx('version.frozen_notice') }}
            </div>

            <div
                v-if="version.state === 'in_review'"
                class="rounded border p-2 px-3 small mb-3"
                :class="NOTE.info"
                role="note"
                data-testid="banner-in-review"
            >
                {{ tx('banner.in_review') }}
                <Link :href="route('manager.clinical-reviews.index')" class="fw-semibold">{{
                    tx('banner.open_reviews')
                }}</Link>
            </div>
            <div
                v-else-if="version.state === 'validated'"
                class="rounded border p-2 px-3 small mb-3"
                :class="NOTE.success"
                role="note"
                data-testid="banner-validated"
            >
                {{ tx('banner.validated') }}
            </div>
            <div
                v-else-if="version.state === 'declined'"
                class="rounded border p-2 px-3 small mb-3"
                :class="NOTE.neutral"
                role="note"
                data-testid="banner-declined"
            >
                {{ tx('banner.declined') }}
            </div>
            <div
                v-else-if="version.state === 'withdrawn'"
                class="rounded border p-2 px-3 small mb-3"
                :class="NOTE.neutral"
                role="note"
                data-testid="banner-withdrawn"
            >
                {{ tx('banner.withdrawn') }}
            </div>
            <div
                v-else-if="version.state === 'suspended'"
                class="rounded border p-2 px-3 small mb-3"
                :class="NOTE.warning"
                role="alert"
                data-testid="banner-suspended"
            >
                <strong>{{ tx('banner.suspended') }}</strong>
                <span v-if="marks.suspended_reason" class="d-block text-break">{{
                    tx('banner.suspended_detail', { reason: marks.suspended_reason })
                }}</span>
                <span class="d-block">
                    {{
                        tx('banner.by_on', {
                            name: marks.suspended_by ?? 'system',
                            date: dateTime(version.suspended_at),
                        })
                    }}
                </span>
                <span v-if="marks.suspended_from_label" class="d-block">{{
                    tx('banner.suspended_from', { state: marks.suspended_from_label })
                }}</span>
            </div>
            <div
                v-else-if="version.state === 'retired'"
                class="rounded border p-2 px-3 small mb-3"
                :class="NOTE.neutral"
                role="note"
                data-testid="banner-retired"
            >
                <strong>{{ tx('banner.retired') }}</strong>
                <span v-if="marks.retired_reason" class="d-block text-break">{{
                    tx('banner.retired_detail', { reason: marks.retired_reason })
                }}</span>
                <span class="d-block">
                    {{ tx('banner.by_on', { name: marks.retired_by ?? 'system', date: dateTime(version.retired_at) }) }}
                </span>
            </div>

            <div
                v-if="marks.review_due_status === 'soon' || marks.review_due_status === 'overdue'"
                class="rounded border p-2 px-3 small mb-3"
                :class="NOTE.warning"
                role="status"
                data-testid="banner-review-due"
            >
                {{
                    tx(marks.review_due_status === 'overdue' ? 'banner.review_due_overdue' : 'banner.review_due_soon', {
                        date: date(marks.review_due_at),
                    })
                }}
            </div>
            <div v-if="version.content_error" class="alert alert-danger" role="alert" data-testid="content-invalid">
                {{ tx('version.content_invalid') }}
            </div>

            <div
                class="nav nav-tabs mb-3"
                role="tablist"
                :aria-label="tx('version.heading', { protocol: protocol.title, version: version.version })"
            >
                <button
                    v-for="(name, index) in TABS"
                    :id="`version-tab-${name}`"
                    :key="name"
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === name }"
                    role="tab"
                    :aria-selected="tab === name ? 'true' : 'false'"
                    :aria-controls="`version-panel-${name}`"
                    :tabindex="tab === name ? 0 : -1"
                    :data-testid="`tab-${name}`"
                    @click="tab = name"
                    @keydown="onTabKey($event, index)"
                >
                    {{ tx(`version.tabs.${name}`) }}
                </button>
            </div>

            <!-- Regra -->
            <div
                v-show="tab === 'rule'"
                id="version-panel-rule"
                role="tabpanel"
                aria-labelledby="version-tab-rule"
                tabindex="0"
            >
                <template v-if="content">
                    <div v-if="editable" class="row g-4">
                        <div class="col-xl-7">
                            <p v-if="hasErrors" class="text-danger small" role="alert" data-testid="preview-invalid">
                                {{ tx('version.preview_invalid') }}
                            </p>
                            <p
                                v-else-if="previewHash"
                                class="small text-success"
                                role="status"
                                data-testid="preview-valid"
                            >
                                {{ tx('version.preview_valid', { hash: previewHash.slice(2, 14) }) }}
                            </p>
                            <p
                                v-if="errors.lock_version"
                                class="alert alert-warning small"
                                role="alert"
                                data-testid="stale"
                            >
                                {{ errors.lock_version }}
                            </p>
                            <RecallProtocolEditor
                                v-model="form.content"
                                :errors="errors"
                                :options="options"
                                :t="t"
                                :disabled="form.processing"
                                :requires-change-note="!!version.parent"
                            />
                        </div>
                        <div class="col-xl-5">
                            <div class="sticky-xl-top" style="top: 1rem">
                                <RecallRuleCard :content="form.content" :t="ruleT" :show-hints="false" />
                            </div>
                        </div>
                    </div>
                    <RecallRuleCard v-else :content="content" :t="ruleT" />
                </template>
            </div>

            <!-- Alterações -->
            <div
                v-show="tab === 'changes'"
                id="version-panel-changes"
                role="tabpanel"
                aria-labelledby="version-tab-changes"
                tabindex="0"
            >
                <RecallContentDiff :changes="diff" :has-parent="!!version.parent" :t="ruleT" />
            </div>

            <!-- Exemplos -->
            <div
                v-show="tab === 'examples'"
                id="version-panel-examples"
                role="tabpanel"
                aria-labelledby="version-tab-examples"
                tabindex="0"
            >
                <form class="row g-2 align-items-end mb-3" @submit.prevent="refreshExamples">
                    <div class="col-auto">
                        <label for="example-date" class="form-label mb-1">{{ ruleT.examples?.date_label }}</label>
                        <input
                            id="example-date"
                            v-model="exampleDateInput"
                            type="date"
                            class="form-control form-control-sm"
                            data-testid="example-date"
                        />
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-primary btn-sm" data-testid="example-refresh">
                            <i class="ti ti-refresh me-1" aria-hidden="true"></i>OK
                        </button>
                    </div>
                </form>
                <p v-if="editable" class="small text-body-secondary">{{ tx('version.unsaved_example') }}</p>
                <RecallTimelineExamples :examples="examples" :anchor="exampleDate" :t="ruleT" />
            </div>

            <!-- Validação: quem validou, quando e o quê -->
            <div
                v-show="tab === 'validation'"
                id="version-panel-validation"
                role="tabpanel"
                aria-labelledby="version-tab-validation"
                tabindex="0"
            >
                <h3 class="h6 fw-semibold">{{ tx('validation.title') }}</h3>
                <p class="small text-body-secondary">{{ tx('validation.intro') }}</p>

                <p v-if="!validation.invitations.length" data-testid="validation-empty">
                    {{ tx('validation.empty') }}
                    <Link :href="route('manager.clinical-reviews.index')">{{ tx('validation.open_reviews') }}</Link>
                </p>

                <article
                    v-for="invitation in validation.invitations"
                    :key="invitation.id"
                    class="card mb-3"
                    :data-testid="`validation-${invitation.id}`"
                >
                    <div class="card-header bg-transparent d-flex flex-wrap align-items-center gap-2">
                        <strong class="me-auto">{{ invitation.reviewer }}</strong>
                        <span class="badge fw-normal" :class="NEUTRAL_BADGE">{{ invitation.status_label }}</span>
                        <span
                            v-if="invitation.is_validating"
                            class="badge text-bg-success fw-normal"
                            data-testid="validating-badge"
                            >{{ tx('validation.validating') }}</span
                        >
                    </div>
                    <div class="card-body">
                        <p
                            v-if="!invitation.hash_matches"
                            class="rounded border p-2 small"
                            :class="NOTE.warning"
                            role="alert"
                            data-testid="hash-differs"
                        >
                            {{ tx('validation.hash_differs') }}
                        </p>

                        <dl class="row mb-0 small">
                            <dt class="col-md-3">{{ tx('validation.decision') }}</dt>
                            <dd class="col-md-9">
                                <span
                                    v-if="invitation.decision"
                                    class="badge fw-normal"
                                    :class="decisionClass[invitation.decision]"
                                    >{{ invitation.decision_label }}</span
                                >
                                <span v-else class="text-body-secondary">{{ tx('validation.no_decision') }}</span>
                            </dd>

                            <template v-if="invitation.comment">
                                <dt class="col-md-3">{{ tx('validation.comment') }}</dt>
                                <dd class="col-md-9 text-break" style="white-space: pre-line">
                                    {{ invitation.comment }}
                                </dd>
                            </template>
                            <template v-if="invitation.proposed_changes">
                                <dt class="col-md-3">{{ tx('validation.proposed') }}</dt>
                                <dd class="col-md-9 text-break" style="white-space: pre-line">
                                    {{ invitation.proposed_changes }}
                                </dd>
                            </template>

                            <template v-if="invitation.attestation">
                                <dt class="col-md-3">{{ tx('validation.declared') }}</dt>
                                <dd class="col-md-9">
                                    {{ invitation.attestation.name }} · {{ tx('validation.crm') }}
                                    {{ invitation.attestation.crm_number }}/{{ invitation.attestation.crm_uf }} ·
                                    {{ tx('validation.rqe') }} {{ invitation.attestation.rqe }}
                                </dd>
                                <dt class="col-md-3">{{ tx('validation.conflict') }}</dt>
                                <dd class="col-md-9">
                                    {{
                                        invitation.attestation.conflict_of_interest
                                            ? tx('validation.conflict_yes')
                                            : tx('validation.conflict_no')
                                    }}
                                </dd>
                                <dt class="col-md-3">{{ tx('validation.display') }}</dt>
                                <dd class="col-md-9">
                                    {{
                                        invitation.attestation.allow_identity_display
                                            ? tx('validation.display_yes')
                                            : tx('validation.display_no')
                                    }}
                                </dd>
                                <dt class="col-md-3">{{ tx('validation.signed_at') }}</dt>
                                <dd class="col-md-9">{{ dateTime(invitation.attestation.signed_at) }}</dd>
                                <dt class="col-md-3">{{ tx('validation.submission') }}</dt>
                                <dd class="col-md-9">
                                    <code class="text-break">{{ invitation.attestation.submission_hash }}</code>
                                </dd>
                            </template>

                            <dt class="col-md-3">{{ tx('validation.acceptance') }}</dt>
                            <dd class="col-md-9" :data-testid="`acceptance-${invitation.id}`">
                                <template v-if="invitation.acceptance">
                                    <span class="d-block">
                                        <strong>{{ invitation.acceptance.decision_label }}</strong> —
                                        {{
                                            tx('validation.accepted_by', {
                                                name: invitation.acceptance.accepted_by,
                                                date: dateTime(invitation.acceptance.checked_at),
                                            })
                                        }}
                                    </span>
                                    <span
                                        v-if="invitation.acceptance.crm_checked && invitation.acceptance.rqe_checked"
                                        class="d-block"
                                        >{{ tx('validation.checked') }}</span
                                    >
                                    <span v-if="invitation.acceptance.note" class="d-block text-break"
                                        >{{ tx('validation.note') }}: {{ invitation.acceptance.note }}</span
                                    >
                                </template>
                                <span v-else class="text-body-secondary">{{ tx('validation.no_acceptance') }}</span>
                            </dd>
                        </dl>
                    </div>
                </article>
            </div>

            <!-- Integridade -->
            <div
                v-show="tab === 'integrity'"
                id="version-panel-integrity"
                role="tabpanel"
                aria-labelledby="version-tab-integrity"
                tabindex="0"
            >
                <div
                    class="alert"
                    :class="version.integrity_ok ? 'alert-success' : 'alert-danger'"
                    role="status"
                    data-testid="integrity-status"
                >
                    <i
                        class="ti me-1"
                        :class="version.integrity_ok ? 'ti-shield-check' : 'ti-alert-octagon'"
                        aria-hidden="true"
                    ></i>
                    {{ version.integrity_ok ? tx('version.integrity.ok') : tx('version.integrity.broken') }}
                </div>

                <dl class="row">
                    <dt class="col-md-3">{{ tx('version.integrity.hash') }}</dt>
                    <dd class="col-md-9">
                        <code class="text-break" data-testid="full-hash">{{ version.content_hash }}</code>
                        <button type="button" class="btn btn-light btn-sm ms-2" @click="copyHash">
                            <i class="ti ti-copy" aria-hidden="true"></i>
                            <span class="visually-hidden">{{ copied ? '✓' : '' }}</span>
                        </button>
                    </dd>

                    <dt class="col-md-3">{{ tx('version.integrity.milestones') }}</dt>
                    <dd class="col-md-9">
                        <ul class="list-unstyled mb-0">
                            <li v-for="[name, when, who] in milestones" :key="name">
                                {{ tx(`version.integrity.${name}`) }}:
                                {{ when ? dateTime(when) : tx('version.integrity.none') }}
                                <span v-if="when && who" class="text-body-secondary">· {{ who }}</span>
                            </li>
                            <li v-if="version.review_due_at">
                                {{ tx('version.integrity.review_due') }}: {{ date(version.review_due_at) }}
                            </li>
                        </ul>
                    </dd>
                </dl>

                <h3 class="h6 fw-semibold">
                    {{ tx('version.integrity.canonical') }}
                    <small class="text-body-secondary fw-normal"
                        >({{ tx('version.integrity.bytes', { bytes: canonical.length }) }})</small
                    >
                </h3>
                <pre
                    class="border rounded p-2 bg-body-tertiary small text-break"
                    style="white-space: pre-wrap; max-height: 24rem; overflow: auto"
                    data-testid="canonical"
                    >{{ canonical }}</pre>

                <h3 class="h6 fw-semibold mt-4">{{ tx('show.history') }}</h3>
                <p v-if="!events.length" class="text-body-secondary">{{ tx('show.no_history') }}</p>
                <ul v-else class="list-unstyled small mb-0">
                    <li v-for="event in events" :key="event.id" class="border-bottom py-1">
                        <strong>{{ event.label }}</strong>
                        — {{ dateTime(event.created_at) }} · {{ event.actor }}
                        <span v-if="event.reason" class="text-break"> · {{ event.reason }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <CenteredModal :open="freezing.open" size="md" @close="freezing.open = false">
            <template #header>
                <h5 class="mb-0 fw-semibold">{{ tx('version.freeze_title') }}</h5>
            </template>
            <p class="mb-0">{{ tx('version.freeze_body') }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="freezing.busy" @click="freezing.open = false">
                    {{ tx('version.cancel') }}
                </button>
                <button
                    type="button"
                    class="btn btn-danger"
                    :disabled="freezing.busy"
                    data-testid="freeze-confirm"
                    @click="freeze"
                >
                    <span v-if="freezing.busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ tx('version.freeze_confirm') }}
                </button>
            </template>
        </CenteredModal>

        <!-- liberar -->
        <CenteredModal :open="governing.kind === 'release'" size="md" @close="closeGovern">
            <template #header>
                <h5 class="mb-0 fw-semibold">{{ tx('governance.release.title') }}</h5>
            </template>
            <p>{{ tx('governance.release.body', { version: version.version }) }}</p>
            <p class="small text-body-secondary mb-2">{{ tx('governance.release.evidence') }}</p>
            <p class="small mb-0" data-testid="release-note">
                {{
                    currentReleased
                        ? tx('governance.release.superseded', { previous: currentReleased.version })
                        : tx('governance.release.no_superseded')
                }}
            </p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="governing.busy" @click="closeGovern">
                    {{ tx('governance.cancel') }}
                </button>
                <button
                    type="button"
                    class="btn btn-success"
                    :disabled="governing.busy"
                    data-testid="release-confirm"
                    @click="govern()"
                >
                    <span v-if="governing.busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ tx('governance.release.confirm') }}
                </button>
            </template>
        </CenteredModal>

        <!-- retomar -->
        <CenteredModal :open="governing.kind === 'resume'" size="md" @close="closeGovern">
            <template #header>
                <h5 class="mb-0 fw-semibold">{{ tx('governance.resume.title') }}</h5>
            </template>
            <p class="mb-0">{{ tx('governance.resume.body') }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="governing.busy" @click="closeGovern">
                    {{ tx('governance.cancel') }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="governing.busy"
                    data-testid="resume-confirm"
                    @click="govern()"
                >
                    <span v-if="governing.busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ tx('governance.resume.confirm') }}
                </button>
            </template>
        </CenteredModal>

        <!-- suspender / aposentar / retirar: exigem o motivo (vai para o log de governança) -->
        <ConfirmationWithReasonModal
            v-for="kind in ['suspend', 'retire', 'withdraw']"
            :key="kind"
            :open="governing.kind === kind"
            :title="tx(`governance.${kind}.title`)"
            :message="tx(`governance.${kind}.body`)"
            :confirm-label="tx(`governance.${kind}.confirm`)"
            :min-length="GOVERNANCE_MIN[kind]"
            :saving="governing.busy"
            :error="reasonError"
            @close="closeGovern"
            @confirm="govern"
        />
    </AppLayout>
</template>
