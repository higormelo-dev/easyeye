<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { GLOSA_ICONS, deadlineBadge, glosaRef, nextAction, softBadge } from './glosaHelpers.js';

/**
 * Painel de detalhes da glosa (carregado por recarga parcial — prop
 * `glosaDetail` do TissGlosasController): resumo, guia (paciente só pelo
 * nome), motivo completo, recursos (nº REC, status, valores, justificativa) e
 * linha do tempo do histórico TISS. O rodapé traz a próxima ação.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    detail: { type: Object, default: null },
    today: { type: String, default: '' },
    dueSoonDays: { type: Number, default: 5 },
    busy: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'action']);

const { tx } = useTrans(() => props.t);
const { money, date, dateTime } = useLocaleFormat();

const closeRef = ref(null);

const missing = computed(() => Boolean(props.detail?.missing));
const glosa = computed(() => (props.detail && !missing.value ? props.detail : null));
const next = computed(() => (glosa.value ? nextAction(glosa.value) : { kind: 'details', appeal: null }));
const deadline = computed(() => (glosa.value ? deadlineBadge(glosa.value, props.today, props.dueSoonDays, tx) : null));

const ACTIONS = {
    appeal: { label: 'appeal_btn', icon: 'ti-message-circle-up', cls: 'btn-warning' },
    submit: { label: 'submit_appeal_btn', icon: 'ti-send', cls: 'btn-info' },
    resolve: { label: 'resolve_appeal_btn', icon: 'ti-gavel', cls: 'btn-primary' },
};

const primaryAction = computed(() => ACTIONS[next.value.kind] ?? null);

/**
 * Linha do tempo: histórico TISS em ordem; se não houver o registro de
 * abertura (glosa lançada no Faturamento não grava histórico), começa pela
 * data de identificação.
 */
const timeline = computed(() => {
    const events = (glosa.value?.timeline ?? []).map((event) => ({
        id: event.id,
        at: event.changed_at,
        label: timelineLabel(event),
        reason: event.reason,
        icon: event.context === 'appeal' ? 'ti-message-circle-up' : 'ti-gavel',
    }));

    const hasOpening = (glosa.value?.timeline ?? []).some((e) => e.context === 'glosa' && e.current_status === 'open');

    if (glosa.value?.identified_at && !hasOpening) {
        events.unshift({
            id: 'identified',
            at: glosa.value.identified_at,
            label: props.t.timeline_identified,
            reason: '',
            icon: 'ti-flag',
            dateOnly: true,
        });
    }

    return events;
});

function timelineLabel(event) {
    if (event.context === 'appeal') {
        return event.previous_label
            ? tx('timeline_appeal_change', {
                  number: event.appeal_number ?? '—',
                  from: event.previous_label,
                  to: event.current_label,
              })
            : tx('timeline_appeal', { number: event.appeal_number ?? '—', status: event.current_label });
    }

    return event.previous_label
        ? tx('timeline_glosa_change', { from: event.previous_label, to: event.current_label })
        : tx('timeline_glosa', { status: event.current_label });
}

watch(
    () => props.open,
    (open) => {
        if (open) nextTick(() => closeRef.value?.focus?.());
    },
);

