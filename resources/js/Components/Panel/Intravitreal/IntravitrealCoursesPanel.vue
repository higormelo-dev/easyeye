<script setup>
import { reactive, ref } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Cursos de injeções intravítreas (anti-VEGF) do paciente (Fase 6): um cartão por olho com a droga, o intervalo
 * decidido pelo médico, a próxima aplicação (e o atraso, em TEXTO além da cor), o último lote e a autorização. O médico
 * agenda a próxima aplicação na série aberta do curso e encerra o curso com motivo. A execução de cada aplicação é feita
 * no "Confirmar execução" do procedimento, não aqui.
 */
const props = defineProps({
    courses: { type: Array, default: () => [] },
    // Pode gerir o curso (agendar a próxima, encerrar)? É ato do médico e NÃO depende de o prontuário de origem estar aberto.
    canManage: { type: Boolean, default: false },
    // { close: '/…/{course}/close', schedule: '/…/{course}/schedule-next' } com __COURSE_ID__.
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['changed']);

const tx = makeTx(() => props.t);
const { date, dateTime } = useLocaleFormat();

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const action = reactive({ kind: null, course: null, busy: false, error: '' });
const scheduleAt = ref('');
const closeForm = reactive({ status: 'completed', reason: '' });

function open(kind, course) {
    action.kind = kind;
    action.course = course;
    action.error = '';
    scheduleAt.value = '';
    closeForm.status = 'completed';
    closeForm.reason = '';
}

function cancel() {
    if (!action.busy) action.kind = null;
}

async function send(urlKey, body) {
    action.busy = true;
    action.error = '';

    try {
        const response = await fetch(props.urls[urlKey].replace('__COURSE_ID__', action.course.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(body),
        });
        const json = await response.json().catch(() => ({}));

        if (!response.ok) {
            action.error = json.errors
                ? (Object.values(json.errors)[0]?.[0] ?? json.message)
                : (json.message ?? props.t.errors.generic);

            return;
        }

        action.kind = null;
        emit('changed');
    } catch {
        action.error = props.t.errors.generic;
    } finally {
        action.busy = false;
    }
}

const submitSchedule = () => send('schedule', { date_time: scheduleAt.value });
const submitClose = () => send('close', { status: closeForm.status, reason: closeForm.reason });

function nextText(course) {
    if (course.days_to_next === null || course.days_to_next === undefined) return props.t.panel.no_next;

    const when = date(course.next_due_on);
    const days = course.days_to_next;
    const relative =
        days === 0
            ? props.t.panel.today
            : days > 0
              ? tx('panel.in_days', { count: days })
              : tx('panel.overdue_days', { count: Math.abs(days) });

    return `${tx('panel.next', { date: when })} · ${relative}`;
}

const overdue = (course) => course.open && course.days_to_next !== null && course.days_to_next < 0;
</script>

<template>
    <section v-if="courses.length" class="mb-3 recall-form" aria-labelledby="ivt-title" data-testid="ivt-panel">
        <h6 id="ivt-title" class="small text-uppercase text-muted mb-2">
            <i class="fas fa-eye me-1" aria-hidden="true"></i>{{ t.panel.title }}
        </h6>

        <article
            v-for="course in courses"
            :key="course.id"
            class="border rounded p-2 mb-2"
            :class="{ 'opacity-75': !course.open }"
            :data-testid="`ivt-course-${course.eye}`"
            :data-status="course.status"
        >
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-light text-dark border">{{ t.panel.eye[course.eye] }}</span>
                <span class="fw-semibold">{{ course.drug }}</span>
                <span class="recall-tone" :class="course.open ? 'recall-tone--info' : 'recall-tone--muted'">{{
                    course.status_label
                }}</span>
                <span class="small text-muted ms-auto">{{ course.regimen_label }}</span>
            </div>

            <div class="small mt-1">
                {{ tx('panel.applications', { count: course.applications_count }) }}
                <span v-if="course.interval_weeks">
                    · {{ tx('panel.interval', { weeks: course.interval_weeks }) }}</span
                >
            </div>

            <div v-if="course.open" class="small mt-1" data-testid="ivt-next">
                <span v-if="overdue(course)" class="recall-tone recall-tone--danger">{{ nextText(course) }}</span>
                <span v-else>{{ nextText(course) }}</span>
                <span v-if="course.planned?.schedule_id" class="text-success ms-1">
                    <i class="fas fa-calendar-check" aria-hidden="true"></i> {{ t.panel.has_schedule }}
                </span>
            </div>
            <div v-else class="small text-muted mt-1">
                {{ tx('panel.ended', { date: date(course.ended_on), reason: course.end_reason ?? '' }) }}
            </div>

            <div v-if="course.last_applications.length" class="small text-muted mt-1">
                {{
                    tx('panel.last_lot', {
                        lot: course.last_applications[0].lot_number ?? '—',
                        expiry: course.last_applications[0].lot_expiry
                            ? date(course.last_applications[0].lot_expiry)
                            : '—',
                        auth: course.last_applications[0].authorization_label,
                    })
                }}
                <span v-if="course.last_applications[0].performed_at">
                    · {{ dateTime(course.last_applications[0].performed_at) }}</span
                >
            </div>

            <div v-if="canManage && course.open && action.course?.id !== course.id" class="d-flex flex-wrap gap-2 mt-2">
                <button
                    v-if="!course.planned?.schedule_id"
                    type="button"
                    class="btn btn-outline-primary btn-sm"
                    data-testid="ivt-schedule-open"
                    @click="open('schedule', course)"
                >
                    <i class="fas fa-calendar-plus me-1" aria-hidden="true"></i>{{ t.panel.schedule }}
                </button>
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-testid="ivt-close-open"
                    @click="open('close', course)"
                >
                    {{ t.panel.close }}
                </button>
            </div>

            <div
                v-if="action.kind && action.course?.id === course.id"
                class="border-top mt-2 pt-2"
                data-testid="ivt-action"
            >
                <template v-if="action.kind === 'schedule'">
                    <label class="form-label small mb-1" for="ivt-schedule-at">{{ t.panel.schedule_when }} *</label>
                    <input
                        id="ivt-schedule-at"
                        v-model="scheduleAt"
                        type="datetime-local"
                        class="form-control form-control-sm"
                    />
                    <p class="form-text">{{ t.panel.schedule_hint }}</p>
                </template>
                <template v-else>
                    <label class="form-label small mb-1" for="ivt-close-status">{{ t.panel.close_status }}</label>
                    <select id="ivt-close-status" v-model="closeForm.status" class="form-select form-select-sm mb-2">
                        <option value="completed">{{ t.exec.end_completed }}</option>
                        <option value="discontinued">{{ t.exec.end_discontinued }}</option>
                    </select>
                    <label class="form-label small mb-1" for="ivt-close-reason">{{ t.panel.close_reason }} *</label>
                    <input
                        id="ivt-close-reason"
                        v-model="closeForm.reason"
                        type="text"
                        maxlength="500"
                        class="form-control form-control-sm"
                    />
                </template>

                <p v-if="action.error" class="text-danger small mt-2 mb-0" role="alert" data-testid="ivt-error">
                    {{ action.error }}
                </p>

                <div class="d-flex justify-content-end gap-2 mt-2">
                    <button type="button" class="btn btn-light btn-sm" :disabled="action.busy" @click="cancel">
                        {{ t.panel.back }}
                    </button>
                    <button
                        v-if="action.kind === 'schedule'"
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="action.busy || !scheduleAt"
                        data-testid="ivt-schedule-confirm"
                        @click="submitSchedule"
                    >
                        {{ t.panel.schedule_confirm }}
                    </button>
                    <button
                        v-else
                        type="button"
                        class="btn btn-danger btn-sm"
                        :disabled="action.busy || closeForm.reason.trim().length < 3"
                        data-testid="ivt-close-confirm"
                        @click="submitClose"
                    >
                        {{ t.panel.close_confirm }}
                    </button>
                </div>
            </div>
        </article>
    </section>
</template>
