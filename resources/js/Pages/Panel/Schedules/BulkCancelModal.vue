<script setup>
import { ref, watch, nextTick } from 'vue';

const props = defineProps({
    open:        { type: Boolean, required: true },
    selectedIds: { type: Array,   default: () => [] },
    t:           { type: Object,  required: true },
});

const emit = defineEmits(['close', 'done']);

const notes    = ref('');
const saving   = ref(false);
const errorMsg = ref('');
let   bsModal  = null;

watch(() => props.open, async (val) => {
    if (val) {
        notes.value    = '';
        errorMsg.value = '';
        saving.value   = false;
        await nextTick();
        if (! bsModal) bsModal = new bootstrap.Modal(document.getElementById('bulkCancelModal'));
        bsModal.show();
    } else {
        bsModal?.hide();
    }
});

async function onConfirm() {
    if (saving.value || props.selectedIds.length === 0) return;
    saving.value   = true;
    errorMsg.value = '';

    const res = await fetch(route('panel.schedules.bulk-update'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ ids: props.selectedIds, situation: 9, notes: notes.value || null }),
    });
    const json = await res.json();
    saving.value = false;

    if (res.ok) {
        bsModal?.hide();
        emit('done', json);
    } else {
        errorMsg.value = json.message ?? 'Erro ao cancelar.';
    }
}

function onHidden() { emit('close'); }
</script>

<template>
    <Teleport to="body">
        <div id="bulkCancelModal"
             class="modal fade"
             tabindex="-1"
             @hidden.bs.modal="onHidden">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-ban me-2 text-danger"></i>{{ t.bulk_cancel_title }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="errorMsg" class="alert alert-danger py-2 small">{{ errorMsg }}</div>
                        <p class="text-muted small mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            {{ selectedIds.length }} {{ t.selected_count }}
                        </p>
                        <label class="form-label fw-semibold">{{ t.show_cancel_reason }}</label>
                        <textarea
                            v-model="notes"
                            class="form-control"
                            rows="3"
                            :placeholder="t.form_notes_ph"
                            maxlength="500"
                        ></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            {{ t.reschedule_cancel }}
                        </button>
                        <button type="button"
                                class="btn btn-danger"
                                :disabled="saving || selectedIds.length === 0"
                                @click="onConfirm">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ saving ? t.saving : t.btn_bulk_cancel }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
