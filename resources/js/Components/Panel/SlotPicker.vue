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
    modelValue: { type: String,  default: '' },
    doctorId:   { type: String,  default: '' },
    scheduleId: { type: String,  default: null },
    t:          { type: Object,  required: true },
});

const emit = defineEmits(['update:modelValue', 'slot-selected']);

// ── Internal state ─────────────────────────────────────────────────────────────
const selectedDate   = ref(new Date().toISOString().substring(0, 10));
const slots          = ref([]);
const slotsLoading   = ref(false);
const hasSchedule    = ref(false);
const slotInterval   = ref(15);
const showManualTime = ref(false);

// ── CSRF ───────────────────────────────────────────────────────────────────────
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// ── Fallback: grade de horários quando médico não tem escala configurada ───────
function generateFallback(date, interval) {
    const result = [];
    let min = 7 * 60;
    while (min < 19 * 60) {
        const h    = String(Math.floor(min / 60)).padStart(2, '0');
        const m    = String(min % 60).padStart(2, '0');
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
    try {
        const qs = new URLSearchParams({ doctor_id: props.doctorId, date: selectedDate.value });
        if (props.scheduleId) qs.set('schedule_id', props.scheduleId);

        const res = await fetch(`/panel/schedules/slots?${qs}`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });

        if (!res.ok) throw new Error('slots-fetch-error');

        const json         = await res.json();
        slotInterval.value = json.interval ?? 15;
        hasSchedule.value  = json.has_schedule ?? false;

        slots.value = json.has_schedule
            ? (json.slots ?? [])
            : generateFallback(selectedDate.value, json.interval ?? 15);

        // Slot selecionado deixou de existir (outro usuário agendou no meio-tempo)
        if (props.modelValue && !slots.value.some(s => s.datetime === props.modelValue)) {
            emit('update:modelValue', '');
        }
    } catch {
        slots.value = [];
    } finally {
        slotsLoading.value = false;
    }
}

// ── Seleção de slot ────────────────────────────────────────────────────────────
function selectSlot(slot) {
    if (!slot.available && props.modelValue !== slot.datetime) return;
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
    if (!slot.available) return 'btn-secondary opacity-50';
    return 'btn-outline-primary';
}

// ── Label do horário selecionado ───────────────────────────────────────────────
const selectedLabel = computed(() => {
    if (!props.modelValue) return '';
    try {
        const locale = window.sessionLocale ?? 'pt-BR';
        const raw    = props.modelValue.length === 16 ? props.modelValue + ':00' : props.modelValue;
        const dt     = new Date(raw);
        const d      = dt.toLocaleDateString(locale, { weekday: 'short', day: '2-digit', month: 'short' });
        const h      = dt.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
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

watch(selectedDate, () => {
    clearSlot();
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
        selectedDate.value   = date || new Date().toISOString().substring(0, 10);
        slots.value          = [];
        showManualTime.value = false;
        nextTick(fetchSlots);
    },
    /** Navega para uma data específica sem limpar o valor selecionado (modo edição). */
    setDate(date) {
        if (date && date !== selectedDate.value) {
            selectedDate.value = date;
        }
    },
});
</script>

<template>
    <div class="slot-picker">

        <!-- ── Seletor de data com navegação prev/next ─────────────────────── -->
        <div class="input-group input-group-sm mb-2">
            <button type="button"
                    class="btn btn-outline-secondary"
                    :disabled="!selectedDate"
                    @click="addDays(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>

            <input v-model="selectedDate"
                   type="date"
                   class="form-control text-center fw-semibold">

            <button type="button"
                    class="btn btn-outline-secondary"
                    :disabled="!selectedDate"
                    @click="addDays(1)">
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
        <div v-else-if="slotsLoading"
             class="py-1 d-flex align-items-center gap-2">
            <span class="spinner-border spinner-border-sm text-primary"></span>
            <span class="text-muted small">{{ t.form_loading_slots }}</span>
        </div>

        <!-- ── Grade de horários ───────────────────────────────────────────── -->
        <template v-else-if="slots.length > 0">
            <div class="d-flex flex-wrap gap-1 mt-1">
                <button v-for="slot in slots"
                        :key="slot.datetime"
                        type="button"
                        class="btn btn-sm px-2 py-1"
                        style="font-size:.78rem; min-width:52px;"
                        :class="slotBtnClass(slot)"
                        :disabled="!slot.available && modelValue !== slot.datetime"
                        :title="!slot.available && modelValue !== slot.datetime
                                    ? t.form_slot_occupied
                                    : slot.time"
                        @click="selectSlot(slot)">
                    {{ slot.time }}
                </button>
            </div>

            <small class="text-muted mt-2 d-block">
                <i class="fas fa-info-circle me-1"></i>
                {{ t.form_interval_hint?.replace(':min', slotInterval) ?? `Intervalo de ${slotInterval} min` }}
                <span v-if="!hasSchedule"> — {{ t.form_no_scale }}</span>
            </small>
        </template>

        <!-- ── Sem slots disponíveis ───────────────────────────────────────── -->
        <p v-else class="text-muted small mb-1">
            <i class="fas fa-clock me-1"></i>{{ t.form_no_slots }}
        </p>

        <!-- ── Pill do horário selecionado ────────────────────────────────── -->
        <div v-if="modelValue && !showManualTime"
             class="mt-2 alert alert-primary py-1 px-2 d-flex align-items-center gap-2 small mb-1 rounded">
            <i class="fas fa-check-circle flex-shrink-0"></i>
            <span class="fw-semibold">{{ selectedLabel }}</span>
            <button type="button"
                    class="btn-close ms-auto"
                    style="font-size:.6rem;"
                    aria-label="Limpar horário"
                    @click="clearSlot"></button>
        </div>

        <!-- ── Override manual ────────────────────────────────────────────── -->
        <div class="mt-2">
            <button type="button"
                    class="btn btn-link btn-sm p-0 text-muted text-decoration-none"
                    style="font-size:.78rem;"
                    @click="showManualTime = !showManualTime">
                <i class="fas fa-pencil-alt me-1"></i>{{ t.form_manual_override }}
            </button>

            <div v-if="showManualTime" class="mt-1">
                <input :value="modelValue"
                       type="datetime-local"
                       class="form-control form-control-sm"
                       @input="onManualInput">
            </div>
        </div>

    </div>
</template>
