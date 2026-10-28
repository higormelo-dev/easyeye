<script setup>
import { computed, reactive, watch } from 'vue';
const props = defineProps({
    credential: { type: Object, required: true },
    hookUrl: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
defineEmits(['store', 'destroy']);
const form = reactive({ reason: '' });
const validReason = computed(() => form.reason.trim().length >= 20);
watch(
    () => props.credential?.environment,
    () => {
        form.reason = '';
    },
);
</script>

<template>
    <section class="card h-100" :data-test="`nfse-hook-${credential.environment}`">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0"><i class="ti ti-webhook me-2" aria-hidden="true"></i>{{ t.page?.hook }}</h2>
            <span class="badge" :class="credential.hook?.registered ? 'badge-soft-success' : 'badge-soft-secondary'">{{
                credential.hook?.registered ? t.hook?.registered : t.hook?.not_registered
            }}</span>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                <span class="d-block fw-semibold">{{ t.hook?.url }}</span
                ><code class="text-break">{{ hookUrl }}</code>
            </p>
            <dl v-if="credential.hook?.registered" class="row small mb-3">
                <dt class="col-4">{{ t.hook?.id }}</dt>
                <dd class="col-8 text-break">{{ credential.hook?.hook_id }}</dd>
                <dt class="col-4">{{ t.hook?.event }}</dt>
                <dd class="col-8">{{ credential.hook?.event }}</dd>
            </dl>
            <label :for="`nfse-hook-reason-${credential.environment}`" class="form-label">{{ t.hook?.reason }}</label
            ><textarea
                :id="`nfse-hook-reason-${credential.environment}`"
                v-model="form.reason"
                class="form-control mb-1"
                rows="2"
                required
                minlength="20"
            ></textarea>
            <div class="form-text mb-3">{{ t.hook?.reason_hint }}</div>
            <div v-if="errors.reason" class="invalid-feedback d-block mb-2">{{ errors.reason }}</div>
            <button
                v-if="credential.hook?.registered"
                type="button"
                class="btn btn-outline-danger btn-sm"
                :disabled="saving || !validReason"
                @click="$emit('destroy', { environment: credential.environment, reason: form.reason })"
            >
                {{ t.hook?.remove }}</button
            ><button
                v-else
                type="button"
                class="btn btn-outline-primary btn-sm"
                :disabled="saving || !validReason"
                @click="$emit('store', { environment: credential.environment, reason: form.reason })"
            >
                {{ t.hook?.register }}
            </button>
        </div>
    </section>
</template>
