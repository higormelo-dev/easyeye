<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { formatPatientCode } from '@/utils/formatPatientCode.js';

const props = defineProps({
    item: { type: Object, required: true },
    isStaff: { type: Boolean, default: false },
    isDoctor: { type: Boolean, default: false },
    canRegisterCash: { type: Boolean, default: false },
    // Painel de chamadas (TV da sala de espera) habilitado nesta clínica
    callPanelEnabled: { type: Boolean, default: false },
    situations: { type: Array, default: () => [] },
    moods: { type: Array, default: () => [] },
    selectionMode: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
    // Ficha do paciente (impressão) — mesma regra de acesso de Pacientes.
    canSeePatients: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

// App\Enums\ScheduleSituation::Cancelled->value — cancelar segue passando
// pelo CancelModal (motivo opcional), mesmo valor hardcoded lá.
const CANCELLED_VALUE = 9;

const emit = defineEmits([
    'toggle-select',
    'view',
    'edit',
    'reschedule',
    'cancel',
    'change-situation',
    'change-mood',
    'register-cash',
    'call',
]);

// ── WhatsApp (só vem para quem tem acesso a Pacientes) ───────────────────────
// ok → abre a conversa (wa.me); opted_out → respondeu SAIR; no_number → sem celular.
const whatsappTitle = computed(() => {
    switch (props.item.whatsapp_state) {
        case 'ok':
            return props.t.whatsapp_open ?? 'Abrir conversa no WhatsApp';
        case 'opted_out':
            return props.t.whatsapp_opted_out_hint ?? 'O paciente respondeu SAIR: não envie mensagens pelo WhatsApp.';
        default:
            return props.t.whatsapp_no_number ?? 'Sem celular cadastrado para WhatsApp.';
    }
});

const sessionLabel = computed(() =>
    props.item.session_total ? `${props.item.session_number}/${props.item.session_total}` : null,
);

const patientSheetUrl = computed(() =>
    props.item.patient_id && props.canSeePatients ? route('panel.schedules.patient-sheet', props.item.id) : null,
);

// ── Waiting timer ──────────────────────────────────────────────────────────────
const waitingTime = ref('');
let timerInterval = null;

function updateWaiting() {
    if (props.item.situation !== 3 || !props.item.arrived_at) {
        waitingTime.value = '';
        return;
    }
    const diff = Math.floor((Date.now() - new Date(props.item.arrived_at).getTime()) / 1000);
    const h = Math.floor(diff / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const s = diff % 60;
    waitingTime.value =
        h > 0
            ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
            : `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

onMounted(() => {
    if (props.item.situation === 3 && props.item.arrived_at) {
        updateWaiting();
        timerInterval = setInterval(updateWaiting, 1000);
    }
});

onUnmounted(() => clearInterval(timerInterval));

function onSituationClick(trans) {
    if (trans.value === props.item.situation) return; // já é a situação atual — no-op

    if (trans.value === CANCELLED_VALUE) {
        emit('cancel', props.item);
    } else {
        emit('change-situation', { item: props.item, to: trans.value });
    }
}
</script>

<template>
    <div
        class="card mb-2 border schedule-card"
        :class="{
            'opacity-65 bg-light': item.is_terminal,
            'border-primary bg-primary-subtle': selectionMode && selected,
        }"
        :style="{ borderLeftColor: item.doctor_color + ' !important' }"
        @click="selectionMode && isStaff ? emit('toggle-select', item.id) : null"
    >
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-3 schedule-card-row">
                <!-- Bulk checkbox -->
                <div v-if="isStaff && selectionMode" class="flex-shrink-0">
                    <input
                        type="checkbox"
                        class="form-check-input"
                        style="width: 1.2rem; height: 1.2rem; cursor: pointer"
                        :checked="selected"
                        @click.stop="emit('toggle-select', item.id)"
                    />
                </div>

                <!-- Doctor color bar indicator (mobile) -->
                <div
                    class="d-none d-md-flex flex-shrink-0 align-items-center justify-content-center rounded-circle"
                    style="width: 42px; height: 42px"
                    :style="{ background: item.doctor_color + '22', border: `2px solid ${item.doctor_color}` }"
                >
                    <i class="fas fa-user" :style="{ color: item.doctor_color }"></i>
                </div>

                <!-- Info -->
                <div class="flex-grow-1 schedule-card-info min-w-0">
                    <!-- Row 1: time + name + code -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <strong class="fs-6" :style="{ color: item.doctor_color }">{{ item.time }}</strong>
                        <span class="fw-semibold">{{ item.name }}</span>
                        <small v-if="item.code" class="text-muted" :title="item.code"
                            >({{ formatPatientCode(item.code) }})</small
                        >
                    </div>

                    <!-- Row 2: metadata -->
                    <div class="text-muted small mt-1">
                        {{ [item.visit_name, item.specialty_area_name].filter(Boolean).join(' · ') || '—' }}
                        &mdash;
                        {{ item.covenant_name ?? '—' }}
                        <template v-if="!isDoctor"> &mdash; {{ item.doctor_name }} </template>
                    </div>

                    <!-- Row 3: badges -->
                    <div class="d-flex align-items-center gap-1 flex-wrap mt-1">
                        <span class="badge" :class="item.badge">
                            <i class="fas me-1" :class="item.icon"></i>{{ item.label }}
                        </span>

                        <span
                            v-if="item.confirmed_at"
                            class="badge bg-info text-dark"
                            :title="t.show_confirmed_at + ' ' + item.confirmed_at"
                        >
                            <i class="fas fa-check-circle"></i>
                        </span>

                        <!-- Encaixe: selo com texto (não só cor) -->
                        <span
                            v-if="item.is_fit_in"
                            class="badge schedule-fit-in-badge"
                            :title="t.fit_in_hint ?? 'Encaixe: paciente a mais neste turno'"
                            data-test="fit-in-badge"
                        >
                            <i class="ti ti-arrow-autofit-height me-1" aria-hidden="true"></i
                            >{{ t.fit_in_badge ?? 'Encaixe' }}
                        </span>

                        <!-- Pronto atendimento: fora da grade e do teto de encaixes -->
                        <span
                            v-if="item.is_urgent_care"
                            class="badge schedule-urgent-badge"
                            :title="t.urgent_care_hint ?? 'Atendimento do pronto atendimento'"
                            data-test="urgent-care-badge"
                        >
                            <i class="ti ti-urgent me-1" aria-hidden="true"></i
                            >{{ t.urgent_care_badge ?? 'Pronto atendimento' }}
                        </span>

                        <!-- Sessão da série ("3/10") -->
                        <span
                            v-if="sessionLabel"
                            class="badge schedule-session-badge"
                            :title="(t.session_label ?? 'Sessão :session').replace(':session', sessionLabel)"
                            data-test="session-badge"
                        >
                            <i class="ti ti-repeat me-1" aria-hidden="true"></i>{{ sessionLabel }}
                        </span>

                        <!-- Sala/equipamento reservado -->
                        <span
                            v-for="room in item.resource_names ?? []"
                            :key="room"
                            class="badge bg-light text-secondary border"
                            :title="t.form_resources ?? 'Sala / equipamento'"
                        >
                            <i class="ti ti-door me-1" aria-hidden="true"></i>{{ room }}
                        </span>

                        <span v-if="item.notes" class="badge bg-light text-secondary border" :title="item.notes">
                            <i class="fas fa-sticky-note"></i>
                        </span>

                        <span
                            v-if="item.patient_mood"
                            class="badge"
                            :class="item.patient_mood.badge"
                            :title="item.patient_mood.label"
                        >
                            <i class="fas" :class="item.patient_mood.icon"></i>
                        </span>

                        <span
                            v-if="item.situation === 3 && item.arrived_at && waitingTime"
                            class="badge bg-warning text-dark"
                        >
                            <i class="fas fa-clock me-1"></i>{{ waitingTime }}
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="d-flex align-items-center flex-shrink-0 schedule-card-actions" style="gap: 8px" @click.stop>
                    <!-- Iniciar atendimento (Agenda do MÉDICO): ação principal —
                         abre o prontuário DESTE agendamento; o status vira
                         "Em consulta" na abertura (ScheduleFlowGuard). -->
                    <!-- Ícone-only por decisão de produto (29/08): o rótulo
                         cortava em cards comprimidos. Play do Tabler (mesma
                         família dos botões vizinhos) + tooltip acessível. -->
                    <a
                        v-if="isDoctor && item.attend_url && !item.is_terminal"
                        :href="item.attend_url"
                        class="btn btn-success btn-sm d-inline-flex align-items-center justify-content-center flex-shrink-0 px-2"
                        :title="t.btn_attend ?? 'Iniciar atendimento'"
                        :aria-label="t.btn_attend ?? 'Iniciar atendimento'"
                    >
                        <i class="ti ti-player-play fs-16"></i>
                    </a>

                    <!-- WhatsApp: abre a conversa (wa.me) — desabilitado com o
                         motivo quando o paciente respondeu SAIR ou não tem
                         celular. Só para quem tem acesso a Pacientes (o
                         servidor só manda o campo nesse caso). -->
                    <ActionIconButton
                        v-if="item.whatsapp_state"
                        icon="ti ti-brand-whatsapp"
                        :variant="item.whatsapp_state === 'ok' ? 'success' : 'default'"
                        :href="item.whatsapp_state === 'ok' ? item.whatsapp_url : null"
                        :target="item.whatsapp_state === 'ok' ? '_blank' : null"
                        :disabled="item.whatsapp_state !== 'ok'"
                        :title="whatsappTitle"
                        :data-whatsapp="item.whatsapp_state"
                    />

                    <!-- Chamar paciente (painel/TV da sala de espera) — opcional
                         por clínica (Config. > Painel de chamadas). -->
                    <ActionIconButton
                        v-if="callPanelEnabled && !item.is_terminal"
                        icon="fas fa-bullhorn"
                        :title="t.btn_call ?? 'Chamar paciente'"
                        @click="emit('call', item)"
                    />

                    <!-- Grupo 1: controles de estado (situação + humor) -->
                    <!-- AJUSTE: situação/humor editáveis a qualquer momento, inclusive
                         com agendamento terminal (Atendido/Faltou/Cancelado) — corrige
                         seleção por engano sem precisar recriar o agendamento.
                         Situação também pro MÉDICO (fluxo Dilatação/Exame manual);
                         humor segue só staff. -->
                    <ActionIconGroup
                        v-if="(isStaff || isDoctor) && (situations.length > 0 || item.patient_id)"
                        gap="tight"
                    >
                        <!-- Situation dropdown: todas as situações, sempre — nenhuma
                             some da lista, nenhuma fica "travada" por ser terminal. -->
                        <ActionDropdown
                            v-if="situations.length > 0"
                            icon="fas fa-list-ul"
                            btn-class="ee-action-icon ee-action-icon--default"
                            :title="t.dropdown_situation ?? 'Alterar situação'"
                        >
                            <li v-for="trans in situations" :key="trans.value">
                                <button
                                    type="button"
                                    class="dropdown-item d-flex align-items-center"
                                    :class="{ 'text-muted': trans.value === item.situation }"
                                    :disabled="trans.value === item.situation"
                                    @click="onSituationClick(trans)"
                                >
                                    <i class="fas fa-circle me-2" :class="trans.circle"></i>
                                    <span class="flex-grow-1">{{ trans.label.toUpperCase() }}</span>
                                    <i v-if="trans.value === item.situation" class="fas fa-check ms-2"></i>
                                </button>
                            </li>
                        </ActionDropdown>

                        <!-- Mood dropdown (recepção) -->
                        <ActionDropdown
                            v-if="isStaff && item.patient_id"
                            :icon="'fas ' + (item.patient_mood ? item.patient_mood.icon : 'fa-theater-masks')"
                            btn-class="ee-action-icon ee-action-icon--default"
                            :title="t.dropdown_mood ?? 'Humor do paciente'"
                        >
                            <li>
                                <button
                                    type="button"
                                    class="dropdown-item"
                                    @click="emit('change-mood', { item, mood: null })"
                                >
                                    <i class="fas fa-times text-muted me-2"></i> {{ t.dropdown_mood_clear ?? 'Limpar' }}
                                </button>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li v-for="m in moods" :key="m.value">
                                <button
                                    type="button"
                                    class="dropdown-item"
                                    :class="{ 'fw-bold': item.patient_mood?.value === m.value }"
                                    @click="emit('change-mood', { item, mood: m.value })"
                                >
                                    <i class="fas me-2" :class="[m.icon, m.text_class]"></i>
                                    {{ m.label }}
                                </button>
                            </li>
                        </ActionDropdown>
                    </ActionIconGroup>

                    <!-- Grupo 2: navegação/edição do agendamento -->
                    <ActionIconGroup gap="tight">
                        <!-- Caixa: registrar entrada (fluxo "Chegou -> caixa") -->
                        <ActionIconButton
                            v-if="canRegisterCash && !item.is_terminal"
                            icon="ti ti-cash-register"
                            :variant="item.has_cash_entry ? 'success' : 'default'"
                            :title="
                                item.has_cash_entry
                                    ? (t.cash_already_registered ?? 'Entrada já lançada no caixa')
                                    : (t.cash_register ?? 'Registrar entrada no caixa')
                            "
                            @click="emit('register-cash', item)"
                        />

                        <!-- Edit — função administrativa: fora da Agenda do médico
                             (remarcação/organização é papel da secretaria). -->
                        <ActionIconButton
                            v-if="isStaff && !item.is_terminal"
                            icon="fas fa-edit"
                            :title="t.btn_edit ?? 'Editar'"
                            @click="emit('edit', item)"
                        />

                        <!-- Patient record -->
                        <ActionIconButton
                            v-if="item.patient_url"
                            icon="fas fa-address-card"
                            :title="t.btn_patient ?? 'Cadastro do paciente'"
                            :href="item.patient_url"
                        />

                        <!-- Medical records -->
                        <ActionIconButton
                            v-if="item.medical_records_url"
                            icon="fas fa-file-medical"
                            :title="t.btn_medical_records ?? 'Prontuário'"
                            variant="info"
                            :href="item.medical_records_url"
                        />

                        <!-- View (histórico do agendamento) — pro médico o
                             histórico clínico relevante já vive no prontuário
                             (resumo de consultas anteriores). -->
                        <ActionIconButton
                            v-if="isStaff"
                            icon="fas fa-eye"
                            :title="t.btn_view ?? 'Visualizar'"
                            @click="emit('view', item)"
                        />

                        <!-- More (reschedule) -->
                        <ActionDropdown
                            v-if="!item.is_terminal && isStaff"
                            icon="fas fa-ellipsis-v"
                            btn-class="ee-action-icon ee-action-icon--default"
                            :title="t.dropdown_more ?? 'Mais ações'"
                        >
                            <li>
                                <button type="button" class="dropdown-item" @click="emit('reschedule', item)">
                                    <i class="fas fa-calendar-alt me-2"></i>{{ t.btn_reschedule ?? 'Reagendar' }}
                                </button>
                            </li>
                            <li v-if="patientSheetUrl">
                                <a class="dropdown-item" :href="patientSheetUrl" target="_blank" rel="noopener">
                                    <i class="ti ti-printer me-2"></i
                                    >{{ t.print_patient_sheet ?? 'Imprimir ficha do paciente' }}
                                </a>
                            </li>
                        </ActionDropdown>
                    </ActionIconGroup>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Celular: as ações descem para baixo das informações (antes espremiam o
   nome do paciente numa coluna de poucas letras). */
@media (max-width: 575.98px) {
    .schedule-card-row {
        flex-wrap: wrap;
        row-gap: 0.5rem !important;
    }

    .schedule-card-actions {
        width: 100%;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
}

.schedule-fit-in-badge {
    background: #ede9fe;
    color: #5b21b6;
    border: 1px solid #c4b5fd;
}

.schedule-urgent-badge {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.schedule-session-badge {
    background: #e0f2fe;
    color: #075985;
    border: 1px solid #bae6fd;
}

[data-bs-theme='dark'] .schedule-fit-in-badge {
    background: rgba(139, 92, 246, 0.2);
    color: #ddd6fe;
    border-color: rgba(167, 139, 250, 0.5);
}

[data-bs-theme='dark'] .schedule-urgent-badge {
    background: rgba(239, 68, 68, 0.2);
    color: #fecaca;
    border-color: rgba(248, 113, 113, 0.5);
}

[data-bs-theme='dark'] .schedule-session-badge {
    background: rgba(14, 165, 233, 0.18);
    color: #bae6fd;
    border-color: rgba(56, 189, 248, 0.45);
}
</style>
