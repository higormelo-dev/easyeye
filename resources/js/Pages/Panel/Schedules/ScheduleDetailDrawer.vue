<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { latestRequest, isAbortError } from '@/Support/latestRequest.js';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import ClinicalAlertBanner from '@/Components/Panel/ClinicalSafety/ClinicalAlertBanner.vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    scheduleId: { type: [String, Number], default: null },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'reschedule', 'changed']);

const loading = ref(false);
const schedule = ref(null);

// ── Série de sessões: cancelar as futuras (com motivo) ──────────────────────
const cancelSeriesOpen = ref(false);
const cancelReason = ref('');
const cancelSaving = ref(false);
const cancelError = ref('');

const sessionLabel = computed(() =>
    schedule.value?.session_total ? `${schedule.value.session_number}/${schedule.value.session_total}` : null,
);

function rescheduleSession(session) {
    emit('reschedule', {
        id: session.id,
        name: session.name,
        time: session.time,
        doctor_id: session.doctor_id,
        is_fit_in: session.is_fit_in,
        reschedule_url: session.reschedule_url,
    });
}

async function cancelFutureSessions() {
    if (cancelSaving.value || !cancelReason.value.trim()) return;
    cancelSaving.value = true;
    cancelError.value = '';
    try {
        const res = await fetch(schedule.value.cancel_series_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ reason: cancelReason.value.trim() }),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            cancelError.value = json.errors?.reason?.[0] ?? json.message ?? props.t.series_cancel_error ?? 'Erro.';
            return;
        }
        cancelSeriesOpen.value = false;
        cancelReason.value = '';
        if (window.showSuccessToast) window.showSuccessToast(json.message);
        emit('changed', json);
        await loadDetail(schedule.value.id);
    } finally {
        cancelSaving.value = false;
    }
}

// Só a última carga vale (agendamento traz paciente, alergias e medicações):
// a resposta atrasada de outro agendamento não sobrescreve a gaveta aberta.
const detailReq = latestRequest();

async function loadDetail(id) {
    const req = detailReq.begin();
    loading.value = true;
    schedule.value = null;
    try {
        const res = await fetch(route('panel.schedules.show', id), {
            headers: { Accept: 'application/json' },
            signal: req.signal,
        });
        const json = await res.json();
        if (req.current()) schedule.value = json.data;
    } catch (error) {
        if (!isAbortError(error) && req.current()) throw error;
    } finally {
        if (req.current()) loading.value = false;
    }
}

watch(
    () => [props.open, props.scheduleId],
    ([open, id]) => {
        if (open && id) loadDetail(id);
        if (!open) {
            detailReq.cancel();
            loading.value = false;
            schedule.value = null;
            cancelSeriesOpen.value = false;
            cancelReason.value = '';
            cancelError.value = '';
        }
    },
);

// ── Copiar código (SDL/PAC/DOC) ─────────────────────────────────────────────
// Copia só prefixo + número sem zeros à esquerda (SDL-741 em vez de
// SDL-0000000741) — cosmético, só o que vai pro clipboard; o valor exibido
// na tela e o gravado no banco continuam com o zero-padding original.
const copiedField = ref(null);

function shortCode(code) {
    const m = String(code ?? '').match(/^([A-Za-z]+)-0*(\d+)$/);
    return m ? `${m[1]}-${m[2]}` : code;
}

