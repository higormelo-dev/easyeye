<script setup>
import { computed, reactive, watch } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

const props = defineProps({
    credential: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['save', 'test']);
const { dateTime } = useLocaleFormat();
const form = reactive({ token: '', master_token: '', reason: '' });
watch(
    () => props.credential?.environment,
    () => Object.assign(form, { token: '', master_token: '', reason: '' }),
);
const label = computed(() => props.t.environment?.[props.credential.environment] ?? props.credential.environment);
const validReason = computed(() => form.reason.trim().length >= 20);
function submit() {
    if (!validReason.value) return;
    emit('save', {
        environment: props.credential.environment,
        token: form.token || undefined,
        master_token: form.master_token || undefined,
        reason: form.reason,
    });
}
</script>

<template>
    <section class="card h-100" :data-test="`nfse-credentials-${credential.environment}`">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0"><i class="ti ti-key me-2" aria-hidden="true"></i>{{ label }}</h2>
            <span class="badge" :class="credential.configured ? 'badge-soft-success' : 'badge-soft-secondary'">{{
                credential.configured ? t.credentials?.configured : t.credentials?.not_configured
            }}</span>
        </div>
        <div class="card-body">
            <p v-if="credential.last_test?.at" class="small text-muted">
                {{ t.credentials?.last_test?.replace(':date', dateTime(credential.last_test.at)) }}
                <span :class="credential.last_test.ok ? 'text-success' : 'text-danger'">{{
                    credential.last_test.message
                }}</span>
            </p>
            <form novalidate @submit.prevent="submit">
                <div class="mb-3">
                    <label :for="`nfse-token-${credential.environment}`" class="form-label">{{
                        t.credentials?.token
                    }}</label
                    ><input
                        :id="`nfse-token-${credential.environment}`"
                        v-model="form.token"
                        class="form-control"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="credential.has_token ? t.credentials?.replace_token : ''"
                    />
                    <div class="form-text">{{ t.credentials?.replace_token }}</div>
                    <div v-if="errors.token" class="invalid-feedback d-block">{{ errors.token }}</div>
                </div>
                <div class="mb-3">
                    <label :for="`nfse-master-token-${credential.environment}`" class="form-label">{{
                        t.credentials?.master_token
                    }}</label
                    ><input
                        :id="`nfse-master-token-${credential.environment}`"
                        v-model="form.master_token"
                        class="form-control"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="credential.has_master_token ? t.credentials?.replace_token : ''"
                    />
                    <div v-if="errors.master_token" class="invalid-feedback d-block">{{ errors.master_token }}</div>
                </div>
                <div class="mb-3">
                    <label :for="`nfse-credentials-reason-${credential.environment}`" class="form-label">{{
                        t.credentials?.reason
                    }}</label
                    ><textarea
                        :id="`nfse-credentials-reason-${credential.environment}`"
                        v-model="form.reason"
                        class="form-control"
                        rows="2"
                        required
                        minlength="20"
                    ></textarea>
                    <div class="form-text">{{ t.credentials?.reason_hint }}</div>
                    <div v-if="errors.reason" class="invalid-feedback d-block">{{ errors.reason }}</div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="saving || !validReason">
                        {{ t.credentials?.save }}</button
                    ><button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="saving || !credential.configured"
                        @click="$emit('test', credential.environment)"
                    >
                        {{ t.credentials?.test }}
                    </button>
                </div>
            </form>
        </div>
    </section>
</template>
