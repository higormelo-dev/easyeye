<script setup>
/**
 * SlotPicker — seletor visual de horários para agendamento.
 *
 * Responsabilidades:
 *   - Navegação de data (prev/next/input direto)
 *   - Busca de slots via GET /panel/schedules/slots
 *   - Exibição da grade de horários com estado disponível/ocupado
 *   - Fallback de horários livres quando o médico não tem escala
 *   - Override manual via datetime-local
 *   - Polling de 15s para refletir mudanças de outros usuários em tempo real
 *
 * Interface pública (v-model + eventos):
 *   v-model               → datetime selecionado (string "YYYY-MM-DDTHH:mm")
 *   @slot-selected        → { datetime } — disparado ao confirmar slot
 *
 * Expõe via template ref:
 *   reset(date?)          → reinicia estado (novo agendamento)
 *   setDate(date)         → navega para data específica (modo edição)
 */
import { ref, watch, computed, onMounted, onUnmounted, nextTick } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    doctorId: { type: String, default: '' },
    scheduleId: { type: String, default: null },
    // Encaixe: horários ocupados também podem ser escolhidos (paciente a
    // mais) e o horário fora da grade (manual) não é descartado.
    allowOccupied: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const emit = defineEmits(['update:modelValue', 'slot-selected']);

// ── Internal state ─────────────────────────────────────────────────────────────
// Edição: já nasce na data do horário salvo (senão a 1ª busca era a de hoje).
const selectedDate = ref(
    props.modelValue && props.modelValue.length >= 10
        ? props.modelValue.substring(0, 10)
        : new Date().toISOString().substring(0, 10),
);
const slots = ref([]);
const slotsLoading = ref(false);
const hasSchedule = ref(false);
const slotInterval = ref(15);
const showManualTime = ref(false);
// Encaixes do dia por turno (servidor): { morning: { limit, used }, ... }
const fitInUsage = ref(null);

// ── CSRF ───────────────────────────────────────────────────────────────────────
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// ── Fallback: grade de horários quando médico não tem escala configurada ───────
function generateFallback(date, interval) {
    const result = [];
    let min = 7 * 60;
    while (min < 19 * 60) {
        const h = String(Math.floor(min / 60)).padStart(2, '0');
        const m = String(min % 60).padStart(2, '0');
        const time = `${h}:${m}`;
        result.push({ time, datetime: `${date}T${time}`, available: true });
        min += interval;
    }
    return result;
}

// ── Busca de slots ─────────────────────────────────────────────────────────────
async function fetchSlots() {
    if (!props.doctorId || !selectedDate.value) {
        slots.value = [];
        return;
    }

    slotsLoading.value = true;
    const date = selectedDate.value;
    try {
        const qs = new URLSearchParams({ doctor_id: props.doctorId, date });
        if (props.scheduleId) qs.set('schedule_id', props.scheduleId);

        const res = await fetch(`/panel/schedules/slots?${qs}`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });

        if (!res.ok) throw new Error('slots-fetch-error');

        const json = await res.json();
        // Resposta de uma data que já não é a exibida (navegou no meio): ignora.
        if (date !== selectedDate.value) return;
        slotInterval.value = json.interval ?? 15;
        hasSchedule.value = json.has_schedule ?? false;
        fitInUsage.value = json.fit_in ?? null;

        slots.value = json.has_schedule
            ? (json.slots ?? [])
            : generateFallback(selectedDate.value, json.interval ?? 15);

        // Slot selecionado deixou de existir (outro usuário agendou no meio-tempo)
        // — só vale para horário DESTA data (o de edição de outro dia não some).
        // Encaixe: horário ocupado/fora da grade é justamente o caso — fica.
        if (
            !props.allowOccupied &&
            props.modelValue &&
            props.modelValue.startsWith(date) &&
            !slots.value.some((s) => s.datetime === props.modelValue)
        ) {
            emit('update:modelValue', '');
        }
    } catch {
        slots.value = [];
    } finally {
        slotsLoading.value = false;
    }
}

// ── Seleção de slot ────────────────────────────────────────────────────────────
function selectable(slot) {
    return slot.available || props.allowOccupied || props.modelValue === slot.datetime;
}

function selectSlot(slot) {
    if (!selectable(slot)) return;
    showManualTime.value = false;
    emit('update:modelValue', slot.datetime);
    emit('slot-selected', { datetime: slot.datetime });
}

function clearSlot() {
    emit('update:modelValue', '');
}

