<script setup>
import { ref, watch, nextTick } from 'vue';

const props = defineProps({
    item:    { type: Object, default: null },
    doctors: { type: Array,  default: () => [] },
    t:       { type: Object, required: true },
});

const emit = defineEmits(['close', 'rescheduled']);

const dateTime = ref('');
const doctorId = ref('');
const saving   = ref(false);
const errorMsg = ref('');
let bsModal    = null;

watch(() => props.item, async (val) => {
    if (val) {
        dateTime.value = '';
        doctorId.value = val.doctor_id ?? '';
        errorMsg.value = '';
        saving.value   = false;
        await nextTick();
        if (! bsModal) {
            bsModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
        }
        bsModal.show();
    } else {
        bsModal?.hide();
    }
});

async function onConfirm() {
    if (saving.value || ! props.item || ! dateTime.value) return;
    saving.value   = true;
    errorMsg.value = '';

    const res = await fetch(props.item.reschedule_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ date_time: dateTime.value, doctor_id: doctorId.value || null }),
    });
    const json = await res.json();
    saving.value = false;

    if (res.ok) {
        bsModal?.hide();
        emit('rescheduled', json);
    } else {
        errorMsg.value = json.message ?? 'Erro ao reagendar.';
    }
}

function onHidden() {
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div id="rescheduleModal"
             class="modal fade"
             tabindex="-1"
             @hidden.bs.modal="onHidden">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-calendar-alt me-2 text-primary"></i>{{ t.reschedule_title }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="errorMsg" class="alert alert-danger py-2 small">{{ errorMsg }}</div>
                        <div v-if="item" class="mb-3 text-muted small">
                            {{ item.time }} — <strong>{{ item.name }}</strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">{{ t.reschedule_datetime }}</label>
                            <input v-model="dateTime" type="datetime-local" class="form-control">
                        </div>
                        <div v-if="doctors.length > 1" class="mb-3">
                            <label class="form-label fw-semibold">{{ t.reschedule_doctor }}</label>
                            <select v-model="doctorId" class="form-select">
                                <option value="">{{ t.form_select }}</option>
                                <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            {{ t.reschedule_cancel }}
                        </button>
                        <button type="button" class="btn btn-primary"
                                :disabled="saving || !dateTime"
                                @click="onConfirm">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ saving ? t.saving : t.reschedule_save }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
