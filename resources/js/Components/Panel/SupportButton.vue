<script setup>
const { support } = defineProps({ support: { type: Object, required: true } });
</script>
<template>
    <a
        :href="support.url"
        target="_blank"
        rel="noopener noreferrer"
        class="topbar-link btn btn-icon"
        :title="support.label"
        :aria-label="support.label"
        data-test="support-button"
    >
        <i
            :class="support.kind === 'whatsapp' ? 'ti ti-brand-whatsapp' : 'ti ti-mail'"
            class="fs-16"
            aria-hidden="true"
        ></i>
    </a>
</template>