function runPrimary() {
    if (!glosa.value || !primaryAction.value) return;

    emit('action', next.value.kind, glosa.value, next.value.appeal);
}
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="640"
        :loading="loading && !detail"
        :loading-label="t.detail_loading"
        @close="emit('close')"
    >
        <template #header>
            <h2 class="h5 mb-0 fw-semibold" data-test="detail-title">
                <i class="ti ti-gavel me-2 text-primary" aria-hidden="true"></i
                >{{ tx('detail_title', { code: glosa ? glosaRef(glosa) : '—' }) }}
            </h2>
        </template>

        <div class="glosa-detail" :aria-busy="loading ? 'true' : 'false'" data-test="glosa-detail">
            <div v-if="missing" class="alert alert-warning mb-0" role="alert" data-test="detail-missing">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.detail_missing }}
            </div>

            <template v-else-if="glosa">
                <!-- Resumo -->
                <section class="mb-4" aria-labelledby="glosa-detail-summary">
                    <h3 id="glosa-detail-summary" class="h6 fw-semibold mb-2">{{ t.detail_summary }}</h3>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted fw-normal">{{ t.detail_status }}</dt>
                        <dd class="col-7 mb-1">
                            <span class="badge" :class="softBadge(glosa.status_color)">
                                <i
                                    class="ti me-1"
                                    :class="GLOSA_ICONS[glosa.status] ?? 'ti-point'"
                                    aria-hidden="true"
                                ></i
                                >{{ glosa.status_label }}
                            </span>
                        </dd>
                        <dt class="col-5 text-muted fw-normal">{{ t.modal_covenant_label }}</dt>
                        <dd class="col-7 mb-1">{{ glosa.operator_name || t.no_covenant }}</dd>
                        <dt class="col-5 text-muted fw-normal">{{ t.detail_amount }}</dt>
                        <dd class="col-7 mb-1 fw-semibold">{{ money(glosa.amount) }}</dd>
                        <template v-if="Number(glosa.recovered_amount) > 0">
                            <dt class="col-5 text-muted fw-normal">{{ t.detail_recovered }}</dt>
                            <dd class="col-7 mb-1 text-success-emphasis">{{ money(glosa.recovered_amount) }}</dd>
                        </template>
                        <dt class="col-5 text-muted fw-normal">{{ t.detail_identified }}</dt>
                        <dd class="col-7 mb-1">{{ date(glosa.identified_at) }}</dd>
                        <dt class="col-5 text-muted fw-normal">{{ t.detail_deadline }}</dt>
                        <dd class="col-7 mb-1" data-test="detail-deadline">
                            <span v-if="deadline" class="badge me-1" :class="deadline.cls">
                                <i class="ti me-1" :class="deadline.icon" aria-hidden="true"></i>{{ deadline.text }}
                            </span>
                            {{ glosa.deadline ? date(glosa.deadline) : '—' }}
                        </dd>
                        <template v-if="glosa.resolved_at">
                            <dt class="col-5 text-muted fw-normal">{{ t.detail_resolved_at }}</dt>
                            <dd class="col-7 mb-1">{{ date(glosa.resolved_at) }}</dd>
                        </template>
                    </dl>
                </section>

                <!-- Guia -->
                <section v-if="glosa.guide" class="mb-4" aria-labelledby="glosa-detail-guide" data-test="detail-guide">
                    <h3 id="glosa-detail-guide" class="h6 fw-semibold mb-2">{{ t.detail_guide }}</h3>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted fw-normal">{{ t.guide_provider_number }}</dt>
                        <dd class="col-7 mb-1">
                            <code>{{ glosa.guide.provider_number || '—' }}</code>
                        </dd>
                        <template v-if="glosa.guide.operator_number">
                            <dt class="col-5 text-muted fw-normal">{{ t.guide_operator_number }}</dt>
                            <dd class="col-7 mb-1">
                                <code>{{ glosa.guide.operator_number }}</code>
                            </dd>
                        </template>
                        <template v-if="glosa.guide.claim_code">
                            <dt class="col-5 text-muted fw-normal">{{ t.guide_claim_code }}</dt>
                            <dd class="col-7 mb-1">{{ glosa.guide.claim_code }}</dd>
                        </template>
                        <dt class="col-5 text-muted fw-normal">{{ t.guide_attendance }}</dt>
                        <dd class="col-7 mb-1">{{ date(glosa.guide.attendance_date) }}</dd>
                        <dt class="col-5 text-muted fw-normal">{{ t.guide_patient }}</dt>
                        <dd class="col-7 mb-1">{{ glosa.guide.patient_name || '—' }}</dd>
                        <dt class="col-5 text-muted fw-normal">{{ t.guide_total }}</dt>
                        <dd class="col-7 mb-1">{{ money(glosa.guide.total_amount) }}</dd>
                    </dl>
                </section>

                <!-- Motivo completo -->
                <section class="mb-4" aria-labelledby="glosa-detail-reason" data-test="detail-reason">
                    <h3 id="glosa-detail-reason" class="h6 fw-semibold mb-2">{{ t.detail_reason }}</h3>
                    <p class="small mb-1">
                        <span class="badge badge-soft-secondary me-1">{{ glosa.reason_code }}</span
                        >{{ glosa.reason_text || '—' }}
                    </p>
                    <p v-if="glosa.resolution_notes" class="small text-muted mb-0">
                        <span class="fw-medium">{{ t.detail_resolution_notes }}:</span> {{ glosa.resolution_notes }}
                    </p>
                </section>

                <!-- Recursos -->
                <section class="mb-4" aria-labelledby="glosa-detail-appeals" data-test="detail-appeals">
                    <h3 id="glosa-detail-appeals" class="h6 fw-semibold mb-2">{{ t.detail_appeals }}</h3>
                    <p v-if="!glosa.appeals?.length" class="small text-muted mb-0">{{ t.no_appeals }}</p>
                    <ul v-else class="list-unstyled mb-0">
                        <li
                            v-for="appeal in glosa.appeals"
                            :key="appeal.id"
                            class="border rounded p-2 mb-2 small"
                            data-test="detail-appeal"
                        >
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <code>{{ appeal.appeal_number }}</code>
                                <span class="badge" :class="softBadge(appeal.status_color)">{{
                                    appeal.status_label
                                }}</span>
                            </div>
                            <dl class="row mb-0">
                                <template v-if="appeal.created_at">
                                    <dt class="col-5 text-muted fw-normal">{{ t.appeal_opened_at }}</dt>
                                    <dd class="col-7 mb-1">{{ dateTime(appeal.created_at) }}</dd>
                                </template>
                                <template v-if="appeal.submitted_at">
                                    <dt class="col-5 text-muted fw-normal">{{ t.appeal_submitted_at }}</dt>
                                    <dd class="col-7 mb-1">{{ dateTime(appeal.submitted_at) }}</dd>
                                </template>
                                <template v-if="appeal.deadline">
                                    <dt class="col-5 text-muted fw-normal">{{ t.appeal_response_deadline }}</dt>
                                    <dd class="col-7 mb-1">{{ date(appeal.deadline) }}</dd>
                                </template>
                                <dt class="col-5 text-muted fw-normal">{{ t.appeal_requested }}</dt>
                                <dd class="col-7 mb-1">{{ money(appeal.requested_amount) }}</dd>
                                <template v-if="Number(appeal.accepted_amount) > 0">
                                    <dt class="col-5 text-muted fw-normal">{{ t.appeal_accepted }}</dt>
                                    <dd class="col-7 mb-1">{{ money(appeal.accepted_amount) }}</dd>
                                </template>
                                <template v-if="appeal.reason">
                                    <dt class="col-5 text-muted fw-normal">{{ t.appeal_reason }}</dt>
                                    <dd class="col-7 mb-1 glosa-detail__text">{{ appeal.reason }}</dd>
                                </template>
                                <template v-if="appeal.result_notes">
                                    <dt class="col-5 text-muted fw-normal">{{ t.appeal_result_notes }}</dt>
                                    <dd class="col-7 mb-1 glosa-detail__text">{{ appeal.result_notes }}</dd>
                                </template>
                            </dl>
                        </li>
                    </ul>
                </section>

                <!-- Linha do tempo -->
                <section aria-labelledby="glosa-detail-timeline" data-test="detail-timeline">
                    <h3 id="glosa-detail-timeline" class="h6 fw-semibold mb-2">{{ t.detail_timeline }}</h3>
                    <ol class="glosa-timeline list-unstyled mb-0">
                        <li
                            v-for="event in timeline"
                            :key="event.id"
                            class="glosa-timeline__item small"
                            data-test="timeline-item"
                        >
                            <i class="ti glosa-timeline__icon" :class="event.icon" aria-hidden="true"></i>
                            <div>
                                <div class="fw-medium">{{ event.label }}</div>
                                <time class="text-muted d-block" :datetime="event.at">{{
                                    event.dateOnly ? date(event.at) : dateTime(event.at)
                                }}</time>
                                <div v-if="event.reason" class="text-muted glosa-detail__text">{{ event.reason }}</div>
                            </div>
                        </li>
                    </ol>
                </section>
            </template>
        </div>

        <template #footer>
            <button
                ref="closeRef"
                type="button"
                class="btn btn-light btn-sm"
                data-test="detail-close"
                @click="emit('close')"
            >
                {{ t.close }}
            </button>
            <button
                v-if="glosa && primaryAction"
                type="button"
                class="btn btn-sm"
                :class="primaryAction.cls"
                :disabled="busy"
                data-test="detail-action"
                @click="runPrimary"
            >
                <i class="ti me-1" :class="primaryAction.icon" aria-hidden="true"></i>{{ t[primaryAction.label] }}
            </button>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.glosa-detail__text {
    white-space: pre-line;
    word-break: break-word;
}

.glosa-timeline__item {
    position: relative;
    display: flex;
    gap: 0.75rem;
    padding-bottom: 0.75rem;
}

.glosa-timeline__item:not(:last-child)::before {
    content: '';
    position: absolute;
    top: 1.5rem;
    bottom: 0;
    left: 0.6875rem;
    border-left: 1px solid var(--bs-border-color);
}

.glosa-timeline__icon {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 1.375rem;
    height: 1.375rem;
    border-radius: 50%;
    color: var(--bs-primary-text-emphasis);
    background-color: var(--bs-primary-bg-subtle);
}
</style>
