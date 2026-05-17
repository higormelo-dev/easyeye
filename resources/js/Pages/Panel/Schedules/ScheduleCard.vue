<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    item:          { type: Object,  required: true },
    isStaff:       { type: Boolean, default: false },
    isDoctor:      { type: Boolean, default: false },
    moods:         { type: Array,   default: () => [] },
    selectionMode: { type: Boolean, default: false },
    selected:      { type: Boolean, default: false },
    t:             { type: Object,  required: true },
});

const emit = defineEmits([
    'toggle-select',
    'edit',
    'reschedule',
    'cancel',
    'change-situation',
    'change-mood',
]);

// ── Waiting timer ──────────────────────────────────────────────────────────────
const waitingTime = ref('');
let timerInterval = null;

function updateWaiting() {
    if (props.item.situation !== 3 || ! props.item.arrived_at) {
        waitingTime.value = '';
        return;
    }
    const diff = Math.floor((Date.now() - new Date(props.item.arrived_at).getTime()) / 1000);
    const h    = Math.floor(diff / 3600);
    const m    = Math.floor((diff % 3600) / 60);
    const s    = diff % 60;
    waitingTime.value = h > 0
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
    if (trans.is_cancel) {
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
            <div class="d-flex align-items-center gap-3">

                <!-- Bulk checkbox -->
                <div v-if="isStaff && selectionMode" class="flex-shrink-0">
                    <input
                        type="checkbox"
                        class="form-check-input"
                        style="width:1.2rem;height:1.2rem;cursor:pointer;"
                        :checked="selected"
                        @click.stop="emit('toggle-select', item.id)"
                    >
                </div>

                <!-- Doctor color bar indicator (mobile) -->
                <div class="d-none d-md-flex flex-shrink-0 align-items-center justify-content-center rounded-circle"
                     style="width:42px;height:42px;"
                     :style="{ background: item.doctor_color + '22', border: `2px solid ${item.doctor_color}` }">
                    <i class="fas fa-user" :style="{ color: item.doctor_color }"></i>
                </div>

                <!-- Info -->
                <div class="flex-grow-1 schedule-card-info min-w-0">

                    <!-- Row 1: time + name + code -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <strong class="fs-6" :style="{ color: item.doctor_color }">{{ item.time }}</strong>
                        <span class="fw-semibold">{{ item.name }}</span>
                        <small v-if="item.code" class="text-muted">({{ item.code }})</small>
                    </div>

                    <!-- Row 2: metadata -->
                    <div class="text-muted small mt-1">
                        {{ item.visit_name ?? '—' }}
                        &mdash;
                        {{ item.covenant_name ?? '—' }}
                        <template v-if="!isDoctor">
                            &mdash; {{ item.doctor_name }}
                        </template>
                    </div>

                    <!-- Row 3: badges -->
                    <div class="d-flex align-items-center gap-1 flex-wrap mt-1">
                        <span class="badge" :class="item.badge">
                            <i class="fas me-1" :class="item.icon"></i>{{ item.label }}
                        </span>

                        <span v-if="item.confirmed_at"
                              class="badge bg-info text-dark"
                              :title="t.show_confirmed_at + ' ' + item.confirmed_at">
                            <i class="fas fa-check-circle"></i>
                        </span>

                        <span v-if="item.notes"
                              class="badge bg-light text-secondary border"
                              :title="item.notes">
                            <i class="fas fa-sticky-note"></i>
                        </span>

                        <span v-if="item.patient_mood"
                              class="badge"
                              :class="item.patient_mood.badge"
                              :title="item.patient_mood.label">
                            <i class="fas" :class="item.patient_mood.icon"></i>
                        </span>

                        <span v-if="item.situation === 3 && item.arrived_at && waitingTime"
                              class="badge bg-warning text-dark">
                            <i class="fas fa-clock me-1"></i>{{ waitingTime }}
                        </span>
                    </div>

                </div>

                <!-- Actions -->
                <div class="d-flex align-items-center gap-1 flex-shrink-0" @click.stop>

                    <!-- Situation dropdown -->
                    <div v-if="!item.is_terminal && isStaff && item.allowed_transitions.length > 0" class="btn-group">
                        <button type="button"
                                class="btn btn-light btn-sm dropdown-toggle"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                            <i class="fas fa-list-ul"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li v-for="trans in item.allowed_transitions" :key="trans.value">
                                <button type="button"
                                        class="dropdown-item"
                                        @click="onSituationClick(trans)">
                                    <i class="fas fa-circle me-2"
                                       :class="'text-' + (trans.is_cancel ? 'dark' : 'secondary')"></i>
                                    {{ trans.label.toUpperCase() }}
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Mood dropdown -->
                    <div v-if="!item.is_terminal && isStaff && item.patient_id" class="dropdown">
                        <button type="button"
                                class="btn btn-light btn-sm dropdown-toggle"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                            <i class="fas"
                               :class="item.patient_mood ? item.patient_mood.icon : 'fa-theater-masks'"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <button type="button" class="dropdown-item"
                                        @click="emit('change-mood', { item, mood: null })">
                                    <i class="fas fa-times text-muted me-2"></i> Limpar
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li v-for="m in moods" :key="m.value">
                                <button type="button" class="dropdown-item"
                                        :class="{ 'fw-bold': item.patient_mood?.value === m.value }"
                                        @click="emit('change-mood', { item, mood: m.value })">
                                    <i class="fas me-2" :class="[m.icon, m.text_class]"></i>
                                    {{ m.label }}
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div v-if="!item.is_terminal" class="vr mx-1 d-none d-sm-block"></div>

                    <!-- Edit -->
                    <button v-if="!item.is_terminal"
                            type="button"
                            class="btn btn-light btn-sm"
                            @click="emit('edit', item)">
                        <i class="fas fa-edit"></i>
                    </button>

                    <!-- Patient record -->
                    <a v-if="item.patient_url"
                       :href="item.patient_url"
                       class="btn btn-light btn-sm">
                        <i class="fas fa-address-card"></i>
                    </a>

                    <!-- Medical records -->
                    <a v-if="item.medical_records_url"
                       :href="item.medical_records_url"
                       class="btn btn-light btn-sm">
                        <i class="fas fa-file-medical"></i>
                    </a>

                    <!-- View -->
                    <a :href="item.show_url" class="btn btn-light btn-sm">
                        <i class="fas fa-eye"></i>
                    </a>

                    <!-- More (reschedule) -->
                    <div v-if="!item.is_terminal && isStaff" class="dropdown">
                        <button type="button"
                                class="btn btn-outline-light btn-sm"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <button type="button" class="dropdown-item"
                                        @click="emit('reschedule', item)">
                                    <i class="fas fa-calendar-alt me-2"></i>Reagendar
                                </button>
                            </li>
                        </ul>
                    </div>

                </div>
            </div>
        </div>
    </div>
</template>