function onManualInput(e) {
    const val = e.target.value;
    emit('update:modelValue', val);
    if (val) emit('slot-selected', { datetime: val });
}

// ── Navegação de data ──────────────────────────────────────────────────────────
function addDays(n) {
    const dt = new Date(selectedDate.value + 'T12:00:00');
    dt.setDate(dt.getDate() + n);
    selectedDate.value = dt.toISOString().substring(0, 10);
    clearSlot();
}

// ── Classe do botão de slot ────────────────────────────────────────────────────
function slotBtnClass(slot) {
    if (props.modelValue === slot.datetime) return 'btn-primary';
    if (!slot.available && props.allowOccupied) return 'btn-outline-warning slot-occupied-fit';
    if (!slot.available) return 'btn-secondary opacity-50';
    return 'btn-outline-primary';
}

function slotTitle(slot) {
    if (slot.available) return slot.time;
    if (props.allowOccupied)
        return `${slot.time} — ${props.t.form_slot_occupied_fit_in ?? 'ocupado: entra como encaixe'}`;
    return props.modelValue === slot.datetime ? slot.time : props.t.form_slot_occupied;
}

// "Encaixes: manhã 1 de 2 · tarde 0 de 2" (turno sem encaixe: "não aceita").
const fitInSummary = computed(() => {
    if (!props.allowOccupied || !fitInUsage.value) return [];
    return ['morning', 'afternoon', 'evening'].map((shift) => {
        const usage = fitInUsage.value[shift] ?? { limit: 0, used: 0 };
        const label = props.t[`shift_${shift}`] ?? shift;
        const text =
            usage.limit > 0
                ? (props.t.fit_in_usage ?? ':used de :limit')
                      .replace(':used', usage.used)
                      .replace(':limit', usage.limit)
                : (props.t.fit_in_usage_none ?? 'não aceita');
        return { shift, label, text, full: usage.limit > 0 && usage.used >= usage.limit, none: usage.limit <= 0 };
    });
});

// ── Label do horário selecionado ───────────────────────────────────────────────
const selectedLabel = computed(() => {
    if (!props.modelValue) return '';
    try {
        const locale = window.sessionLocale ?? 'pt-BR';
        const raw = props.modelValue.length === 16 ? props.modelValue + ':00' : props.modelValue;
        const dt = new Date(raw);
        const d = dt.toLocaleDateString(locale, { weekday: 'short', day: '2-digit', month: 'short' });
        const h = dt.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
        return `${d} · ${h}`;
    } catch {
        return props.modelValue;
    }
});

// ── Watchers ───────────────────────────────────────────────────────────────────
watch(
    () => props.doctorId,
    (newId, oldId) => {
        if (newId === oldId) return;
        clearSlot();
        fetchSlots();
    },
);

// Troca de data vinda do próprio v-model (modo edição: o agendamento é de
// outro dia) NÃO limpa o horário — antes limpava, e "Editar" só salvava se a
// recepção escolhesse o horário de novo (422 "data e hora obrigatória").
let keepSlotOnDateChange = false;

watch(selectedDate, () => {
    if (keepSlotOnDateChange) {
        keepSlotOnDateChange = false;
    } else {
        clearSlot();
    }
    fetchSlots();
});

// Modo edição: pai define modelValue com datetime pré-existente.
// Sincroniza a data de navegação para exibir o slot correto.
watch(
    () => props.modelValue,
    (val) => {
        if (val && val.length >= 10) {
            const date = val.substring(0, 10);
            if (date !== selectedDate.value) {
                // Atualiza data sem disparar clearSlot (o valor já está definido)
                keepSlotOnDateChange = true;
                selectedDate.value = date;
            }
        }
    },
    { immediate: false },
);

// ── Polling de 15 s — porta o comportamento do Alpine original ─────────────────
let pollTimer = null;

onMounted(() => {
    fetchSlots();
    pollTimer = setInterval(() => {
        if (props.doctorId && selectedDate.value) fetchSlots();
    }, 15_000);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
});

// ── API pública via template ref ───────────────────────────────────────────────
defineExpose({
    /** Reinicia para novo agendamento. */
    reset(date) {
        selectedDate.value = date || new Date().toISOString().substring(0, 10);
        slots.value = [];
        showManualTime.value = false;
        nextTick(fetchSlots);
    },
    /** Navega para uma data específica sem limpar o valor selecionado (modo edição). */
    setDate(date) {
        if (date && date !== selectedDate.value) {
            keepSlotOnDateChange = true;
            selectedDate.value = date;
        }
    },
});
</script>

