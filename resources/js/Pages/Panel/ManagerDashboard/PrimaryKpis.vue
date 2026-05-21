<script setup>
const props = defineProps({
    primaryKpis:      { type: Object, required: true },
    subscriptionKpis: { type: Object, required: true },
    t:                { type: Object, required: true },
});
</script>

<template>
    <!-- Linha 1: Clínicas, Ativas, Trial, MRR -->
    <div class="row g-3 mb-3">

        <!-- Total Clínicas -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--entities h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--entities">
                        <i class="ti ti-building"></i>
                    </div>
                    <div>
                        <div class="stat-value">{{ primaryKpis.totalEntities }}</div>
                        <div class="stat-label">{{ t.kpi_entities }}</div>
                        <div class="d-flex gap-2 mt-1">
                            <span class="period-badge">
                                {{ t.today }} <span class="period-value">+{{ primaryKpis.newEntitiesToday }}</span>
                            </span>
                            <span class="period-badge">
                                {{ t.month }} <span class="period-value">+{{ primaryKpis.newEntitiesMonth }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assinaturas Ativas -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--active h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--active">
                        <i class="ti ti-circle-check"></i>
                    </div>
                    <div>
                        <div class="stat-value">{{ subscriptionKpis.subscriptionCounts?.active ?? 0 }}</div>
                        <div class="stat-label">{{ t.kpi_active_subs }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Em Trial -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--trial h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--trial">
                        <i class="ti ti-clock-hour-4"></i>
                    </div>
                    <div>
                        <div class="stat-value">{{ subscriptionKpis.subscriptionCounts?.trial ?? 0 }}</div>
                        <div class="stat-label">{{ t.kpi_trial }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MRR -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--mrr h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--mrr">
                        <i class="ti ti-currency-real"></i>
                    </div>
                    <div>
                        <div class="stat-value">
                            R$ {{ Number(subscriptionKpis.mrr).toLocaleString('pt-BR', { minimumFractionDigits: 0 }) }}
                        </div>
                        <div class="stat-label">{{ t.kpi_mrr }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Linha 2: Pacientes, Médicos, Prontuários, Agendamentos -->
    <div class="row g-3 mb-4">

        <!-- Total Pacientes -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--patients h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--patients">
                        <i class="ti ti-users"></i>
                    </div>
                    <div>
                        <div class="stat-value">{{ primaryKpis.totalPatients?.toLocaleString('pt-BR') }}</div>
                        <div class="stat-label">{{ t.kpi_patients }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Médicos -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--doctors h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--doctors">
                        <i class="ti ti-stethoscope"></i>
                    </div>
                    <div>
                        <div class="stat-value">{{ primaryKpis.totalDoctors?.toLocaleString('pt-BR') }}</div>
                        <div class="stat-label">{{ t.kpi_doctors }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Prontuários -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--records h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--records">
                        <i class="ti ti-file-text"></i>
                    </div>
                    <div>
                        <div class="stat-value">{{ primaryKpis.totalMedicalRecords?.toLocaleString('pt-BR') }}</div>
                        <div class="stat-label">{{ t.kpi_records }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Agendamentos -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--schedules h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--schedules">
                        <i class="ti ti-calendar-event"></i>
                    </div>
                    <div>
                        <div class="stat-value">{{ primaryKpis.totalSchedules?.toLocaleString('pt-BR') }}</div>
                        <div class="stat-label">{{ t.kpi_schedules }}</div>
                        <div class="d-flex gap-2 mt-1">
                            <span class="period-badge">
                                {{ t.today }} <span class="period-value">{{ primaryKpis.schedulesToday }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
