<script setup>
import DescriptionPanel from '@/Components/Surgery/Operation/DescriptionPanel.vue';
import ImplantsPanel from '@/Components/Surgery/Operation/ImplantsPanel.vue';
import PerformPanel from '@/Components/Surgery/Operation/PerformPanel.vue';

/**
 * Aba "Cirurgia" (M4): registrar a cirurgia como realizada (com as condições
 * que o servidor confere), implantes com rastreabilidade (RDC 556/2021) e a
 * descrição cirúrgica. O servidor decide o que cada perfil pode fazer
 * (`section.state`); a tela só reflete e, depois de cada ação, pede
 * 'reload' para a página do caso.
 */
defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

defineEmits(['reload', 'edit']);
</script>

<template>
    <div data-testid="operation-tab">
        <PerformPanel :section="section" :surgical-case="surgicalCase" :t="t" @reload="$emit('reload')" />
        <ImplantsPanel :section="section" :t="t" @reload="$emit('reload')" />
        <DescriptionPanel :section="section" :t="t" @reload="$emit('reload')" />
    </div>
</template>
