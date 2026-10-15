<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import DoctorMissingAlert from '@/Components/Panel/DoctorMissingAlert.vue';
import RecallRuleCard from '@/Components/Recall/RecallRuleCard.vue';
import RecallContentDiff from '@/Components/Recall/RecallContentDiff.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Configurar retornos (Fase 6) — a clínica escolhe quais protocolos VALIDADOS usa e ajusta os intervalos dentro da
 * faixa que o Revisor validou. A regra aparece pelo mesmo componente do Manager e da página do Revisor
 * (RecallRuleCard): o que o Revisor aprovou é o que a clínica lê. Ativar e desativar é ato do médico (o servidor
 * confere); aqui a tela só esconde o que a pessoa não pode fazer.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    general: { type: Object, required: true },
    // O que está do lado do EasyEye para o convite por WhatsApp (pausa, chave do Manager, número que envia).
    invites: { type: Object, default: () => ({ available: false, blocker: null, label: null }) },
    protocols: { type: Array, default: () => [] },
    exams: { type: Object, required: true },
    canDecide: { type: Boolean, default: false },
    canConfigure: { type: Boolean, default: false },
    isDoctor: { type: Boolean, default: false },
    doctorMissing: { type: Boolean, default: false },
    doctorMissingText: { type: String, default: '' },
    ruleT: { type: Object, required: true },
    t: { type: Object, required: true },
});

const tx = makeTx(() => props.t);
const { date } = useLocaleFormat();

// ── Retorno pedido pelo médico ───────────────────────────────────────────────
const generalForm = useForm({ doctor_order_enabled: props.general.doctor_order_enabled });

function saveGeneral() {
    generalForm.put(route('panel.recall.settings.general'), { preserveScroll: true });
}

// ── Convite por WhatsApp ─────────────────────────────────────────────────────
// Segue no mesmo PUT da configuração geral; o retorno pedido pelo médico vai como está salvo (não leva junto uma
// edição ainda não salva do outro cartão).
const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

const inviteForm = useForm({
    whatsapp_invites_enabled: props.general.whatsapp_invites_enabled,
    invite_window_start: props.general.invite_window_start,
    invite_window_end: props.general.invite_window_end,
    invite_weekdays: [...props.general.invite_weekdays],
    min_days_between_invites: props.general.min_days_between_invites,
    max_auto_invites_per_cycle: props.general.max_auto_invites_per_cycle,
    daily_invite_cap: props.general.daily_invite_cap,
    auto_invite_max_overdue_days: props.general.auto_invite_max_overdue_days,
}).transform((data) => ({ ...data, doctor_order_enabled: props.general.doctor_order_enabled }));

function saveInvites() {
    inviteForm.put(route('panel.recall.settings.general'), { preserveScroll: true });
}

// Ligar só dá quando o EasyEye já liberou (pausa, chave do Manager, número); desligar sempre dá.
const inviteSwitchLocked = computed(
    () => !props.canConfigure || (!props.invites.available && !inviteForm.whatsapp_invites_enabled),
);

// ── Protocolos ───────────────────────────────────────────────────────────────
// Selos com contraste AA nos dois temas (resources/css/system/_recall.scss); o texto do selo diz o estado.
const STATUS_TONE = {
    available: 'recall-tone recall-tone--info',
    active: 'recall-tone recall-tone--success',
    update_available: 'recall-tone recall-tone--warning',
    paused: 'recall-tone recall-tone--muted',
    unavailable: 'recall-tone recall-tone--muted',
};

const forms = reactive({});
const showRule = reactive({});

function buildForm(protocol) {
    const tasks = {};

    for (const task of protocol.tasks) {
        tasks[task.key] = { interval: task.interval, enabled: task.enabled };
    }

    return useForm({
        version_id: protocol.released?.id ?? protocol.adoption?.version_id ?? '',
        tasks,
        auto_contact: protocol.adoption?.auto_contact ?? false,
        acknowledged: false,
    });
}

watch(
    () => props.protocols,
    (protocols) => {
        for (const protocol of protocols) forms[protocol.id] = buildForm(protocol);
    },
    { immediate: true },
);

const contentOf = (protocol) => protocol.adoption?.version?.content ?? protocol.released?.content ?? null;

