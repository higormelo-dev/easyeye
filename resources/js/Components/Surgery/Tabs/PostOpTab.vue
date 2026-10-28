<script setup>
import { computed, ref } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import EvaluationFormModal from '@/Components/Surgery/PostOp/EvaluationFormModal.vue';
import AddendumModal from '@/Components/Surgery/PostOp/AddendumModal.vue';
import GuidanceModal from '@/Components/Surgery/PostOp/GuidanceModal.vue';
import { firstError, postOpRequest } from '@/Components/Surgery/PostOp/usePostOpApi.js';

/**
 * Aba "Pós-operatório": retornos D1/D7/D30 da Agenda, avaliações estruturadas
 * (rascunho → finalizada → adendo), alertas de complicação grave e emissão de
 * colírios/orientações. O servidor decide o que cada perfil pode (`section.can`).
 */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload']);

const { date, dateTime } = useLocaleFormat();
const tp = computed(() => props.t.postop ?? {});
const ui = computed(() => tp.value.ui ?? {});
const sectionT = computed(() => ({ ...tp.value }));

const evaluationOpen = ref(false);
const editing = ref(null);
const addendumFor = ref(null);
const guidanceOpen = ref(false);
const busy = ref(false);
const message = ref('');
const failure = ref('');

const sectionCan = computed(() => props.section.can ?? {});

function tx(key, params = {}) {
    return Object.entries(params).reduce(
        (text, [name, value]) => text.replace(`:${name}`, String(value)),
        ui.value[key] ?? key,
    );
}

function openNew() {
    editing.value = null;
    evaluationOpen.value = true;
}

function openEdit(evaluation) {
    editing.value = evaluation;
    evaluationOpen.value = true;
}

function done() {
    emit('reload');
}

async function act(method, url, body, confirmKey) {
    if (busy.value) return;
    if (confirmKey && !window.confirm(ui.value[confirmKey])) return;
    busy.value = true;
    failure.value = '';
    const result = await postOpRequest(method, url, body);
    busy.value = false;

    if (result.ok) {
        message.value = result.data?.message ?? '';
        emit('reload');
        return;
    }

    failure.value = firstError(result, ui.value.error_generic);
}

const finalize = (e) =>
    act('POST', props.section.urls.evaluation_finalize.replace('__ID__', e.id), {
        ...payloadOf(e),
        expected_revision: e.revision,
    });
const remove = (e) =>
    act('DELETE', props.section.urls.evaluation_destroy.replace('__ID__', e.id), null, 'delete_confirm');
const retry = () => act('POST', props.section.urls.returns_retry, {});

/** Reenvia o conteúdo do rascunho como está (o servidor só fecha). */
function payloadOf(e) {
    return {
        milestone: e.milestone.value,
        eye: e.eye.value,
        evaluated_at: String(e.evaluated_at).replace('T', ' ').slice(0, 19),
        va_uncorrected_id: e.va_uncorrected_id,
        va_corrected_id: e.va_corrected_id,
        iop_mmhg: e.iop_mmhg,
        findings: e.findings.map((f) => f.value),
        findings_notes: e.findings_notes,
        refraction_sphere: e.refraction_sphere,
        refraction_cylinder: e.refraction_cylinder,
        refraction_axis: e.refraction_axis,
        complications: e.complications,
        adherence: e.adherence?.value ?? null,
        plan: e.plan,
        schedule_id: e.schedule_id,
    };
}

function attendUrl(row) {
    return props.section.urls.schedule_attend.replace('__ID__', row.schedule.id);
}

const severeAlerts = computed(() => (props.section.alerts ?? []).filter((a) => a.type === 'severe'));
const failedAlerts = computed(() => (props.section.alerts ?? []).filter((a) => a.type === 'return_failed'));
</script>

