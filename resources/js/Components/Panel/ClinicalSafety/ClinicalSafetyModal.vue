<script setup>
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ClinicalAlertBanner from './ClinicalAlertBanner.vue';
import PatientAllergiesPanel from './PatientAllergiesPanel.vue';
import PatientMedicationsPanel from './PatientMedicationsPanel.vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { clinicalTexts, tc } from '@/Support/clinicalSafety.js';

/**
 * "Revisar alergias e medicações" no prontuário (Fase 2 / A1-A2): o médico
 * confirma, corrige ou descarta o que a recepção registrou e mantém a lista
 * de medicações em uso. Grava na hora — não depende de salvar o prontuário.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    store: { type: Object, required: true },
    searchUrl: { type: String, default: '' },
    t: { type: Object, default: null },
});

defineEmits(['close']);

const texts = clinicalTexts(props.t);
const tt = (path, fallback) => tc(texts, path, fallback);
const page = usePage();
const closeText = computed(() => page?.props?.t_ui?.close ?? 'Fechar');
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-clipboard-heart me-2 text-danger" aria-hidden="true"></i
                >{{ tt('banner.review', 'Revisar alergias e medicações') }}
            </h5>
        </template>

        <ClinicalAlertBanner :summary="store.summary" :t="t" class="mb-3" />
        <PatientAllergiesPanel mode="live" :store="store" :search-url="searchUrl" :t="t" class="mb-3" />
        <hr />
        <PatientMedicationsPanel mode="live" :store="store" :search-url="searchUrl" :t="t" />

        <template #footer>
            <button type="button" class="btn btn-primary" @click="$emit('close')">{{ closeText }}</button>
        </template>
    </CenteredModal>
</template>