<template>
    <div class="slot-picker">
        <!-- ── Seletor de data com navegação prev/next ─────────────────────── -->
        <div class="input-group input-group-sm mb-2">
            <button type="button" class="btn btn-outline-secondary" :disabled="!selectedDate" @click="addDays(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>

            <input v-model="selectedDate" type="date" class="form-control text-center fw-semibold" />

            <button type="button" class="btn btn-outline-secondary" :disabled="!selectedDate" @click="addDays(1)">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>

        <!-- ── Mensagens de guia ───────────────────────────────────────────── -->
        <p v-if="!doctorId" class="text-muted small mb-1">
            <i class="fas fa-user-md me-1"></i>{{ t.form_select_doctor_first }}
        </p>

        <p v-else-if="!selectedDate" class="text-muted small mb-1">
            <i class="fas fa-calendar me-1"></i>{{ t.form_select_date_first }}
        </p>

        <!-- ── Carregando ──────────────────────────────────────────────────── -->
        <div v-else-if="slotsLoading" class="py-1 d-flex align-items-center gap-2">
            <span class="spinner-border spinner-border-sm text-primary"></span>
            <span class="text-muted small">{{ t.form_loading_slots }}</span>
        </div>

        <!-- ── Grade de horários ───────────────────────────────────────────── -->
        <template v-else-if="slots.length > 0">
            <div class="d-flex flex-wrap gap-1 mt-1">
                <button
                    v-for="slot in slots"
                    :key="slot.datetime"
                    type="button"
                    class="btn btn-sm px-2 py-1"
                    style="font-size: 0.78rem; min-width: 52px"
                    :class="slotBtnClass(slot)"
                    :disabled="!selectable(slot)"
                    :title="slotTitle(slot)"
                    @click="selectSlot(slot)"
                >
                    {{ slot.time }}
                </button>
            </div>

            <small class="text-muted mt-2 d-block">
                <i class="fas fa-info-circle me-1"></i>
                {{ t.form_interval_hint?.replace(':min', slotInterval) ?? `Intervalo de ${slotInterval} min` }}
                <span v-if="!hasSchedule"> — {{ t.form_no_scale }}</span>
            </small>
        </template>

        <!-- ── Encaixes do dia por turno (modo encaixe) ───────────────────── -->
        <div v-if="fitInSummary.length" class="small mt-2 d-flex flex-wrap gap-2" data-test="fit-in-usage">
            <span class="text-muted">{{ t.fit_in_usage_title ?? 'Encaixes no dia:' }}</span>
            <span
                v-for="row in fitInSummary"
                :key="row.shift"
                class="badge rounded-pill border fit-in-usage-pill"
                :class="
                    row.full || row.none
                        ? 'text-danger border-danger-subtle bg-danger-subtle'
                        : 'text-body bg-body-tertiary'
                "
            >
                {{ row.label }}: {{ row.text }}
            </span>
        </div>

        <!-- ── Sem slots disponíveis ───────────────────────────────────────── -->
        <p v-else class="text-muted small mb-1"><i class="fas fa-clock me-1"></i>{{ t.form_no_slots }}</p>

        <!-- ── Pill do horário selecionado ────────────────────────────────── -->
        <div
            v-if="modelValue && !showManualTime"
            class="mt-2 alert alert-primary py-1 px-2 d-flex align-items-center gap-2 small mb-1 rounded"
        >
            <i class="fas fa-check-circle flex-shrink-0"></i>
            <span class="fw-semibold">{{ selectedLabel }}</span>
            <button
                type="button"
                class="btn-close ms-auto"
                style="font-size: 0.6rem"
                aria-label="Limpar horário"
                @click="clearSlot"
            ></button>
        </div>

        <!-- ── Override manual ────────────────────────────────────────────── -->
        <div class="mt-2">
            <button
                type="button"
                class="btn btn-link btn-sm p-0 text-muted text-decoration-none"
                style="font-size: 0.78rem"
                @click="showManualTime = !showManualTime"
            >
                <i class="fas fa-pencil-alt me-1"></i>{{ t.form_manual_override }}
            </button>

            <div v-if="showManualTime" class="mt-1">
                <input
                    :value="modelValue"
                    type="datetime-local"
                    class="form-control form-control-sm"
                    @input="onManualInput"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.slot-occupied-fit {
    border-style: dashed;
}

.fit-in-usage-pill {
    font-size: 0.75rem;
    font-weight: 500;
}
</style>
