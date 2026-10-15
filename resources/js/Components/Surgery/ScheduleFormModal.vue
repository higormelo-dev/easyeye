<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Agendar / reagendar uma cirurgia: data, hora, duração, local e sala. Enquanto
 * a pessoa preenche, o servidor confere a janela (`check`, sem gravar) e a tela
 * lista cada conflito — sala, cirurgião, paciente, escala, bloqueio, consulta.
 * Quem decide é o envio (sob trava): se alguém ocupou a vaga no meio, o 409
 * volta com os conflitos e a tela os mostra no mesmo lugar.
 *
 * Pai: `target` = { id, title, patient, revision, start, duration, facility_id,
 * room_id } e `facilities` = [{ id, name, rooms: [{ id, name }] }]. `urls` traz
 * `submit`/`check` já resolvidos para o caso. Emite `saved` depois de gravar.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    mode: { type: String, default: 'schedule' }, // schedule | reschedule
    target: { type: Object, default: null },
    facilities: { type: Array, default: () => [] },
    defaultDate: { type: String, default: '' },
    defaultFacility: { type: String, default: '' },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved']);

const s = computed(() => props.t.schedule ?? {});
const f = computed(() => s.value.form ?? {});

const date = ref('');
const time = ref('08:00');
const duration = ref(60);
const facilityId = ref('');
const roomId = ref('');

const saving = ref(false);
const checking = ref(false);
const conflicts = ref([]);
const checked = ref(false);
const errors = ref({});
const message = ref('');

const rooms = computed(() => props.facilities.find((fac) => fac.id === facilityId.value)?.rooms ?? []);
const startsAt = computed(() => (date.value && time.value ? `${date.value}T${time.value}` : ''));
const ready = computed(
    () =>
        Boolean(startsAt.value) &&
        Number(duration.value) >= 5 &&
        Boolean(facilityId.value) &&
        (rooms.value.length === 0 || Boolean(roomId.value)),
);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

watch(
    () => [props.open, props.target?.id],
    ([open]) => {
        if (!open || !props.target) return;
        const start = props.target.start ? new Date(props.target.start) : null;
        const pad = (n) => String(n).padStart(2, '0');
        date.value = start
            ? `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())}`
            : props.defaultDate || '';
        time.value = start ? `${pad(start.getHours())}:${pad(start.getMinutes())}` : '08:00';
        duration.value = props.target.duration || 60;
        facilityId.value =
            props.target.facility_id ||
            props.defaultFacility ||
            (props.facilities.length === 1 ? props.facilities[0].id : '');
        roomId.value = props.target.room_id || '';
        conflicts.value = [];
        checked.value = false;
        errors.value = {};
        message.value = '';
    },
    { immediate: true },
);

watch(facilityId, () => {
    if (!rooms.value.some((r) => r.id === roomId.value))
        roomId.value = rooms.value.length === 1 ? rooms.value[0].id : '';
});

// ── Conferência ao vivo (debounce + descarta resposta velha) ───────────────
let timer = null;
let ticket = 0;

watch([startsAt, duration, facilityId, roomId], () => {
    clearTimeout(timer);
    checked.value = false;
    conflicts.value = [];

    if (!props.open || !ready.value || !props.urls.check) return;
    timer = setTimeout(runCheck, 450);
});

onBeforeUnmount(() => clearTimeout(timer));

function payload() {
    return {
        starts_at: startsAt.value,
        duration_minutes: Number(duration.value),
        surgery_facility_id: facilityId.value,
        surgery_room_id: roomId.value || null,
        expected_revision: props.target?.revision ?? null,
    };
}

async function send(url, method) {
    return fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(payload()),
    });
}

async function runCheck() {
    const current = ++ticket;
    checking.value = true;

    try {
        const res = await send(props.urls.check, 'POST');
        const json = await res.json().catch(() => ({}));
        if (current !== ticket) return;

        if (res.ok) {
            conflicts.value = json.conflicts ?? [];
            checked.value = true;
            message.value = '';
        } else {
            conflicts.value = json.context?.conflicts ?? [];
            message.value =
                res.status === 422 && json.errors ? Object.values(json.errors).flat().join(' ') : (json.message ?? '');
            checked.value = false;
        }
    } catch {
        if (current === ticket) message.value = s.value.check_failed ?? '';
    } finally {
        if (current === ticket) checking.value = false;
    }
}

async function submit() {
    if (!ready.value || saving.value) return;
    saving.value = true;
    errors.value = {};
    message.value = '';

    try {
        const res = await send(props.urls.submit, props.mode === 'reschedule' ? 'PUT' : 'POST');
        const json = await res.json().catch(() => ({}));

        if (res.ok) {
            emit('saved', json.message ?? '');
            return;
        }

        if (res.status === 422) {
            errors.value = json.errors ?? {};
            message.value = json.message ?? '';
        } else {
            conflicts.value = json.context?.conflicts ?? [];
            message.value = json.message ?? s.value.save_failed ?? '';
        }
    } catch {
        message.value = s.value.save_failed ?? '';
    } finally {
        saving.value = false;
    }
}

