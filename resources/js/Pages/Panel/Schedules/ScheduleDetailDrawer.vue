<script setup>
import { ref, watch } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:       { type: Boolean, required: true },
    scheduleId: { type: [String, Number], default: null },
    t:          { type: Object,  default: () => ({}) },
});

defineEmits(['close']);

const loading  = ref(false);
const schedule = ref(null);

async function loadDetail(id) {
    loading.value  = true;
    schedule.value = null;
    try {
        const res  = await fetch(route('panel.schedules.show', id), {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();
        schedule.value = json.data;
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val && props.scheduleId) loadDetail(props.scheduleId);
    if (!val) schedule.value = null;
});
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="640"
        :loading="loading"
        :loading-label="t.drawer_loading ?? 'Carregando...'"
        @close="$emit('close')"
    >
        <!-- ── Header ─────────────────────────────────────────────────────── -->
        <template #header>
            <div class="d-flex align-items-center gap-3 flex-grow-1 min-width-0">
                <div
                    v-if="schedule"
                    class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                    style="width:64px;height:64px;"
                    :style="{
                        background: (schedule.doctor_color ?? '#6c757d') + '22',
                        border: `2px solid ${schedule.doctor_color ?? '#6c757d'}`,
                    }"
                >
                    <i class="ti ti-calendar-event fs-3" :style="{ color: schedule.doctor_color ?? '#6c757d' }"></i>
                </div>
                <div class="min-width-0 flex-grow-1">
                    <h5 class="mb-0 fw-semibold text-truncate">
                        {{ schedule?.patient_name ?? (t.drawer_loading ?? 'Carregando...') }}
                    </h5>
                    <div v-if="schedule" class="d-flex align-items-center gap-2 flex-wrap mt-1">
                        <code class="text-muted small">{{ schedule.code }}</code>
                        <span class="badge rounded-pill" :class="schedule.situation_badge" style="font-size:.7rem;">
                            <i class="fas me-1" :class="schedule.situation_icon"></i>{{ schedule.situation_label }}
                        </span>
                        <span v-if="!schedule.patient_is_registered" class="badge bg-secondary rounded-pill" style="font-size:.7rem;">
                            {{ t.drawer_unregistered ?? 'Sem cadastro' }}
                        </span>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Body ───────────────────────────────────────────────────────── -->
        <template v-if="schedule">

            <!-- Identificação -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-id-badge me-1"></i> {{ t.drawer_section_id ?? 'Identificação' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_code ?? 'Código' }}</span>
                        <span class="detail-value"><code>{{ schedule.code }}</code></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_datetime ?? 'Data e hora' }}</span>
                        <span class="detail-value">{{ schedule.date_time }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_situation ?? 'Situação' }}</span>
                        <span class="detail-value">
                            <span class="badge" :class="schedule.situation_badge">
                                <i class="fas me-1" :class="schedule.situation_icon"></i>{{ schedule.situation_label }}
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Paciente -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-user me-1"></i> {{ t.drawer_section_patient ?? 'Paciente' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_name ?? 'Nome' }}</span>
                        <span class="detail-value">
                            {{ schedule.patient_name ?? '—' }}
                            <a v-if="schedule.medical_records_url"
                               :href="schedule.medical_records_url"
                               class="ms-2 small text-decoration-none"
                               :title="schedule.patient_code">
                                <i class="ti ti-stethoscope"></i>
                            </a>
                        </span>
                    </div>
                    <div v-if="schedule.patient_code" class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_code ?? 'Código' }}</span>
                        <span class="detail-value"><code>{{ schedule.patient_code }}</code></span>
                    </div>
                </div>
            </div>

            <!-- Médico -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-stethoscope me-1"></i> {{ t.drawer_section_doctor ?? 'Médico' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_name ?? 'Nome' }}</span>
                        <span class="detail-value d-flex align-items-center gap-2">
                            <span
                                v-if="schedule.doctor_color"
                                class="rounded-circle d-inline-block"
                                :style="{ background: schedule.doctor_color, width: '10px', height: '10px' }"
                            ></span>
                            <span>{{ schedule.doctor_name ?? '—' }}</span>
                        </span>
                    </div>
                    <div v-if="schedule.doctor_code" class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_code ?? 'Código' }}</span>
                        <span class="detail-value"><code>{{ schedule.doctor_code }}</code></span>
                    </div>
                    <div v-if="schedule.doctor_record" class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_record ?? 'CRM' }}</span>
                        <span class="detail-value">{{ schedule.doctor_record }}</span>
                    </div>
                </div>
            </div>

            <!-- Atendimento -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-clipboard-list me-1"></i> {{ t.drawer_section_visit ?? 'Atendimento' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_visit ?? 'Tipo de visita' }}</span>
                        <span class="detail-value">{{ schedule.visit_type_name ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_covenant ?? 'Convênio' }}</span>
                        <span class="detail-value">{{ schedule.covenant_name ?? '—' }}</span>
                    </div>
                    <div v-if="schedule.attendance_type_name" class="detail-row">
                        <span class="detail-label">{{ t.form_attendance_type ?? 'Tipo de atendimento' }}</span>
                        <span class="detail-value">{{ schedule.attendance_type_name }}</span>
                    </div>
                    <div v-if="schedule.specialty_area_name" class="detail-row">
                        <span class="detail-label">{{ t.form_specialty_area ?? 'Especialidade / Área' }}</span>
                        <span class="detail-value">{{ schedule.specialty_area_name }}</span>
                    </div>
                    <div v-if="schedule.arrived_at" class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_arrived ?? 'Chegou em' }}</span>
                        <span class="detail-value">{{ schedule.arrived_at }}</span>
                    </div>
                    <div v-if="schedule.confirmed_at" class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_confirmed ?? 'Confirmado em' }}</span>
                        <span class="detail-value">{{ schedule.confirmed_at }}</span>
                    </div>
                    <div v-if="schedule.notes" class="detail-row">
                        <span class="detail-label">{{ t.show_notes ?? 'Observações' }}</span>
                        <span class="detail-value text-prewrap">{{ schedule.notes }}</span>
                    </div>
                    <div v-if="schedule.cancellation_reason" class="detail-row">
                        <span class="detail-label text-danger">{{ t.show_cancel_reason ?? 'Motivo do cancelamento' }}</span>
                        <span class="detail-value text-danger text-prewrap">{{ schedule.cancellation_reason }}</span>
                    </div>
                </div>
            </div>

            <!-- Contato (telefone / celular informados na agenda) -->
            <div v-if="schedule.telephone || schedule.cellphone" class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-phone me-1"></i> {{ t.drawer_section_contact ?? 'Contato' }}
                </div>
                <div class="detail-table">
                    <div v-if="schedule.telephone" class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_telephone ?? 'Telefone' }}</span>
                        <span class="detail-value">{{ schedule.telephone }}</span>
                    </div>
                    <div v-if="schedule.cellphone" class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_cellphone ?? 'Celular' }}</span>
                        <span class="detail-value">
                            {{ schedule.cellphone }}
                            <span v-if="schedule.cellphone_whatsapp" class="badge bg-success-subtle text-success border border-success ms-1 rounded-pill" style="font-size:.65rem;">
                                WhatsApp
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Recursos -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-package me-1"></i> {{ t.drawer_section_resources ?? 'Recursos' }}
                </div>
                <div v-if="schedule.resources.length === 0" class="text-muted small fst-italic">
                    {{ t.drawer_no_resources ?? 'Nenhum recurso vinculado' }}
                </div>
                <ul v-else class="list-unstyled mb-0">
                    <li v-for="r in schedule.resources" :key="r.id" class="d-flex align-items-start gap-2 mb-2">
                        <i class="ti ti-circle-filled text-primary mt-1" style="font-size:.5rem;"></i>
                        <div class="flex-grow-1">
                            <div class="fw-medium small">{{ r.name }}</div>
                            <div v-if="r.type || r.code" class="text-muted" style="font-size:.75rem;">
                                <span v-if="r.type">{{ r.type }}</span>
                                <span v-if="r.type && r.code"> · </span>
                                <code v-if="r.code">{{ r.code }}</code>
                            </div>
                            <div v-if="r.description" class="text-muted small">{{ r.description }}</div>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Histórico -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-history me-1"></i> {{ t.show_history ?? 'Histórico' }}
                </div>
                <div v-if="schedule.situation_logs.length === 0" class="text-muted small fst-italic">
                    {{ t.drawer_no_history ?? 'Nenhuma alteração de situação registrada' }}
                </div>
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="small">{{ t.show_from ?? 'De' }}</th>
                                <th class="small">{{ t.show_to ?? 'Para' }}</th>
                                <th class="small">{{ t.show_by ?? 'Por' }}</th>
                                <th class="small text-nowrap">{{ t.show_when ?? 'Quando' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in schedule.situation_logs" :key="log.id">
                                <td>
                                    <span v-if="log.from_label" class="badge" :class="log.from_badge" style="font-size:.65rem;">
                                        {{ log.from_label }}
                                    </span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <span class="badge" :class="log.to_badge" style="font-size:.65rem;">
                                        {{ log.to_label }}
                                    </span>
                                </td>
                                <td class="small">{{ log.user_name ?? '—' }}</td>
                                <td class="small text-nowrap text-muted">{{ log.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Sistema -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-info-circle me-1"></i> {{ t.drawer_section_system ?? 'Sistema' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_created ?? 'Criado em' }}</span>
                        <span class="detail-value">{{ schedule.created_at ?? '—' }}</span>
                    </div>
                </div>
            </div>

        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.min-width-0 { min-width: 0; }
.text-prewrap { white-space: pre-wrap; }
.detail-section { margin-bottom: 1.5rem; }
.detail-section__title {
    font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    color: var(--bs-secondary-color); margin-bottom: .5rem; padding-bottom: .25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.detail-table { display: grid; gap: .375rem; }
.detail-row { display: grid; grid-template-columns: 150px 1fr; gap: .5rem; font-size: .875rem; align-items: baseline; }
.detail-label { font-weight: 600; color: var(--bs-body-color); }
.detail-value { color: var(--bs-secondary-color); word-break: break-word; }
</style>
