<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Atendimentos por médico hoje (administração): agendadas, atendidas sobre o
 * que ainda conta (agendadas − faltas/cancelamentos), quem está na clínica e
 * as faltas — um agregado por médico, nenhum paciente.
 */
const props = defineProps({
    // ClinicOperationsService::doctorsToday()
    doctors: { type: Object, default: null },
    t: { type: Object, required: true },
});

const { number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const items = computed(() =>
    (props.doctors?.items ?? []).map((doctor) => ({
        ...doctor,
        pct: doctor.expected > 0 ? Math.round((doctor.attended / doctor.expected) * 100) : 0,
    })),
);
</script>

<template>
    <section class="card db-card doctors-today" data-tour="dashboard-doctors-today" :aria-label="t.doctors_today_title">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-stethoscope" aria-hidden="true"></i>
                {{ t.doctors_today_title }}
            </h3>
        </div>

        <div v-if="!items.length" class="db-empty db-empty--inline">
            <i class="ti ti-calendar-off" aria-hidden="true"></i>
            <span>{{ t.doctors_today_empty }}</span>
        </div>

        <table v-else class="table table-sm db-table mb-0">
            <thead>
                <tr>
                    <th scope="col">{{ t.col_doctor }}</th>
                    <th scope="col" class="text-end">{{ t.doctors_col_progress }}</th>
                    <th scope="col" class="text-end" :title="t.doctors_col_waiting_hint">{{ t.doctors_col_waiting }}</th>
                    <th scope="col" class="text-end" :title="t.summary_cancelled">{{ t.doctors_col_missed }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="doctor in items" :key="doctor.id" :data-doctor="doctor.id">
                    <th scope="row" class="db-table__name">
                        <span class="text-truncate d-block">{{ doctor.name }}</span>
                        <span class="db-mini-bar" aria-hidden="true">
                            <span :style="{ width: `${doctor.pct}%` }"></span>
                        </span>
                    </th>
                    <td class="text-end text-nowrap">
                        <span class="visually-hidden">{{ tx('doctors_progress_sr', { attended: doctor.attended, expected: doctor.expected }) }}</span>
                        <span aria-hidden="true"><strong>{{ number(doctor.attended) }}</strong> / {{ number(doctor.expected) }}</span>
                    </td>
                    <td class="text-end" :class="{ 'text-warning-emphasis fw-semibold': doctor.waiting > 0 }">
                        {{ number(doctor.waiting) }}
                    </td>
                    <td class="text-end" :class="{ 'text-danger-emphasis': doctor.missed > 0 }">{{ number(doctor.missed) }}</td>
                </tr>
            </tbody>
        </table>

        <p v-if="doctors?.others > 0" class="db-card-note" role="note">
            {{ tx('list_more', { count: number(doctors.others) }) }}
        </p>
    </section>
</template>
