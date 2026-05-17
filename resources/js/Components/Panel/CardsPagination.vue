<script setup>
/**
 * CardsPagination — Paginação numérica para views de cards com fetch AJAX.
 *
 * Props:
 *   meta – objeto { current_page, last_page }
 *
 * Emits:
 *   change(page) – quando o usuário clica numa página
 *
 * Não exibe nada quando last_page === 1.
 */
defineProps({
    meta: {
        type: Object,
        default: () => ({ current_page: 1, last_page: 1 }),
    },
});

defineEmits(['change']);
</script>

<template>
    <nav v-if="meta.last_page > 1" class="d-flex justify-content-center mt-3">
        <ul class="pagination pagination-sm mb-0">
            <!-- Previous -->
            <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
                <button
                    class="page-link"
                    @click="$emit('change', meta.current_page - 1)"
                >
                    <i class="ti ti-arrow-left"></i>
                </button>
            </li>

            <!-- Page numbers -->
            <template v-for="p in meta.last_page" :key="p">
                <li class="page-item" :class="{ active: p === meta.current_page }">
                    <button class="page-link" @click="$emit('change', p)">{{ p }}</button>
                </li>
            </template>

            <!-- Next -->
            <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
                <button
                    class="page-link"
                    @click="$emit('change', meta.current_page + 1)"
                >
                    <i class="ti ti-arrow-right"></i>
                </button>
            </li>
        </ul>
    </nav>
</template>