/** Há uma versão liberada diferente da que a clínica adotou — vale também com a adoção parada (versão suspensa). */
const hasNewerVersion = (protocol) =>
    Boolean(protocol.adoption && protocol.released && protocol.adoption.version_id !== protocol.released.id);

/** A versão que o formulário ativa e o que ele faz: ativar (novo), ajustar (mesma versão) ou trocar (versão nova). */
function formMode(protocol) {
    if (!protocol.adoption) return 'activate';

    return hasNewerVersion(protocol) ? 'upgrade' : 'adjust';
}

// Adoção parada só tem formulário quando há versão liberada para trocar; sem ela, só dá para desativar.
const canSubmitForm = (protocol) =>
    (['available', 'active', 'update_available'].includes(protocol.status) ||
        (protocol.status === 'paused' && hasNewerVersion(protocol))) &&
    protocol.tasks.length > 0;

/** Erro de servidor que nenhum campo do formulário mostra (tarefa desconhecida, versão inválida…): vai num aviso. */
const FIELD_ERROR = /^(acknowledged|tasks\.[^.]+\.(interval|enabled))$/;

const strayErrors = (protocol) =>
    Object.entries(forms[protocol.id]?.errors ?? {})
        .filter(([field]) => !FIELD_ERROR.test(field))
        .map(([, message]) => message);

function unitText(unit) {
    return tx(`protocols.form.units.${unit}`);
}

function rangeText(task) {
    return tx('protocols.form.range', {
        min: task.min,
        max: task.max,
        unit: unitText(task.unit),
        default: task.default,
    });
}

function taskLabel(task) {
    const labels = props.ruleT.labels ?? {};

    return task.kind === 'exam'
        ? (labels.exams?.[task.exam_key] ?? task.exam_key)
        : (labels.kinds?.[task.kind] ?? task.kind);
}

function submitProtocol(protocol) {
    const form = forms[protocol.id];

    form.post(route('panel.recall.settings.protocols.activate', protocol.id), { preserveScroll: true });
}

function submitLabel(protocol) {
    const mode = formMode(protocol);

    return mode === 'upgrade'
        ? tx('protocols.form.submit_upgrade', { version: protocol.released.version })
        : tx(`protocols.form.submit_${mode}`);
}

function formTitle(protocol) {
    const mode = formMode(protocol);

    return mode === 'upgrade'
        ? tx('protocols.form.title_upgrade', { version: protocol.released.version })
        : tx(`protocols.form.title_${mode}`);
}

function validatedBy(evidence) {
    if (!evidence) return '';
    if (!evidence.reviewer) return tx('protocols.validated_by_anonymous');

    return tx('protocols.validated_by', {
        name: evidence.reviewer.name,
        crm: evidence.reviewer.crm,
        rqe: evidence.reviewer.rqe,
    });
}

// ── Desativar ────────────────────────────────────────────────────────────────
const deactivating = ref(null);
const deactivateForm = useForm({ reason: '' });

function openDeactivate(protocol) {
    deactivating.value = protocol;
    deactivateForm.reset();
    deactivateForm.clearErrors();
}

function submitDeactivate() {
    if (!deactivating.value || deactivateForm.processing) return;

    deactivateForm.post(route('panel.recall.settings.protocols.deactivate', deactivating.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deactivating.value = null;
        },
    });
}

// ── Exames ───────────────────────────────────────────────────────────────────
const mappingForm = useForm({ mappings: JSON.parse(JSON.stringify(props.exams.mappings)) });
const typeName = computed(() => Object.fromEntries(props.exams.types.map((type) => [type.id, type.name])));
// Tipo já associado que saiu do catálogo (desativado/excluído): continua na lista, marcado, para a clínica poder tirá-lo.
const retiredTypes = computed(
    () => new Set(props.exams.types.filter((type) => type.unavailable).map((type) => type.id)),
);
const picking = reactive(Object.fromEntries(props.exams.keys.map((exam) => [exam.key, ''])));

/** O que a lista de escolha oferece: só o que está no catálogo e ainda não está associado a este exame. */
const pickable = (key) =>
    props.exams.types.filter((type) => !type.unavailable && !mappingForm.mappings[key]?.includes(type.id));

