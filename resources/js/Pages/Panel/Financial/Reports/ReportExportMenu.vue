<script setup>
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';

/**
 * Botão "Exportar" dos relatórios: links puros (o navegador baixa o anexo)
 * montados por useReportPage() com o período APLICADO.
 */
defineProps({
    options: { type: Array,  default: () => [] }, // [{ key, icon, label, href }]
    title:   { type: String, default: '' },
    label:   { type: String, default: '' },
});
</script>

<template>
    <ActionDropdown :title="title" btn-class="btn btn-outline-secondary btn-sm" :min-width="210">
        <template #trigger>
            <i class="ti ti-download me-1" aria-hidden="true"></i>{{ label }}
            <i class="ti ti-chevron-down ms-1" aria-hidden="true"></i>
        </template>
        <li v-for="option in options" :key="option.key">
            <a class="dropdown-item d-flex align-items-center gap-2" :href="option.href" :data-format="option.key">
                <i :class="option.icon" aria-hidden="true"></i>{{ option.label }}
            </a>
        </li>
    </ActionDropdown>
</template>
