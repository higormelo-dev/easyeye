<script setup>
/**
 * Lista compacta dos prontuários anteriores do paciente (somente leitura).
 * Cada item abre o MedicalRecordViewModal via emit `view`. Os dados vêm do
 * prop `records` (controller buildFormProps → previousRecords) — nenhum fetch aqui.
 */
defineProps({
    records: { type: Array, default: () => [] },
    t:       { type: Object, default: () => ({}) },
});

const emit = defineEmits(['view']);
</script>

<template>
    <div v-if="records.length" class="card prev-records mt-2">
        <div class="card-header py-2 d-flex align-items-center gap-2">
            <i class="fas fa-clock-rotate-left text-primary"></i>
            <span class="fw-semibold small">{{ t.previous_records ?? 'Prontuários anteriores' }}</span>
            <span class="badge bg-light text-muted ms-auto">{{ records.length }}</span>
        </div>
        <ul class="list-group list-group-flush">
            <li v-for="r in records" :key="r.id"
                class="list-group-item prev-records__item"
                role="button"
                tabindex="0"
                @click="emit('view', r)"
                @keydown.enter="emit('view', r)"
                @keydown.space.prevent="emit('view', r)">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0">
                        <div class="fw-semibold small text-truncate">
                            {{ r.created_at_formatted }}
                            <span class="text-muted fw-normal">{{ r.created_at_time }}</span>
                        </div>
                        <div class="text-muted small text-truncate">
                            <i class="fas fa-user-doctor me-1"></i>{{ r.doctor_name }}
                        </div>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
                        <i class="fas fa-eye text-muted prev-records__view"></i>
                        <span v-if="r.is_signed" class="badge bg-success-subtle text-success border border-success-subtle"
                              :title="t.signed ?? 'Assinado'">
                            <i class="fas fa-lock"></i>
                        </span>
                    </div>
                </div>
                <div v-if="r.main_complaint" class="text-muted small mt-1 prev-records__complaint">
                    {{ r.main_complaint }}
                </div>
                <div v-if="r.diagnosis_count > 0" class="small mt-1">
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                        <i class="fas fa-notes-medical me-1"></i>{{ r.diagnosis_count }}
                        {{ r.diagnosis_count === 1 ? (t.diagnosis_one ?? 'diagnóstico') : (t.diagnosis_many ?? 'diagnósticos') }}
                    </span>
                </div>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.prev-records {
    border: 1px solid var(--bs-border-color);
    border-radius: .5rem;
    overflow: hidden;
}
.prev-records__item {
    cursor: pointer;
    transition: background-color .12s ease;
}
.prev-records__item:hover,
.prev-records__item:focus-visible {
    background-color: var(--bs-primary-bg-subtle, #e7f1ff);
    outline: none;
}
.prev-records__view {
    opacity: .4;
    transition: opacity .12s ease;
}
.prev-records__item:hover .prev-records__view {
    opacity: 1;
}
.prev-records__complaint {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