const title = computed(() => (props.mode === 'reschedule' ? f.value.title_reschedule : f.value.title_schedule));
const firstError = (field) => (errors.value[field] ?? [])[0] ?? '';
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="emit('close')">
        <template #header>{{ title }}</template>

        <form v-if="target" class="p-1" data-testid="schedule-form" @submit.prevent="submit">
            <p class="mb-3">
                <strong>{{ target.title }}</strong>
                <strong v-if="target.eye" class="ms-1" data-testid="schedule-eye">{{ target.eye }}</strong>
                <span v-if="target.patient" class="text-muted"> — {{ target.patient }}</span>
            </p>

            <div class="row g-3">
                <div class="col-sm-5">
                    <label class="form-label fw-medium" for="sched-date">{{ f.date }}</label>
                    <input
                        id="sched-date"
                        v-model="date"
                        type="date"
                        class="form-control"
                        :class="{ 'is-invalid': firstError('starts_at') }"
                        required
                    />
                    <div v-if="firstError('starts_at')" class="invalid-feedback d-block">
                        {{ firstError('starts_at') }}
                    </div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label fw-medium" for="sched-time">{{ f.time }}</label>
                    <input id="sched-time" v-model="time" type="time" step="300" class="form-control" required />
                </div>
                <div class="col-sm-3">
                    <label class="form-label fw-medium" for="sched-duration">{{ f.duration }}</label>
                    <div class="input-group">
                        <input
                            id="sched-duration"
                            v-model.number="duration"
                            type="number"
                            min="5"
                            max="720"
                            step="5"
                            class="form-control"
                            required
                        />
                        <span class="input-group-text">{{ f.minutes }}</span>
                    </div>
                    <div v-if="firstError('duration_minutes')" class="invalid-feedback d-block">
                        {{ firstError('duration_minutes') }}
                    </div>
                </div>

                <div class="col-sm-6">
                    <label class="form-label fw-medium" for="sched-facility">{{ f.facility }}</label>
                    <select
                        id="sched-facility"
                        v-model="facilityId"
                        class="form-select"
                        :class="{ 'is-invalid': firstError('surgery_facility_id') }"
                        required
                    >
                        <option value="" disabled>{{ f.choose }}</option>
                        <option v-for="fac in facilities" :key="fac.id" :value="fac.id">{{ fac.name }}</option>
                    </select>
                    <div v-if="!facilities.length" class="form-text text-danger">
                        {{ f.no_facilities }}
                        <a v-if="urls.facilities" :href="urls.facilities">{{ f.configure }}</a>
                    </div>
                    <div v-if="firstError('surgery_facility_id')" class="invalid-feedback d-block">
                        {{ firstError('surgery_facility_id') }}
                    </div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-medium" for="sched-room">{{ f.room }}</label>
                    <select
                        id="sched-room"
                        v-model="roomId"
                        class="form-select"
                        :disabled="!rooms.length"
                        :required="rooms.length > 0"
                    >
                        <option value="">{{ rooms.length ? f.choose : f.no_room }}</option>
                        <option v-for="room in rooms" :key="room.id" :value="room.id">{{ room.name }}</option>
                    </select>
                    <div v-if="facilityId && !rooms.length" class="form-text">{{ f.no_rooms_hint }}</div>
                </div>
            </div>

            <!-- Conferência da janela -->
            <div class="mt-3" aria-live="polite" data-testid="schedule-check">
                <div v-if="checking" class="text-muted small">
                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ f.checking }}
                </div>
                <div v-else-if="checked && !conflicts.length" class="text-success small" data-testid="schedule-free">
                    <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ f.free }}
                </div>
                <div
                    v-if="conflicts.length"
                    class="alert alert-danger mb-0"
                    role="alert"
                    data-testid="schedule-conflicts"
                >
                    <p class="fw-semibold mb-1">{{ f.conflicts_title }}</p>
                    <ul class="mb-0 ps-3">
                        <li v-for="(c, i) in conflicts" :key="i" data-testid="schedule-conflict">{{ c.text }}</li>
                    </ul>
                </div>
                <div v-else-if="message" class="alert alert-warning mb-0 mt-2" role="alert">{{ message }}</div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="emit('close')">
                {{ f.cancel }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="!ready || saving || checking"
                data-testid="schedule-submit"
                @click="submit"
            >
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ mode === 'reschedule' ? f.confirm_reschedule : f.confirm_schedule }}
            </button>
        </template>
    </CenteredModal>
</template>