// Só os dígitos, sem o prefixo de letra nem zeros à esquerda (PAC-0000000123
// → "123"). O software nativo de vários aparelhos (ex.: OCULUS Patient Data
// Management) busca paciente por um campo "ID Number" numérico puro — colar
// "PAC-123" ali falha ou exige o operador apagar o prefixo/traço na mão a
// cada exame. Copiar já só o número elimina essa edição manual sem o
// integrador participar em nenhum momento — a colagem continua sendo feita
// pelo operador, direto do EasyEye pro programa do aparelho.
//
// USAR SÓ COM schedule.patient_code, NUNCA com schedule.code: o campo de ID
// do aparelho é a chave de busca na base de dados LOCAL dele. O código do
// paciente é estável (mesma pessoa, toda visita); o código do agendamento
// muda a cada consulta. Colar o código do agendamento faria a busca por ID
// nunca encontrar o cadastro de visitas anteriores — o operador criaria um
// paciente duplicado no aparelho a cada retorno, fragmentando o histórico de
// exames dessa pessoa entre vários cadastros locais diferentes.
function numericCode(code) {
    const m = String(code ?? '').match(/^([A-Za-z]+)-0*(\d+)$/);
    return m ? m[2] : String(code ?? '').replace(/\D/g, '');
}

// "Copiado" some em 2 s; sem área de transferência (permissão negada,
// contexto inseguro) só não marca — nada de promessa rejeitada solta.
let copiedTimer = null;
onBeforeUnmount(() => clearTimeout(copiedTimer));

function markCopied(field) {
    copiedField.value = field;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => {
        if (copiedField.value === field) copiedField.value = null;
    }, 2000);
}

function copyCode(field, code) {
    if (!code) return;
    navigator.clipboard
        ?.writeText(shortCode(code))
        .then(() => markCopied(field))
        .catch(() => {});
}

