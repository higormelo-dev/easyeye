<script setup>
defineProps({
    patients: { type: Array, default: () => [] },
    // "Ver todos"/"Abrir" só para quem pode abrir Pacientes (regra da rota).
    canOpenPatients: { type: Boolean, default: false },
    // Pacientes do médico (atendidos por ele): última consulta no lugar do
    // telefone, que o servidor nem envia (minimização).
    mine: { type: Boolean, default: false },
    t: { type: Object, required: true },
});
</script>

<template>
    <section class="card db-card recent-patients" :aria-label="mine ? t.section_my_recent_patients : t.section_recent_patients">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-users" aria-hidden="true"></i>
                {{ mine ? t.section_my_recent_patients : t.section_recent_patients }}
            </h3>
            <a v-if="canOpenPatients" :href="route('panel.patients.index')" class="db-link">
                {{ t.btn_see_all }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <div v-if="patients.length === 0" class="db-empty db-empty--inline">
            <i class="ti ti-users" aria-hidden="true"></i>
            <span>{{ mine ? t.empty_my_patients : t.empty_patients }}</span>
        </div>

        <ul v-else class="db-list">
            <li v-for="p in patients" :key="p.id" class="db-list__item">
                <span class="patient-initial" :style="{ background: p.color }" aria-hidden="true">{{ p.initial }}</span>
                <div class="db-list__main">
                    <span class="db-list__title">{{ p.name }}</span>
                    <span class="db-list__sub">
                        <template v-if="mine">{{ t.col_last_visit }}: {{ p.last_visit }}</template>
                        <template v-else>{{ t.col_phone }}: {{ p.phone }}</template>
                        <span class="d-none d-sm-inline"> · {{ p.code }}</span>
                    </span>
                </div>
                <a
                    v-if="canOpenPatients"
                    :href="p.url"
                    class="btn btn-sm db-btn-soft flex-shrink-0"
                    :aria-label="`${t.btn_view}: ${p.name}`"
                >
                    {{ t.btn_view }}
                </a>
            </li>
        </ul>
    </section>
</template>
