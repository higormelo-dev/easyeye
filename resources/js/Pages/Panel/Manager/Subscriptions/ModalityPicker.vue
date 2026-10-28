<script setup>
/**
 * Escolha da modalidade da assinatura em cartões (rádio): nome + o que
 * significa para a cobrança. Usado em "Nova assinatura" e "Alterar".
 */
defineProps({
    modelValue: { type: String, default: '' },
    modes: { type: Array, required: true },
    name: { type: String, required: true },
    legend: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['update:modelValue']);

const ICONS = {
    trial: 'ti-clock-play',
    gateway: 'ti-credit-card',
    complimentary: 'ti-gift',
};
</script>

<template>
    <fieldset>
        <legend class="form-label fw-medium fs-6">{{ legend }}</legend>
        <div class="sub-modes">
            <label
                v-for="mode in modes"
                :key="mode"
                class="sub-mode"
                :class="{ 'sub-mode--active': modelValue === mode }"
                :data-mode="mode"
            >
                <input
                    class="visually-hidden"
                    type="radio"
                    :name="name"
                    :value="mode"
                    :checked="modelValue === mode"
                    @change="$emit('update:modelValue', mode)"
                />
                <span class="sub-mode__title">
                    <i :class="['ti me-1', ICONS[mode]]" aria-hidden="true"></i>{{ t.modality?.[mode] }}
                </span>
                <span class="sub-mode__hint">{{ t.modality_hint?.[mode] }}</span>
            </label>
        </div>
        <slot />
    </fieldset>
</template>

<style scoped>
.sub-modes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.5rem;
}
.sub-mode {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    cursor: pointer;
    transition: border-color 0.15s ease;
}
.sub-mode:hover {
    border-color: var(--bs-primary);
}
.sub-mode:focus-within {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
}
.sub-mode--active {
    border-color: var(--bs-primary);
    background: var(--bs-primary-bg-subtle);
}
.sub-mode__title {
    font-weight: 600;
    font-size: 0.875rem;
}
.sub-mode__hint {
    color: var(--bs-secondary-color);
    font-size: 0.75rem;
    line-height: 1.35;
}
</style>