function addExam(key, id) {
    if (!id || mappingForm.mappings[key]?.includes(id)) return;

    mappingForm.mappings[key] = [...(mappingForm.mappings[key] ?? []), id];
    picking[key] = '';
}

function removeExam(key, id) {
    mappingForm.mappings[key] = mappingForm.mappings[key].filter((value) => value !== id);
}

const pendingSuggestions = (key) =>
    (props.exams.suggestions[key] ?? []).filter((id) => !mappingForm.mappings[key]?.includes(id));

function saveMappings() {
    mappingForm.put(route('panel.recall.settings.exam-mappings'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t.title" />

    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3 recall-form" data-testid="recall-settings">
            <PageHeader :title="t.title" :subtitle="t.subtitle">
                <template #actions>
                    <Link :href="route('panel.recall.index')" class="btn btn-sm recall-btn">
                        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ t.back }}
                    </Link>
                </template>
            </PageHeader>

            <DoctorMissingAlert v-if="doctorMissing" :text="doctorMissingText" />

            <p
                v-if="!canDecide && !doctorMissing"
                class="recall-notice recall-notice--info small"
                role="note"
                data-testid="doctor-only"
            >
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.doctor_only }}
            </p>

            <!-- Retorno pedido pelo médico -->
            <section class="card mb-3" aria-labelledby="rs-general">
                <div class="card-body">
                    <h2 id="rs-general" class="h6">{{ t.general.title }}</h2>
                    <p class="small text-body-secondary">{{ t.general.text }}</p>
                    <form class="d-flex flex-wrap align-items-center gap-3" @submit.prevent="saveGeneral">
                        <div class="form-check form-switch mb-0">
                            <input
                                id="rs-doctor-order"
                                v-model="generalForm.doctor_order_enabled"
                                type="checkbox"
                                role="switch"
                                class="form-check-input"
                                :disabled="!canConfigure"
                                data-testid="doctor-order-switch"
                            />
                            <label class="form-check-label" for="rs-doctor-order">{{ t.general.enabled }}</label>
                        </div>
                        <button
                            v-if="canConfigure"
                            type="submit"
                            class="btn btn-primary btn-sm"
                            :disabled="generalForm.processing || !generalForm.isDirty"
                            data-testid="general-save"
                        >
                            {{ t.general.save }}
                        </button>
                    </form>
                </div>
            </section>

            <!-- Convite por WhatsApp -->
            <section class="card mb-3" aria-labelledby="rs-invites" data-testid="invites-card">
                <div class="card-body">
                    <h2 id="rs-invites" class="h6">{{ t.invites.title }}</h2>
                    <p class="small text-body-secondary">{{ t.invites.text }}</p>

                    <p
                        v-if="!invites.available"
                        class="recall-notice recall-notice--warning small"
                        role="note"
                        data-testid="invites-unavailable"
                    >
                        <i class="ti ti-info-circle me-1" aria-hidden="true"></i
                        >{{ tx('invites.unavailable', { reason: invites.label ?? '' }) }}
                    </p>

                    <form @submit.prevent="saveInvites">
                        <div class="form-check form-switch mb-3">
                            <input
                                id="rs-inv-enabled"
                                v-model="inviteForm.whatsapp_invites_enabled"
                                type="checkbox"
                                role="switch"
                                class="form-check-input"
                                :disabled="inviteSwitchLocked"
                                data-testid="invites-switch"
                            />
                            <label class="form-check-label" for="rs-inv-enabled">{{ t.invites.enabled }}</label>
                        </div>

                        <p class="small text-body-secondary">{{ t.invites.auto_note }}</p>

                        <div class="row g-3 mb-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label small mb-1" for="rs-inv-start">{{
                                    t.invites.window_start
                                }}</label>
                                <input
                                    id="rs-inv-start"
                                    v-model="inviteForm.invite_window_start"
                                    type="time"
                                    class="form-control"
                                    :class="{ 'is-invalid': inviteForm.errors.invite_window_start }"
                                    :disabled="!canConfigure"
                                />
                                <div v-if="inviteForm.errors.invite_window_start" class="invalid-feedback d-block">
                                    {{ inviteForm.errors.invite_window_start }}
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small mb-1" for="rs-inv-end">{{ t.invites.window_end }}</label>
                                <input
                                    id="rs-inv-end"
                                    v-model="inviteForm.invite_window_end"
                                    type="time"
                                    class="form-control"
                                    :class="{ 'is-invalid': inviteForm.errors.invite_window_end }"
                                    :disabled="!canConfigure"
                                />
                                <div v-if="inviteForm.errors.invite_window_end" class="invalid-feedback d-block">
                                    {{ inviteForm.errors.invite_window_end }}
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <fieldset>
                                    <legend class="form-label small mb-1">{{ t.invites.weekdays }}</legend>
                                    <div class="d-flex flex-wrap gap-3">
                                        <div v-for="weekday in WEEKDAYS" :key="weekday" class="form-check">
                                            <input
                                                :id="`rs-inv-wd-${weekday}`"
                                                v-model="inviteForm.invite_weekdays"
                                                type="checkbox"
                                                class="form-check-input"
                                                :value="weekday"
                                                :disabled="!canConfigure"
                                            />
                                            <label class="form-check-label" :for="`rs-inv-wd-${weekday}`">{{
                                                t.invites.weekday[weekday]
                                            }}</label>
                                        </div>
                                    </div>
                                </fieldset>
                                <div v-if="inviteForm.errors.invite_weekdays" class="text-danger small" role="alert">
                                    {{ inviteForm.errors.invite_weekdays }}
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label small mb-1" for="rs-inv-min">{{ t.invites.min_days }}</label>
                                <input
                                    id="rs-inv-min"
                                    v-model.number="inviteForm.min_days_between_invites"
                                    type="number"
                                    class="form-control"
                                    :min="general.min_days_floor"
                                    max="365"
                                    :class="{ 'is-invalid': inviteForm.errors.min_days_between_invites }"
                                    aria-describedby="rs-inv-min-hint"
                                    :disabled="!canConfigure"
                                />
                                <div id="rs-inv-min-hint" class="form-text">
                                    {{ tx('invites.min_days_hint', { min: general.min_days_floor }) }}
                                </div>
                                <div v-if="inviteForm.errors.min_days_between_invites" class="invalid-feedback d-block">
                                    {{ inviteForm.errors.min_days_between_invites }}
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small mb-1" for="rs-inv-cycle">{{
                                    t.invites.max_per_cycle
                                }}</label>
                                <input
                                    id="rs-inv-cycle"
                                    v-model.number="inviteForm.max_auto_invites_per_cycle"
                                    type="number"
                                    class="form-control"
                                    min="1"
                                    max="10"
                                    :class="{ 'is-invalid': inviteForm.errors.max_auto_invites_per_cycle }"
                                    aria-describedby="rs-inv-cycle-hint"
                                    :disabled="!canConfigure"
                                />
                                <div id="rs-inv-cycle-hint" class="form-text">{{ t.invites.max_per_cycle_hint }}</div>
                                <div
                                    v-if="inviteForm.errors.max_auto_invites_per_cycle"
                                    class="invalid-feedback d-block"
                                >
                                    {{ inviteForm.errors.max_auto_invites_per_cycle }}
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small mb-1" for="rs-inv-cap">{{ t.invites.daily_cap }}</label>
                                <input
                                    id="rs-inv-cap"
                                    v-model.number="inviteForm.daily_invite_cap"
                                    type="number"
                                    class="form-control"
                                    min="1"
                                    max="500"
                                    :class="{ 'is-invalid': inviteForm.errors.daily_invite_cap }"
                                    aria-describedby="rs-inv-cap-hint"
                                    :disabled="!canConfigure"
                                />
                                <div id="rs-inv-cap-hint" class="form-text" data-testid="invites-cap-hint">
                                    {{
                                        tx('invites.daily_cap_hint', {
                                            today:
                                                general.cap_today === null
                                                    ? t.invites.daily_cap_open
                                                    : general.cap_today,
                                        })
                                    }}
                                    {{ tx('invites.sent_today', { count: general.sent_today }) }}
                                </div>
                                <div v-if="inviteForm.errors.daily_invite_cap" class="invalid-feedback d-block">
                                    {{ inviteForm.errors.daily_invite_cap }}
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small mb-1" for="rs-inv-overdue">{{
                                    t.invites.max_overdue
                                }}</label>
                                <input
                                    id="rs-inv-overdue"
                                    v-model.number="inviteForm.auto_invite_max_overdue_days"
                                    type="number"
                                    class="form-control"
                                    min="30"
                                    max="730"
                                    :class="{ 'is-invalid': inviteForm.errors.auto_invite_max_overdue_days }"
                                    aria-describedby="rs-inv-overdue-hint"
                                    :disabled="!canConfigure"
                                />
                                <div id="rs-inv-overdue-hint" class="form-text">{{ t.invites.max_overdue_hint }}</div>
                                <div
                                    v-if="inviteForm.errors.auto_invite_max_overdue_days"
                                    class="invalid-feedback d-block"
                                >
                                    {{ inviteForm.errors.auto_invite_max_overdue_days }}
                                </div>
                            </div>
                        </div>

                        <button
                            v-if="canConfigure"
                            type="submit"
                            class="btn btn-primary btn-sm"
                            :disabled="inviteForm.processing || !inviteForm.isDirty"
                            data-testid="invites-save"
                        >
                            {{ t.invites.save }}
                        </button>
                    </form>
                </div>
            </section>

            <!-- Protocolos -->
            <section class="mb-3" aria-labelledby="rs-protocols">
                <h2 id="rs-protocols" class="h5">{{ t.protocols.title }}</h2>
                <p class="small text-body-secondary">{{ t.protocols.intro }}</p>

                <p
                    v-if="!protocols.length"
                    class="card card-body text-center text-body-secondary"
                    data-testid="no-protocols"
                >
                    {{ t.protocols.empty }}
                </p>

                <article
                    v-for="protocol in protocols"
                    :key="protocol.id"
                    class="card mb-3"
                    :data-testid="`protocol-${protocol.key}`"
                    :data-status="protocol.status"
                >
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                            <div class="me-auto">
                                <h3 class="h6 mb-1">{{ protocol.title }}</h3>
                                <p class="small text-body-secondary mb-0">{{ protocol.summary }}</p>
                            </div>
                            <span :class="STATUS_TONE[protocol.status]" data-testid="protocol-status">{{
                                t.protocols.status[protocol.status]
                            }}</span>
                        </div>

                        <!-- A prova da validação -->
                        <ul
                            v-if="(protocol.adoption?.version ?? protocol.released)?.evidence"
                            class="small list-unstyled mb-2"
                            data-testid="protocol-evidence"
                        >
                            <template
                                v-for="shown in [protocol.adoption?.version ?? protocol.released]"
                                :key="shown.id"
                            >
                                <li>
                                    <i class="ti ti-shield-check me-1 text-success" aria-hidden="true"></i
                                    >{{ tx('protocols.version', { version: shown.version }) }} ·
                                    {{ validatedBy(shown.evidence) }}
                                </li>
                                <li v-if="shown.evidence.validated_at">
                                    {{ tx('protocols.validated_on', { date: date(shown.evidence.validated_at) }) }}
                                </li>
                                <li v-if="shown.evidence.accepted_at">
                                    {{ tx('protocols.accepted_on', { date: date(shown.evidence.accepted_at) }) }}
                                </li>
                                <li class="font-monospace">
                                    {{ tx('protocols.hash', { hash: shown.evidence.hash_short }) }}
                                </li>
                                <li v-if="shown.evidence.review_due_at && !shown.evidence.review_overdue">
                                    {{ tx('protocols.review_due', { date: date(shown.evidence.review_due_at) }) }}
                                </li>
                                <li
                                    v-if="shown.evidence.review_overdue"
                                    class="recall-notice recall-notice--warning mt-2 mb-0"
                                    data-testid="review-overdue"
                                >
                                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                    >{{ tx('protocols.review_overdue', { date: date(shown.evidence.review_due_at) }) }}
                                </li>
                            </template>
                        </ul>

                        <p v-if="protocol.status === 'unavailable'" class="small text-body-secondary mb-2">
                            {{ t.protocols.unavailable }}
                        </p>

                        <p v-if="protocol.adoption" class="small mb-2" data-testid="active-since">
                            {{
                                protocol.adoption.activated_by
                                    ? tx('protocols.active_since', {
                                          date: date(protocol.adoption.activated_at),
                                          name: protocol.adoption.activated_by,
                                      })
                                    : tx('protocols.active_since_unknown', {
                                          date: date(protocol.adoption.activated_at),
                                      })
                            }}
                        </p>

                        <p
                            v-if="protocol.status === 'paused'"
                            class="recall-notice recall-notice--warning small"
                            role="alert"
                            data-testid="paused-notice"
                        >
                            <i class="ti ti-player-pause me-1" aria-hidden="true"></i
                            >{{ t.protocols.paused[protocol.adoption.pause_reason] ?? t.protocols.paused.suspended }}
                        </p>

                        <!-- Versão nova: o que mudou -->
                        <div
                            v-if="hasNewerVersion(protocol)"
                            class="recall-notice recall-notice--info"
                            data-testid="update-notice"
                        >
                            <p class="fw-semibold mb-1">{{ t.protocols.update.title }}</p>
                            <p class="small mb-2">
                                {{
                                    tx(
                                        protocol.status === 'paused'
                                            ? 'protocols.update.text_paused'
                                            : 'protocols.update.text',
                                        {
                                            version: protocol.released.version,
                                            current: protocol.adoption.version.version,
                                        },
                                    )
                                }}
                            </p>
                            <RecallContentDiff :changes="protocol.diff" :has-parent="true" :t="ruleT" />
                        </div>

                        <!-- A regra -->
                        <button
                            type="button"
                            class="btn btn-link btn-sm recall-link"
                            :aria-expanded="showRule[protocol.id] ? 'true' : 'false'"
                            :aria-controls="`rule-${protocol.id}`"
                            data-testid="toggle-rule"
                            @click="showRule[protocol.id] = !showRule[protocol.id]"
                        >
                            <i
                                class="ti me-1"
                                :class="showRule[protocol.id] ? 'ti-chevron-up' : 'ti-chevron-down'"
                                aria-hidden="true"
                            ></i
                            >{{ showRule[protocol.id] ? t.protocols.hide_rule : t.protocols.show_rule }}
                        </button>
                        <div
                            v-if="showRule[protocol.id] && contentOf(protocol)"
                            :id="`rule-${protocol.id}`"
                            class="mt-2"
                        >
                            <RecallRuleCard :content="contentOf(protocol)" :t="ruleT" />
                        </div>

                        <!-- Parâmetros e ativação (médico) -->
                        <form
                            v-if="canDecide && canSubmitForm(protocol) && forms[protocol.id]"
                            class="border-top pt-3 mt-3"
                            novalidate
                            :aria-labelledby="`form-title-${protocol.id}`"
                            :data-testid="`form-${protocol.key}`"
                            @submit.prevent="submitProtocol(protocol)"
                        >
                            <h4 :id="`form-title-${protocol.id}`" class="h6">{{ formTitle(protocol) }}</h4>

                            <div
                                v-if="strayErrors(protocol).length"
                                class="alert alert-danger small"
                                role="alert"
                                data-testid="form-errors"
                            >
                                <p v-for="message in strayErrors(protocol)" :key="message" class="mb-0">
                                    {{ message }}
                                </p>
                            </div>

                            <ul class="list-unstyled mb-3" :aria-label="t.protocols.form.tasks">
                                <li
                                    v-for="task in protocol.tasks"
                                    :key="task.key"
                                    class="border-bottom py-2"
                                    :data-testid="`task-${task.key}`"
                                >
                                    <div class="fw-medium">
                                        {{ taskLabel(task) }}
                                        <span class="small fw-normal text-body-secondary ms-1">{{
                                            task.required ? t.protocols.form.required : t.protocols.form.optional
                                        }}</span>
                                    </div>

                                    <div class="d-flex flex-wrap align-items-center gap-3 mt-1">
                                        <div class="form-check form-switch mb-0">
                                            <input
                                                :id="`en-${protocol.id}-${task.key}`"
                                                v-model="forms[protocol.id].tasks[task.key].enabled"
                                                type="checkbox"
                                                role="switch"
                                                class="form-check-input"
                                                :disabled="task.required"
                                            />
                                            <label
                                                class="form-check-label small"
                                                :for="`en-${protocol.id}-${task.key}`"
                                                >{{ t.protocols.form.enabled }}</label
                                            >
                                        </div>

                                        <div class="d-flex align-items-center gap-2">
                                            <label class="small" :for="`iv-${protocol.id}-${task.key}`">{{
                                                t.protocols.form.interval
                                            }}</label>
                                            <input
                                                :id="`iv-${protocol.id}-${task.key}`"
                                                v-model.number="forms[protocol.id].tasks[task.key].interval"
                                                type="number"
                                                class="form-control form-control-sm"
                                                :class="{
                                                    'is-invalid':
                                                        forms[protocol.id].errors[`tasks.${task.key}.interval`],
                                                }"
                                                style="width: 5.5rem"
                                                :min="task.min"
                                                :max="task.max"
                                                step="1"
                                                :aria-describedby="`ivh-${protocol.id}-${task.key}`"
                                            />
                                            <span class="small">{{ unitText(task.unit) }}</span>
                                        </div>
                                    </div>

                                    <div :id="`ivh-${protocol.id}-${task.key}`" class="form-text">
                                        {{ rangeText(task) }}
                                    </div>
                                    <div
                                        v-if="forms[protocol.id].errors[`tasks.${task.key}.interval`]"
                                        class="invalid-feedback d-block"
                                        role="alert"
                                    >
                                        {{ forms[protocol.id].errors[`tasks.${task.key}.interval`] }}
                                    </div>
                                    <div
                                        v-if="forms[protocol.id].errors[`tasks.${task.key}.enabled`]"
                                        class="invalid-feedback d-block"
                                        role="alert"
                                    >
                                        {{ forms[protocol.id].errors[`tasks.${task.key}.enabled`] }}
                                    </div>
                                </li>
                            </ul>

                            <div class="form-check mb-3">
                                <input
                                    :id="`ack-${protocol.id}`"
                                    v-model="forms[protocol.id].acknowledged"
                                    type="checkbox"
                                    class="form-check-input"
                                    :class="{ 'is-invalid': forms[protocol.id].errors.acknowledged }"
                                    data-testid="acknowledge"
                                />
                                <label class="form-check-label" :for="`ack-${protocol.id}`">{{
                                    t.protocols.form.acknowledge
                                }}</label>
                                <div
                                    v-if="forms[protocol.id].errors.acknowledged"
                                    class="invalid-feedback d-block"
                                    role="alert"
                                >
                                    {{ forms[protocol.id].errors.acknowledged }}
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button
                                    type="submit"
                                    class="btn btn-primary btn-sm"
                                    :disabled="forms[protocol.id].processing || !forms[protocol.id].acknowledged"
                                    data-testid="submit-protocol"
                                >
                                    {{ submitLabel(protocol) }}
                                </button>
                                <button
                                    v-if="protocol.adoption"
                                    type="button"
                                    class="btn btn-sm recall-btn recall-btn--danger"
                                    data-testid="open-deactivate"
                                    @click="openDeactivate(protocol)"
                                >
                                    {{ t.protocols.deactivate.button }}
                                </button>
                            </div>
                        </form>

                        <div v-else-if="canDecide && protocol.adoption" class="border-top pt-3 mt-3">
                            <button
                                type="button"
                                class="btn btn-sm recall-btn recall-btn--danger"
                                data-testid="open-deactivate"
                                @click="openDeactivate(protocol)"
                            >
                                {{ t.protocols.deactivate.button }}
                            </button>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Exames -->
            <section class="card mb-3" aria-labelledby="rs-exams" data-testid="exam-mappings">
                <div class="card-body">
                    <h2 id="rs-exams" class="h6">{{ t.exams.title }}</h2>
                    <p class="small text-body-secondary">{{ t.exams.text }}</p>

                    <form novalidate @submit.prevent="saveMappings">
                        <div v-for="exam in exams.keys" :key="exam.key" class="mb-3" :data-testid="`exam-${exam.key}`">
                            <h3 class="h6 mb-1">{{ exam.label }}</h3>

                            <ul class="list-inline mb-1">
                                <li
                                    v-if="!(mappingForm.mappings[exam.key] ?? []).length"
                                    class="list-inline-item small text-body-secondary"
                                >
                                    {{ t.exams.none }}
                                </li>
                                <li
                                    v-for="id in mappingForm.mappings[exam.key] ?? []"
                                    :key="id"
                                    class="list-inline-item"
                                >
                                    <span class="recall-tone recall-tone--muted fw-normal">
                                        {{ typeName[id] ?? id }}
                                        <span v-if="retiredTypes.has(id)" class="fst-italic"
                                            >({{ t.exams.unavailable }})</span
                                        >
                                        <button
                                            v-if="canConfigure"
                                            type="button"
                                            class="btn-close ms-1"
                                            style="font-size: 0.55rem"
                                            :aria-label="tx('exams.remove', { name: typeName[id] ?? id })"
                                            @click="removeExam(exam.key, id)"
                                        ></button>
                                    </span>
                                </li>
                            </ul>

                            <div v-if="canConfigure" class="d-flex flex-wrap align-items-center gap-2">
                                <template v-for="id in pendingSuggestions(exam.key)" :key="id">
                                    <button
                                        type="button"
                                        class="btn btn-sm recall-btn"
                                        data-testid="suggestion"
                                        @click="addExam(exam.key, id)"
                                    >
                                        <i class="ti ti-plus me-1" aria-hidden="true"></i
                                        >{{ tx('exams.suggested', { name: typeName[id] ?? id }) }}
                                    </button>
                                </template>
                                <label class="visually-hidden" :for="`pick-${exam.key}`">{{
                                    tx('exams.add_label', { exam: exam.label })
                                }}</label>
                                <select
                                    :id="`pick-${exam.key}`"
                                    v-model="picking[exam.key]"
                                    class="form-select form-select-sm w-auto"
                                    @change="addExam(exam.key, picking[exam.key])"
                                >
                                    <option value="">{{ t.exams.select }}</option>
                                    <option v-for="type in pickable(exam.key)" :key="type.id" :value="type.id">
                                        {{ type.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <button
                            v-if="canConfigure"
                            type="submit"
                            class="btn btn-primary btn-sm"
                            :disabled="mappingForm.processing || !mappingForm.isDirty"
                            data-testid="mappings-save"
                        >
                            {{ t.exams.save }}
                        </button>
                    </form>
                </div>
            </section>
        </div>

        <!-- Desativar -->
        <CenteredModal :open="deactivating !== null" size="md" @close="deactivating = null">
            <template #header>
                <h5 class="mb-0">{{ t.protocols.deactivate.title }}</h5>
            </template>

            <form
                v-if="deactivating"
                id="deactivate-form"
                class="recall-form"
                novalidate
                @submit.prevent="submitDeactivate"
            >
                <p class="fw-semibold">{{ deactivating.title }}</p>
                <p class="small text-body-secondary">{{ t.protocols.deactivate.text }}</p>
                <label class="form-label small mb-1" for="deactivate-reason"
                    >{{ t.protocols.deactivate.reason }} *</label
                >
                <textarea
                    id="deactivate-reason"
                    v-model="deactivateForm.reason"
                    class="form-control"
                    :class="{ 'is-invalid': deactivateForm.errors.reason }"
                    rows="3"
                    maxlength="500"
                    aria-describedby="deactivate-hint"
                    required
                ></textarea>
                <div v-if="deactivateForm.errors.reason" class="invalid-feedback d-block" role="alert">
                    {{ deactivateForm.errors.reason }}
                </div>
                <div id="deactivate-hint" class="form-text">
                    {{ tx('protocols.deactivate.reason_hint', { min: 10 }) }}
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-light"
                    :disabled="deactivateForm.processing"
                    @click="deactivating = null"
                >
                    {{ t.protocols.deactivate.cancel }}
                </button>
                <button
                    type="submit"
                    form="deactivate-form"
                    class="btn btn-danger"
                    :disabled="deactivateForm.processing"
                    data-testid="deactivate-submit"
                >
                    {{ t.protocols.deactivate.submit }}
                </button>
            </template>
        </CenteredModal>
    </AppLayout>
</template>
