<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    open:    { type: Boolean, required: true },
    gateway: { type: Object,  default: null },
    t:       { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

const priority = ref(1);
const saving   = ref(false);
const error    = ref('');

watch(() => props.open, (val) => {
    if (val && props.gateway) {
        priority.value = props.gateway.priority;
        error.value    = '';
    }
});

async function submit() {
    saving.value = true;
    error.value  = '';
    try {
        const res  = await fetch(props.gateway.priority_url, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ priority: priority.value }),
        });
        const json = await res.json();
        if (!res.ok) { error.value = json.message ?? props.t.js_error_generic; return; }
        if (window.showSuccessToast) showSuccessToast(json.message);
        emit('close');
        router.reload({ only: ['gateways', 'defaultGateway'] });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="modal fade show d-block"
            tabindex="-1"
            style="background:rgba(0,0,0,.4)"
            @click.self="$emit('close')"
        >
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-sort-ascending me-2"></i>{{ t.modal_priority_title }}
                        </h5>
                        <button type="button" class="btn-close" @click="$emit('close')"></button>
                    </div>
                    <form @submit.prevent="submit">
                        <div class="modal-body">
                            <p class="fw-semibold mb-1 small">{{ gateway?.name }}</p>
                            <p class="text-muted small mb-3">{{ t.modal_priority_desc }}</p>
                            <input
                                v-model.number="priority"
                                type="number"
                                class="form-control form-control-sm"
                                min="1"
                                max="999"
                                required
                            >
                            <div v-if="error" class="alert alert-danger mt-2 py-2 small mb-0">
                                <i class="ti ti-alert-circle me-1"></i>{{ error }}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" @click="$emit('close')">
                                {{ t.modal_priority_cancel }}
                            </button>
                            <button type="submit" class="btn btn-primary btn-sm" :disabled="saving">
                                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                                {{ t.modal_priority_save }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </Teleport>
</template>
