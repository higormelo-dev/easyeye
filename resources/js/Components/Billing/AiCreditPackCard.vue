<script setup>
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
defineProps({
    pkg: { type: Object, required: true },
    modelValue: { type: String, default: '' },
    name: { type: String, required: true },
    valueId: { type: String, required: true },
    errorId: { type: String, required: true },
    error: { type: String, default: '' },
    t: { type: Object, required: true },
});
const emit = defineEmits(['update:modelValue']);
const { moneyCents } = useLocaleFormat();
</script>
<template>
    <label
        class="ai-pack-card"
        :class="{ 'is-selected': modelValue === pkg.code, 'is-featured': pkg.featured }"
        data-test="ai-pack"
        :data-code="pkg.code"
    >
        <span v-if="pkg.featured" class="ai-pack-featured">{{ t.ai_featured }}</span>
        <span class="d-flex align-items-center gap-2 mb-3">
            <input
                :checked="modelValue === pkg.code"
                type="radio"
                :name="name"
                :value="pkg.code"
                :aria-describedby="`${valueId}${error ? ` ${errorId}` : ''}`"
                @change="emit('update:modelValue', pkg.code)"
            />
            <span class="fw-semibold">{{ pkg.name }}</span>
        </span>
        <span :id="valueId" class="d-block">
            <strong class="h4 text-primary">{{ pkg.custom ? t.ai_custom_label : moneyCents(pkg.value_cents) }}</strong>
            <span v-if="!pkg.custom" class="d-block small text-muted">{{ t.ai_in_balance }}</span>
        </span>
        <span
            v-if="error"
            :id="errorId"
            class="d-block text-danger mt-2 small"
            role="alert"
            data-test="ai-pack-card-error"
            >{{ error }}</span
        >
    </label>
</template>
<style scoped>
.ai-pack-card {
    position: relative;
    cursor: pointer;
    border: 1px solid #9aa7b6;
    border-radius: 0.5rem;
    padding: 1.5rem 1rem;
    margin-top: 0.75rem;
    min-width: 0;
}
.ai-pack-card.is-selected {
    border: 2px solid var(--bs-primary);
    padding: calc(1.5rem - 1px) calc(1rem - 1px);
    background: #f0f6ff;
}
.ai-pack-card:focus-within {
    outline: 3px solid var(--bs-primary);
    outline-offset: 3px;
}
.ai-pack-featured {
    position: absolute;
    top: -0.75rem;
    left: 50%;
    transform: translateX(-50%);
    background: var(--bs-primary);
    color: #fff;
    padding: 0.2rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.7rem;
    font-weight: 700;
    white-space: nowrap;
}
</style>
