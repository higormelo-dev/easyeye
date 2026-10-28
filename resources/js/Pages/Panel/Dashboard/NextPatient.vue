<script setup>
import { computed } from 'vue';
import { situationBadgeStyle } from './situationBadge.js';

// Próximo paciente do médico (PanelDashboardController::buildNextPatient):
// quem já chegou e espera por ele ou, sem ninguém esperando, o próximo
// horário marcado. null = nada mais hoje.
const props = defineProps({
    patient: { type: Object, default: null },
    isRefreshing: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const isWaiting = computed(() => props.patient?.state === 'waiting');

const detail = computed(() => {
    const p = props.patient;
    if (!p) return '';

    if (!isWaiting.value) return (props.t.next_patient_scheduled ?? '').replace(':time', p.time ?? '');

    const arrived = (props.t.next_patient_arrived ?? '').replace(':time', p.arrived_time ?? '');
    if (!p.waiting_minutes) return arrived;

    return `${arrived} · ${(props.t.next_patient_waiting ?? '').replace(':minutes', String(p.waiting_minutes))}`;
});
</script>

<template>
    <section
        :class="['card db-card next-patient', isWaiting ? 'next-patient--waiting' : 'next-patient--scheduled']"
        :aria-label="t.next_patient_title"
    >
        <div class="card-body d-flex flex-wrap align-items-center gap-3 p-3">
            <span class="next-patient-icon" aria-hidden="true">
                <i :class="isWaiting ? 'ti ti-armchair' : 'ti ti-clock-hour-4'"></i>
            </span>

            <div class="flex-grow-1 min-w-0" aria-live="polite">
                <div class="next-patient-label">
                    {{ t.next_patient_title }}
                    <i v-if="isRefreshing" class="ti ti-loader-2 db-spin ms-1" aria-hidden="true"></i>
                </div>

                <template v-if="patient">
                    <div class="next-patient-name text-truncate">{{ patient.name }}</div>
                    <div class="d-flex flex-wrap align-items-center gap-2 small">
                        <span class="badge rounded-pill px-2 py-1" :style="situationBadgeStyle(patient.badge)">
                            <i :class="`fa ${patient.icon} me-1`" style="font-size: 0.65rem" aria-hidden="true"></i>
                            {{ patient.label }}
                        </span>
                        <span class="text-muted">{{ detail }}</span>
                    </div>
                </template>
                <div v-else class="text-muted small">{{ t.next_patient_empty }}</div>
            </div>

            <div v-if="patient" class="d-flex flex-wrap gap-2 next-patient-actions">
                <a v-if="patient.attend_url" :href="patient.attend_url" class="btn btn-sm btn-primary">
                    <i class="ti ti-player-play me-1" aria-hidden="true"></i>{{ t.btn_start_attendance }}
                </a>
                <a
                    v-if="patient.patient_url"
                    :href="patient.patient_url"
                    class="btn btn-sm db-btn-soft"
                    :aria-label="`${t.btn_open_patient}: ${patient.name}`"
                >
                    {{ t.btn_open_patient }}
                </a>
                <a v-if="!patient.attend_url" :href="patient.schedule_url" class="btn btn-sm db-btn-soft">
                    {{ t.btn_open_schedule }}
                </a>
            </div>
        </div>
    </section>
</template>
