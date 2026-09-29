<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Retorno do servidor nas telas de repasse: o backend flasheia `message` (não
 * `success`) e o toast do AppLayout só escuta success/error — alerta local,
 * como em AccessControl/Roles. `flash.error` continua no toast do layout.
 *
 * Fechar é estado local (sem data-bs-dismiss, que removeria do DOM um nó
 * controlado pelo Vue); cada flash novo volta a exibi-lo.
 */
const page = usePage();

const message    = computed(() => page.props?.flash?.message ?? null);
const closeLabel = computed(() => page.props?.t_ui?.close ?? '');
const dismissed  = ref(false);

watch([() => page.props?.flash, message], () => {
    dismissed.value = false;
});
</script>

<template>
    <div v-if="message && !dismissed" class="alert alert-success alert-dismissible mb-3" role="status" data-test="flash-message">
        <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ message }}
        <button type="button" class="btn-close" :aria-label="closeLabel" :title="closeLabel" @click="dismissed = true"></button>
    </div>
</template>