function copyNumericCode(field, code) {
    if (!code) return;
    navigator.clipboard
        ?.writeText(numericCode(code))
        .then(() => markCopied(field))
        .catch(() => {});
}
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
                    style="width: 64px; height: 64px"
                    :style="{
                        background: (schedule.doctor_color ?? '#6c757d') + '22',
                        border: `2px solid ${schedule.doctor_color ?? '#6c757d'}`,
                    }"
                >
                    <i class="ti ti-calendar-event fs-3" :style="{ color: schedule.doctor_color ?? '#6c757d' }"></i>
                </div>
                <div class="min-width-0 flex-grow-1">
                    <h5 class="mb-0 fw-semibold text-truncate">
                        {{ schedule?.patient_name ?? t.drawer_loading ?? 'Carregando...' }}
                    </h5>
                    <div v-if="schedule" class="d-flex align-items-center gap-2 flex-wrap mt-1">
                        <code v-if="schedule.patient_code" class="text-muted small">{{ schedule.patient_code }}</code>
                        <span class="badge rounded-pill" :class="schedule.situation_badge" style="font-size: 0.7rem">
                            <i class="fas me-1" :class="schedule.situation_icon"></i>{{ schedule.situation_label }}
                        </span>
                        <span
                            v-if="!schedule.patient_is_registered"
                            class="badge bg-secondary rounded-pill"
                            style="font-size: 0.7rem"
                        >
                            {{ t.drawer_unregistered ?? 'Sem cadastro' }}
                        </span>
                        <span
                            v-if="schedule.is_fit_in"
                            class="badge rounded-pill drawer-fit-in"
                            data-test="drawer-fit-in"
                        >
                            <i class="ti ti-arrow-autofit-height me-1" aria-hidden="true"></i
                            >{{ t.fit_in_badge ?? 'Encaixe' }}
                        </span>
                        <span
                            v-if="schedule.is_urgent_care"
                            class="badge rounded-pill drawer-urgent"
                            :title="t.urgent_care_hint ?? ''"
                            data-test="drawer-urgent-care"
                        >
                            <i class="ti ti-urgent me-1" aria-hidden="true"></i
                            >{{ t.urgent_care_badge ?? 'Pronto atendimento' }}
                        </span>
                        <span v-if="sessionLabel" class="badge rounded-pill drawer-session" data-test="drawer-session">
                            <i class="ti ti-repeat me-1" aria-hidden="true"></i
                            >{{ (t.session_label ?? 'Sessão :session').replace(':session', sessionLabel) }}
                        </span>
                    </div>
                    <div
                        v-if="schedule && (schedule.patient_sheet_url || schedule.whatsapp_state)"
                        class="d-flex gap-2 mt-2 flex-wrap"
                    >
                        <a
                            v-if="schedule.patient_sheet_url"
                            :href="schedule.patient_sheet_url"
                            target="_blank"
                            rel="noopener"
                            class="btn btn-sm btn-outline-secondary"
                            data-test="drawer-patient-sheet"
                        >
                            <i class="ti ti-printer me-1" aria-hidden="true"></i
                            >{{ t.print_patient_sheet ?? 'Ficha do paciente' }}
                        </a>
                        <a
                            v-if="schedule.whatsapp_state === 'ok'"
                            :href="schedule.whatsapp_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="btn btn-sm btn-outline-success"
                            data-test="drawer-whatsapp"
                        >
                            <i class="ti ti-brand-whatsapp me-1" aria-hidden="true"></i
                            >{{ t.whatsapp_open ?? 'WhatsApp' }}
                        </a>
                        <button
                            v-else-if="schedule.whatsapp_state"
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            disabled
                            :title="
                                schedule.whatsapp_state === 'opted_out'
                                    ? t.whatsapp_opted_out_hint
                                    : t.whatsapp_no_number
                            "
                            data-test="drawer-whatsapp-disabled"
                        >
                            <i class="ti ti-brand-whatsapp me-1" aria-hidden="true"></i
                            >{{ t.whatsapp_open ?? 'WhatsApp' }}
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Body ───────────────────────────────────────────────────────── -->
        <template v-if="schedule">
            <!-- Alergias do paciente (Fase 2): alerta fixo para quem atende. -->
            <ClinicalAlertBanner
                v-if="schedule.clinical_safety"
                :summary="schedule.clinical_safety"
                :show-risks="false"
                compact
                class="mb-3"
            />
            <!-- Identificação -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-id-badge me-1"></i> {{ t.drawer_section_id ?? 'Identificação' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_code ?? 'Código' }}</span>
                        <span class="detail-value d-flex align-items-center gap-1">
                            <code>{{ schedule.code }}</code>
                            <button
                                type="button"
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.drawer_copy_code ?? 'Copiar código'"
                                @click="copyCode('schedule', schedule.code)"
                            >
                                <i :class="copiedField === 'schedule' ? 'ti ti-check text-success' : 'ti ti-copy'"></i>
                            </button>
                            <!--
                                Sem botão de "copiar só número" aqui DE PROPÓSITO. O código
                                do agendamento é novo a cada consulta — colar esse número no
                                campo de busca de ID do software do aparelho (ex.: OCULUS
                                Patient Data Management) nunca bate com o cadastro de visitas
                                anteriores, e o operador acaba criando um paciente duplicado
                                na base LOCAL do aparelho a cada retorno. O identificador
                                estável pra essa finalidade é o do PACIENTE (abaixo), nunca o
                                do agendamento. Ver ScheduleDetailDrawer.test.js.
                            -->
                        </span>
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
                            <a
                                v-if="schedule.medical_records_url"
                                :href="schedule.medical_records_url"
                                class="ms-2 small text-decoration-none"
                                :title="schedule.patient_code"
                            >
                                <i class="ti ti-stethoscope"></i>
                            </a>
                        </span>
                    </div>
                    <div v-if="schedule.patient_code" class="detail-row">
                        <span class="detail-label">{{ t.drawer_label_code ?? 'Código' }}</span>
                        <span class="detail-value d-flex align-items-center gap-1">
                            <code>{{ schedule.patient_code }}</code>
                            <button
                                type="button"
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.drawer_copy_code ?? 'Copiar código'"
                                @click="copyCode('patient', schedule.patient_code)"
                            >
                                <i :class="copiedField === 'patient' ? 'ti ti-check text-success' : 'ti ti-copy'"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-xs btn-outline-secondary"
                                :title="
                                    t.drawer_copy_patient_id_numeric ??
                                    'Copiar ID do paciente (só números) para o programa do aparelho'
                                "
                                @click="copyNumericCode('patient-num', schedule.patient_code)"
                            >
                                <i v-if="copiedField === 'patient-num'" class="ti ti-check text-success"></i>
                                <span v-else class="small">#</span>
                            </button>
                        </span>
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
                        <span class="detail-value d-flex align-items-center gap-1">
                            <code>{{ schedule.doctor_code }}</code>
                            <button
                                type="button"
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.drawer_copy_code ?? 'Copiar código'"
                                @click="copyCode('doctor', schedule.doctor_code)"
                            >
                                <i :class="copiedField === 'doctor' ? 'ti ti-check text-success' : 'ti ti-copy'"></i>
                            </button>
                        </span>
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
                    <!-- Legado: só aparece em agendamento antigo sem Tipo de consulta. -->
                    <div v-if="schedule.attendance_type_name && !schedule.visit_type_name" class="detail-row">
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
                        <span class="detail-label text-danger">{{
                            t.show_cancel_reason ?? 'Motivo do cancelamento'
                        }}</span>
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
                            <span
                                v-if="schedule.cellphone_whatsapp"
                                class="badge bg-success-subtle text-success border border-success ms-1 rounded-pill"
                                style="font-size: 0.65rem"
                            >
                                WhatsApp
                            </span>
                            <span
                                v-if="schedule.whatsapp_opted_out"
                                class="badge bg-warning-subtle text-warning-emphasis border border-warning ms-1 rounded-pill"
                                style="font-size: 0.65rem"
                                :title="t.drawer_wa_opt_out_hint"
                                data-test="whatsapp-opted-out"
                            >
                                <i class="ti ti-bell-off"></i> {{ t.drawer_wa_opt_out }}
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
                        <i class="ti ti-circle-dot text-primary mt-1" style="font-size: 0.5rem"></i>
                        <div class="flex-grow-1">
                            <div class="fw-medium small">{{ r.name }}</div>
                            <div v-if="r.type || r.code" class="text-muted" style="font-size: 0.75rem">
                                <span v-if="r.type">{{ r.type }}</span>
                                <span v-if="r.type && r.code"> · </span>
                                <code v-if="r.code">{{ r.code }}</code>
                            </div>
                            <div v-if="r.description" class="text-muted small">{{ r.description }}</div>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Série de sessões -->
            <div v-if="schedule.series && schedule.series.length" class="detail-section" data-test="drawer-series">
                <div class="detail-section__title d-flex align-items-center justify-content-between gap-2">
                    <span><i class="ti ti-repeat me-1"></i> {{ t.series_title ?? 'Série de sessões' }}</span>
                    <button
                        v-if="schedule.series_cancellable > 0 && !cancelSeriesOpen"
                        type="button"
                        class="btn btn-xs btn-outline-danger"
                        data-test="series-cancel-open"
                        @click="cancelSeriesOpen = true"
                    >
                        <i class="ti ti-calendar-x me-1"></i
                        >{{
                            (t.series_cancel_future ?? 'Cancelar as futuras (:count)').replace(
                                ':count',
                                schedule.series_cancellable,
                            )
                        }}
                    </button>
                </div>

                <div v-if="cancelSeriesOpen" class="border rounded p-2 mb-2 series-cancel-box">
                    <label for="series-cancel-reason" class="form-label small fw-semibold mb-1">
                        {{ t.series_cancel_reason ?? 'Motivo do cancelamento' }} <span class="text-danger">*</span>
                    </label>
                    <textarea
                        id="series-cancel-reason"
                        v-model="cancelReason"
                        class="form-control form-control-sm"
                        rows="2"
                        :class="{ 'is-invalid': cancelError }"
                        :placeholder="t.series_cancel_reason_ph ?? 'Ex.: tratamento encerrado'"
                    ></textarea>
                    <div v-if="cancelError" class="invalid-feedback d-block">{{ cancelError }}</div>
                    <p class="text-muted small mb-2 mt-1">
                        {{
                            t.series_cancel_hint ??
                            'Cancela só as sessões que ainda não aconteceram; passadas e atendidas ficam como estão.'
                        }}
                    </p>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-sm btn-light" @click="cancelSeriesOpen = false">
                            {{ t.series_cancel_back ?? 'Voltar' }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-danger"
                            :disabled="cancelSaving || !cancelReason.trim()"
                            data-test="series-cancel-confirm"
                            @click="cancelFutureSessions"
                        >
                            <span v-if="cancelSaving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ t.series_cancel_confirm ?? 'Cancelar sessões futuras' }}
                        </button>
                    </div>
                </div>

                <ul class="list-unstyled mb-0 series-list">
                    <li
                        v-for="session in schedule.series"
                        :key="session.id"
                        class="d-flex align-items-center gap-2 py-1 series-item"
                        :class="{ 'is-current': session.is_current, 'is-terminal': session.is_terminal }"
                    >
                        <span class="series-number">{{ session.number }}/{{ session.total }}</span>
                        <span class="flex-grow-1 small">
                            {{ session.date_label }}
                            <span v-if="session.is_current" class="text-primary fw-semibold ms-1"
                                >· {{ t.series_current ?? 'esta' }}</span
                            >
                        </span>
                        <span class="badge" :class="session.badge" style="font-size: 0.65rem">{{ session.label }}</span>
                        <button
                            v-if="!session.is_terminal && !session.is_past"
                            type="button"
                            class="btn btn-xs btn-outline-secondary"
                            :title="t.btn_reschedule ?? 'Reagendar'"
                            :aria-label="`${t.btn_reschedule ?? 'Reagendar'} ${session.number}/${session.total}`"
                            @click="rescheduleSession(session)"
                        >
                            <i class="fas fa-calendar-alt"></i>
                        </button>
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
                                    <span
                                        v-if="log.from_label"
                                        class="badge"
                                        :class="log.from_badge"
                                        style="font-size: 0.65rem"
                                    >
                                        {{ log.from_label }}
                                    </span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <span class="badge" :class="log.to_badge" style="font-size: 0.65rem">
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
.min-width-0 {
    min-width: 0;
}
.text-prewrap {
    white-space: pre-wrap;
}
.detail-section {
    margin-bottom: 1.5rem;
}
.detail-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.detail-table {
    display: grid;
    gap: 0.375rem;
}
.detail-row {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.detail-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.detail-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
.drawer-fit-in {
    background: #ede9fe;
    color: #5b21b6;
    border: 1px solid #c4b5fd;
    font-size: 0.7rem;
}
.drawer-urgent {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
    font-size: 0.7rem;
}
.drawer-session {
    background: #e0f2fe;
    color: #075985;
    border: 1px solid #bae6fd;
    font-size: 0.7rem;
}
.series-number {
    min-width: 3rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.series-item {
    border-bottom: 1px dashed var(--bs-border-color);
}
.series-item.is-current {
    background: rgba(var(--bs-primary-rgb), 0.06);
}
.series-item.is-terminal .small {
    color: var(--bs-secondary-color);
}
[data-bs-theme='dark'] .drawer-fit-in {
    background: rgba(139, 92, 246, 0.2);
    color: #ddd6fe;
    border-color: rgba(167, 139, 250, 0.5);
}
[data-bs-theme='dark'] .drawer-urgent {
    background: rgba(239, 68, 68, 0.2);
    color: #fecaca;
    border-color: rgba(248, 113, 113, 0.5);
}
[data-bs-theme='dark'] .drawer-session {
    background: rgba(14, 165, 233, 0.18);
    color: #bae6fd;
    border-color: rgba(56, 189, 248, 0.45);
}
@media (max-width: 575.98px) {
    .detail-row {
        grid-template-columns: 1fr;
        gap: 0.1rem;
    }
}
</style>