<template>
    <div class="postop-tab">
        <div v-if="message" class="alert alert-success py-2" role="status">{{ message }}</div>
        <div v-if="failure" class="alert alert-danger py-2" role="alert" data-testid="po-failure">{{ failure }}</div>

        <!-- Alertas -->
        <div
            v-for="a in severeAlerts"
            :key="a.evaluation_id"
            class="alert alert-danger"
            role="alert"
            data-testid="po-severe-alert"
        >
            <strong class="d-block"
                ><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                >{{ tx('severe_alert_title', { milestone: a.milestone, eye: a.eye }) }}</strong
            >
            <span>{{ a.findings.join(', ') }}</span>
            <div class="mt-1">
                <template v-if="a.urgent_return?.status.value === 'scheduled'">
                    <i class="ti ti-calendar-check me-1" aria-hidden="true"></i
                    >{{ tx('urgent_return_ok', { date: dateTime(a.urgent_return.scheduled_for) }) }}
                </template>
                <template v-else-if="a.urgent_return">
                    <i class="ti ti-calendar-x me-1" aria-hidden="true"></i
                    >{{ a.urgent_return.failure_label || ui.urgent_return_failed }}
                </template>
                <template v-else>{{ ui.severe_alert_text }}</template>
            </div>
        </div>
        <div
            v-for="a in failedAlerts"
            :key="a.milestone"
            class="alert alert-warning py-2"
            role="alert"
            data-testid="po-return-failed"
        >
            <i class="ti ti-calendar-off me-1" aria-hidden="true"></i><strong>{{ a.milestone }}</strong
            >: {{ a.reason }}
        </div>

        <!-- Retornos -->
        <section class="card mb-3" :aria-label="ui.returns_title">
            <div class="card-header d-flex align-items-center gap-2">
                <h2 class="h6 mb-0 me-auto">{{ ui.returns_title }}</h2>
                <button
                    v-if="sectionCan.retry"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    :disabled="busy"
                    data-testid="po-retry"
                    @click="retry"
                >
                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ ui.retry }}
                </button>
            </div>
            <div v-if="!section.returns.length" class="card-body text-muted">{{ ui.no_returns }}</div>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ ui.col_milestone }}</th>
                            <th scope="col">{{ ui.col_target }}</th>
                            <th scope="col">{{ ui.col_scheduled }}</th>
                            <th scope="col">{{ ui.col_status }}</th>
                            <th scope="col">
                                <span class="visually-hidden">{{ ui.col_actions }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in section.returns" :key="r.id" data-testid="po-return-row">
                            <td>{{ r.milestone.short }}</td>
                            <td>{{ date(r.target_date) }}</td>
                            <td>
                                {{ r.scheduled_for ? dateTime(r.scheduled_for) : '—' }}
                                <span
                                    v-if="r.deviated"
                                    class="badge text-bg-warning ms-1"
                                    :title="tx('deviated_hint', { date: date(r.target_date) })"
                                    >{{ ui.deviated }}</span
                                >
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="
                                        r.status.value === 'failed'
                                            ? 'text-bg-danger'
                                            : r.status.value === 'cancelled'
                                              ? 'text-bg-secondary'
                                              : 'text-bg-primary'
                                    "
                                    >{{ r.schedule ? r.schedule.situation.label : r.status.label }}</span
                                >
                                <small v-if="r.failure_label" class="d-block text-danger">{{ r.failure_label }}</small>
                            </td>
                            <td class="text-end">
                                <a
                                    v-if="r.schedule && can.perform"
                                    class="btn btn-sm btn-outline-secondary"
                                    :href="attendUrl(r)"
                                    >{{ ui.open_attendance }}</a
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Avaliações -->
        <section class="card mb-3" :aria-label="ui.evaluations_title">
            <div class="card-header d-flex align-items-center gap-2">
                <h2 class="h6 mb-0 me-auto">{{ ui.evaluations_title }}</h2>
                <button
                    v-if="sectionCan.evaluate"
                    type="button"
                    class="btn btn-sm btn-primary"
                    data-testid="po-new"
                    @click="openNew"
                >
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.new_evaluation }}
                </button>
            </div>
            <div v-if="!section.evaluations.length" class="card-body text-muted">{{ ui.no_evaluations }}</div>
            <ul v-else class="list-group list-group-flush">
                <li v-for="e in section.evaluations" :key="e.id" class="list-group-item" data-testid="po-evaluation">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <strong>{{ e.milestone.short }} · {{ e.eye.label }}</strong>
                        <span class="text-muted small">{{ dateTime(e.evaluated_at) }}</span>
                        <span class="badge" :class="e.finalized ? 'text-bg-success' : 'text-bg-secondary'">{{
                            e.status.label
                        }}</span>
                        <span v-if="e.severe" class="badge text-bg-danger">{{ ui.severe_badge }}</span>
                        <span class="text-muted small ms-auto">{{ tx('by_doctor', { name: e.doctor ?? '—' }) }}</span>
                    </div>
                    <dl class="row small mb-1">
                        <dt class="col-sm-3">{{ ui.f_va_uncorrected }} / {{ ui.f_va_corrected }}</dt>
                        <dd class="col-sm-9">{{ e.va_uncorrected ?? '—' }} / {{ e.va_corrected ?? '—' }}</dd>
                        <dt class="col-sm-3">{{ ui.f_iop }}</dt>
                        <dd class="col-sm-9">
                            {{ e.iop_mmhg ?? '—' }}
                            <span v-if="e.iop_attention" class="badge text-bg-warning ms-1">{{
                                ui.iop_attention
                            }}</span>
                        </dd>
                        <dt class="col-sm-3">{{ ui.f_se }}</dt>
                        <dd class="col-sm-9">
                            {{ e.effective_equivalent ?? '—' }}
                            <small v-if="e.addenda.some((a) => a.corrected_equivalent !== null)" class="text-muted"
                                >({{ ui.corrected_se }})</small
                            >
                        </dd>
                        <dt class="col-sm-3">{{ ui.f_findings }}</dt>
                        <dd class="col-sm-9">
                            <span
                                v-for="f in e.findings"
                                :key="f.value"
                                class="badge me-1"
                                :class="f.severe ? 'text-bg-danger' : 'text-bg-light border'"
                                >{{ f.label }}</span
                            >
                            <span v-if="!e.findings.length">—</span>
                            <span v-if="e.findings_notes" class="d-block">{{ e.findings_notes }}</span>
                        </dd>
                        <template v-if="e.plan">
                            <dt class="col-sm-3">{{ ui.f_plan }}</dt>
                            <dd class="col-sm-9">{{ e.plan }}</dd>
                        </template>
                    </dl>
                    <div v-if="e.addenda.length" class="border-start ps-2 small mb-1">
                        <strong>{{ ui.addenda_title }}</strong>
                        <div v-for="a in e.addenda" :key="a.id">
                            {{ dateTime(a.created_at) }} — {{ a.doctor }}: {{ a.text }}
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <template v-if="e.can_edit">
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="openEdit(e)">
                                {{ ui.edit }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-success"
                                :disabled="busy"
                                data-testid="po-finalize"
                                @click="finalize(e)"
                            >
                                {{ ui.finalize }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                :disabled="busy"
                                @click="remove(e)"
                            >
                                {{ ui.delete }}
                            </button>
                        </template>
                        <button
                            v-if="e.can_addendum"
                            type="button"
                            class="btn btn-sm btn-outline-primary"
                            data-testid="po-addendum"
                            @click="addendumFor = e"
                        >
                            {{ ui.addendum_btn }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Colírios e orientações -->
        <section class="card" :aria-label="ui.guidance_section">
            <div class="card-header d-flex align-items-center gap-2">
                <h2 class="h6 mb-0 me-auto">{{ ui.guidance_section }}</h2>
                <button
                    v-if="sectionCan.guidance"
                    type="button"
                    class="btn btn-sm btn-primary"
                    data-testid="po-guidance"
                    @click="guidanceOpen = true"
                >
                    <i class="ti ti-file-text me-1" aria-hidden="true"></i>{{ ui.guidance_issue_btn }}
                </button>
            </div>
            <div v-if="!section.documents.length" class="card-body text-muted">{{ ui.no_guidance }}</div>
            <ul v-else class="list-group list-group-flush">
                <li
                    v-for="d in section.documents"
                    :key="d.id"
                    class="list-group-item d-flex flex-wrap align-items-center gap-2"
                >
                    <span>{{ dateTime(d.issued_at) }} — {{ d.doctor }}</span>
                    <span class="badge" :class="d.current ? 'text-bg-success' : 'text-bg-secondary'">{{
                        d.current ? ui.current : ui.superseded
                    }}</span>
                    <small class="text-muted">{{ d.eyedrops.map((x) => x.name).join(', ') }}</small>
                    <a
                        class="btn btn-sm btn-outline-secondary ms-auto"
                        :href="d.pdf_url"
                        target="_blank"
                        rel="noopener"
                    >
                        <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>{{ ui.pdf }}
                    </a>
                </li>
            </ul>
        </section>

        <EvaluationFormModal
            :open="evaluationOpen"
            :evaluation="editing"
            :section="section"
            :t="sectionT"
            @close="evaluationOpen = false"
            @saved="done"
        />
        <AddendumModal
            :open="addendumFor !== null"
            :evaluation="addendumFor"
            :section="section"
            :t="sectionT"
            @close="addendumFor = null"
            @saved="done"
        />
        <GuidanceModal
            :open="guidanceOpen"
            :section="section"
            :eye-options="section.options.eyes"
            :t="sectionT"
            @close="guidanceOpen = false"
            @saved="done"
        />
    </div>
</template>
