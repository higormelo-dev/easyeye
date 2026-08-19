<script setup>
import { ref } from 'vue';

/**
 * Painel lateral "Consultas anteriores" (somente leitura) — resumo clínico das
 * últimas consultas AO LADO do prontuário atual, para o médico comparar
 * grau/AV/PIO/diagnóstico/conduta SEM sair do atendimento nem abrir modal.
 *
 * Interação em 3 níveis:
 *  1. Resumo compacto sempre visível por consulta (AV, refração, PIO).
 *  2. Clique no item → expande NO PRÓPRIO PAINEL (diagnósticos, conduta,
 *     queixa) — o form continua utilizável ao lado.
 *  3. "Ver completo" → MedicalRecordViewModal (via emit `view`, como antes)
 *     com o prontuário inteiro (biomicroscopia, fundoscopia, prescrições...).
 *
 * O card inteiro é minimizável (header clicável) pra devolver o espaço
 * lateral quando o médico não precisa do histórico.
 *
 * Dados: prop `records` (controller buildFormProps → previousRecords, já com
 * `summary` clínico serializado) — nenhum fetch aqui.
 */
defineProps({
    records: { type: Array, default: () => [] },
    t:       { type: Object, default: () => ({}) },
});

const emit = defineEmits(['view']);

const collapsed  = ref(false);
const expandedId = ref(null);

function toggleExpand(record) {
    expandedId.value = expandedId.value === record.id ? null : record.id;
}
</script>

<template>
    <div v-if="records.length" class="card prev-records mt-2">
        <div class="card-header py-2 d-flex align-items-center gap-2 prev-records__header"
             role="button"
             :title="collapsed ? (t.expand_panel ?? 'Mostrar histórico') : (t.collapse_panel ?? 'Minimizar histórico')"
             @click="collapsed = !collapsed">
            <i class="fas fa-clock-rotate-left text-primary"></i>
            <span class="fw-semibold small">{{ t.previous_records ?? 'Consultas anteriores' }}</span>
            <span class="badge bg-light text-muted ms-auto">{{ records.length }}</span>
            <i class="fas fa-chevron-down prev-records__chevron" :class="{ 'prev-records__chevron--collapsed': collapsed }"></i>
        </div>

        <ul v-show="!collapsed" class="list-group list-group-flush">
            <li v-for="r in records" :key="r.id"
                class="list-group-item prev-records__item"
                :class="{ 'prev-records__item--expanded': expandedId === r.id }"
                role="button"
                tabindex="0"
                @click="toggleExpand(r)"
                @keydown.enter="toggleExpand(r)"
                @keydown.space.prevent="toggleExpand(r)">
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
                        <i class="fas prev-records__view text-muted"
                           :class="expandedId === r.id ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                        <span v-if="r.is_signed" class="badge bg-success-subtle text-success border border-success-subtle"
                              :title="t.signed ?? 'Assinado'">
                            <i class="fas fa-lock"></i>
                        </span>
                    </div>
                </div>

                <!-- Resumo clínico compacto — sempre visível ("quanto estava o grau?") -->
                <div v-if="r.summary" class="prev-records__summary mt-1">
                    <div v-if="r.summary.av_sc" class="prev-records__summary-line">
                        <span class="prev-records__tag">AV s/c</span>{{ r.summary.av_sc }}
                    </div>
                    <div v-if="r.summary.av_cc" class="prev-records__summary-line">
                        <span class="prev-records__tag">AV c/c</span>{{ r.summary.av_cc }}
                    </div>
                    <div v-if="r.summary.refraction" class="prev-records__summary-line">
                        <span class="prev-records__tag">Ref</span>{{ r.summary.refraction }}
                    </div>
                    <div v-if="r.summary.pio" class="prev-records__summary-line">
                        <span class="prev-records__tag">PIO</span>{{ r.summary.pio }}
                    </div>
                </div>

                <!-- Expansão in-panel: diagnósticos + conduta + queixa -->
                <div v-if="expandedId === r.id" class="prev-records__detail mt-2" @click.stop>
                    <div v-if="r.summary?.diagnoses?.length" class="mb-1">
                        <div class="prev-records__detail-label">{{ t.diagnoses ?? 'Diagnósticos' }}</div>
                        <span v-for="diag in r.summary.diagnoses" :key="diag"
                              class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-1 mb-1 text-wrap text-start">
                            {{ diag }}
                        </span>
                    </div>
                    <div v-if="r.summary?.conduct" class="mb-1">
                        <div class="prev-records__detail-label">{{ t.conduct ?? 'Conduta' }}</div>
                        <div class="small text-muted">{{ r.summary.conduct }}</div>
                    </div>
                    <div v-if="r.main_complaint" class="mb-1">
                        <div class="prev-records__detail-label">{{ t.complaint ?? 'Queixa principal' }}</div>
                        <div class="small text-muted">{{ r.main_complaint }}</div>
                    </div>

                    <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-1"
                            @click.stop="emit('view', r)">
                        <i class="fas fa-eye me-1"></i>{{ t.view_full ?? 'Ver prontuário completo' }}
                    </button>
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
.prev-records__header {
    cursor: pointer;
    user-select: none;
}
.prev-records__chevron {
    font-size: .7rem;
    color: var(--bs-secondary-color);
    transition: transform .15s ease;
}
.prev-records__chevron--collapsed {
    transform: rotate(-90deg);
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
.prev-records__item--expanded {
    background-color: var(--bs-primary-bg-subtle, #e7f1ff);
}
.prev-records__view {
    opacity: .4;
    font-size: .7rem;
    transition: opacity .12s ease;
}
.prev-records__item:hover .prev-records__view {
    opacity: 1;
}
.prev-records__summary {
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.prev-records__summary-line {
    font-size: .72rem;
    color: var(--bs-body-color);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.prev-records__tag {
    display: inline-block;
    min-width: 40px;
    font-weight: 700;
    font-size: .62rem;
    color: var(--bs-secondary-color);
    text-transform: uppercase;
    letter-spacing: .02em;
}
.prev-records__detail {
    border-top: 1px dashed var(--bs-border-color);
    padding-top: .5rem;
    cursor: default;
}
.prev-records__detail-label {
    font-size: .64rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: var(--bs-secondary-color);
    margin-bottom: 2px;
}
</style>
