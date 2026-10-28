<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ReasonModal from '@/Components/Surgery/ReasonModal.vue';
import ScheduleFormModal from '@/Components/Surgery/ScheduleFormModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Aba "Agenda": janela atual da cirurgia (data, hora, local e sala) e as
 * ações de agendar, reagendar e liberar a vaga. Quem pode o quê vem do
 * servidor (`section.can_*`); o conflito de sala, cirurgião, paciente, escala,
 * bloqueio e consulta é conferido no formulário e de novo, sob trava, no envio.
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

const { dateTime, date } = useLocaleFormat();
const s = computed(() => props.t.schedule ?? {});
const ui = computed(() => s.value.ui ?? {});
const sectionUrls = computed(() => props.section.urls ?? {});

const formOpen = ref(false);
const formMode = ref('schedule');
const releasing = ref(false);
const busy = ref(false);
const releaseError = ref('');

const target = computed(() => ({
    id: props.surgicalCase.id,
    title: props.surgicalCase.title,
    eye: props.surgicalCase.eye?.short ?? '',
    patient: props.surgicalCase.patient?.name,
    revision: props.surgicalCase.revision,
    start: props.section.window?.start ?? null,
    duration: props.section.window
        ? Math.round((new Date(props.section.window.end) - new Date(props.section.window.start)) / 60000)
        : props.section.duration,
    facility_id: props.section.facility_id,
    room_id: props.section.window ? props.section.room_id : null,
}));

const formUrls = computed(() => ({
    submit: formMode.value === 'reschedule' ? sectionUrls.value.update : sectionUrls.value.store,
    check: sectionUrls.value.check,
    facilities: sectionUrls.value.facilities,
}));

function open(mode) {
    formMode.value = mode;
    formOpen.value = true;
}

function saved() {
    formOpen.value = false;
    emit('reload');
}

function release(reason) {
    busy.value = true;
    releaseError.value = '';

    router.delete(sectionUrls.value.destroy, {
        data: { reason, expected_revision: props.surgicalCase.revision },
        preserveScroll: true,
        onSuccess: () => {
            releasing.value = false;
        },
        onError: (errors) => {
            releaseError.value = errors.reason ?? Object.values(errors)[0] ?? '';
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

const authorization = computed(() => props.section.authorization ?? {});
</script>

<template>
    <div class="d-grid gap-3">
        <div
            v-if="section.expires_before"
            class="alert alert-warning mb-0"
            role="alert"
            data-testid="schedule-expires-before"
        >
            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
            {{
                ui.expires_before
                    .replace(':valid', date(authorization.valid_until))
                    .replace(':date', date(section.window.start))
            }}
        </div>
        <div
            v-if="authorization.expired"
            class="alert alert-danger mb-0"
            role="alert"
            data-testid="schedule-auth-expired"
        >
            <i class="ti ti-clock-x me-1" aria-hidden="true"></i>{{ ui.authorization_expired }}
        </div>
        <div v-else-if="section.authorization_blocks" class="alert alert-warning mb-0" role="alert">
            {{ ui.authorization_blocks }}
        </div>

        <section aria-labelledby="sched-window">
            <h2 id="sched-window" class="h6">{{ ui.window }}</h2>

            <div v-if="section.window" class="card" data-testid="schedule-window">
                <div class="card-body d-flex flex-wrap gap-4">
                    <div>
                        <div class="text-muted small">{{ ui.start }}</div>
                        <div class="fw-semibold">{{ dateTime(section.window.start) }}</div>
                    </div>
                    <div>
                        <div class="text-muted small">{{ ui.end }}</div>
                        <div class="fw-semibold">{{ dateTime(section.window.end) }}</div>
                    </div>
                    <div>
                        <div class="text-muted small">{{ ui.facility }}</div>
                        <div class="fw-semibold">{{ section.window.facility ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-muted small">{{ ui.room }}</div>
                        <div class="fw-semibold">{{ section.window.room ?? ui.no_room }}</div>
                    </div>
                </div>
            </div>
            <p v-else class="text-muted mb-0" data-testid="schedule-empty">{{ ui.not_scheduled }}</p>
        </section>

        <div class="d-flex flex-wrap gap-2">
            <button
                v-if="section.can_schedule"
                type="button"
                class="btn btn-primary"
                data-testid="schedule-open"
                @click="open('schedule')"
            >
                <i class="ti ti-calendar-plus me-1" aria-hidden="true"></i>{{ ui.schedule }}
            </button>
            <button
                v-if="section.can_reschedule"
                type="button"
                class="btn btn-outline-primary"
                data-testid="reschedule-open"
                @click="open('reschedule')"
            >
                <i class="ti ti-calendar-event me-1" aria-hidden="true"></i>{{ ui.reschedule }}
            </button>
            <button
                v-if="section.can_release"
                type="button"
                class="btn btn-outline-danger"
                data-testid="release-open"
                @click="releasing = true"
            >
                <i class="ti ti-calendar-off me-1" aria-hidden="true"></i>{{ ui.release }}
            </button>
            <a :href="sectionUrls.agenda" class="btn btn-link ms-auto">
                <i class="ti ti-layout-grid me-1" aria-hidden="true"></i>{{ ui.open_agenda }}
            </a>
        </div>

        <ScheduleFormModal
            :open="formOpen"
            :mode="formMode"
            :target="target"
            :facilities="section.facilities"
            :urls="formUrls"
            :t="t"
            @close="formOpen = false"
            @saved="saved"
        />

        <ReasonModal
            :open="releasing"
            :title="ui.release_title"
            :label="ui.release_reason"
            :confirm-text="ui.release_confirm"
            :cancel-text="ui.cancel"
            :busy="busy"
            :error="releaseError"
            :min-length="3"
            tone="danger"
            @close="releasing = false"
            @confirm="release"
        />
    </div>
</template>
