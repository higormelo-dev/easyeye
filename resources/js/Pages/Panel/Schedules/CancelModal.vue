<script setup>
import { ref, watch, nextTick } from 'vue';

const props = defineProps({
    item: { type: Object, default: null },
    t:    { type: Object, required: true },
});

const emit = defineEmits(['close', 'cancelled']);

const notes   = ref('');
const saving  = ref(false);
const errorMsg = ref('');
let bsModal   = null;

watch(() => props.item, async (val) => {
    if (val) {
        notes.value   = '';
        errorMsg.value = '';
        await nextTick();
        if (! bsModal) {
            bsModal = new bootstrap.Modal(document.getElementById('cancelScheduleModal'));
        }
        bsModal.show();
    } else {
        bsModal?.hide();
    }
});

async function onConfirm() {
    if (saving.value || ! props.item) return;
    saving.value  = true;
    errorMsg.value = '';

    const res = await fetch(props.item.situation_url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ situation: 9, notes: notes.value }),
    });
    const json = await res.json();
    saving.value = false;

    if (res.ok) {
        bsModal?.hide();
        emit('cancelled', json);
    } else {
        errorMsg.value = json.message ?? 'Erro ao cancelar.';
    }
}

function onHidden() {
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div id="cancelScheduleModal"
             class="modal fade"
             tabindex="-1"
             @hidden.bs.modal="onHidden">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-ban me-2 text-danger"></i>{{ t.cancel_title }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="errorMsg" class="alert alert-danger py-2 small">{{ errorMsg }}</div>
                        <div v-if="item" class="mb-3 text-muted small">
                            {{ item.time }} — <strong>{{ item.name }}</strong>
                        </div>
                        <label class="form-label fw-semibold">{{ t.show_cancel_reason }}</label>
                        <textarea
                            v-model="notes"
                            class="form-control"
                            rows="3"
                            :placeholder="t.form_notes_ph"
                        ></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            {{ t.reschedule_cancel }}
                        </button>
                        <button type="button" class="btn btn-danger" :disabled="saving" @click="onConfirm">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ saving ? t.saving : t.cancel_title }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
