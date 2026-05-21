<script setup>
import { ref, watch, nextTick } from 'vue';

const props = defineProps({
    open:        { type: Boolean, required: true },
    selectedIds: { type: Array,   default: () => [] },
    t:           { type: Object,  required: true },
});

const emit = defineEmits(['close', 'done']);

const date     = ref('');
const time     = ref('');
const saving   = ref(false);
const errorMsg = ref('');
let   bsModal  = null;

watch(() => props.open, async (val) => {
    if (val) {
        date.value     = '';
        time.value     = '';
        errorMsg.value = '';
        saving.value   = false;
        await nextTick();
        if (! bsModal) bsModal = new bootstrap.Modal(document.getElementById('bulkRescheduleModal'));
        bsModal.show();
    } else {
        bsModal?.hide();
    }
});

async function onConfirm() {
    if (saving.value || props.selectedIds.length === 0 || ! date.value) return;
    saving.value   = true;
    errorMsg.value = '';

    const res = await fetch(route('panel.schedules.bulk-reschedule'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ ids: props.selectedIds, date: date.value, time: time.value || null }),
    });
    const json = await res.json();
    saving.value = false;

    if (res.ok) {
        bsModal?.hide();
        emit('done', json);
    } else {
        errorMsg.value = json.message ?? 'Erro ao reagendar.';
    }
}

function onHidden() { emit('close'); }
</script>

<template>
    <Teleport to="body">
        <div id="bulkRescheduleModal"
             class="modal fade"
             tabindex="-1"
             @hidden.bs.modal="onHidden">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-calendar-alt me-2 text-primary"></i>{{ t.bulk_change_date_title }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="errorMsg" class="alert alert-danger py-2 small">{{ errorMsg }}</div>
                        <p class="text-muted small mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            {{ selectedIds.length }} {{ t.selected_count }}
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">{{ t.reschedule_datetime.split(' ')[0] }} — Data</label>
                            <input v-model="date" type="date" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Hora <small class="text-muted fw-normal">(opcional)</small></label>
                            <input v-model="time" type="time" class="form-control" step="60">
                            <div class="form-text text-muted small">{{ t.bulk_change_date_hint }}</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            {{ t.reschedule_cancel }}
                        </button>
                        <button type="button"
                                class="btn btn-primary"
                                :disabled="saving || !date || selectedIds.length === 0"
                                @click="onConfirm">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ saving ? t.saving : t.btn_bulk_change_date }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
