<script setup>
/**
 * Toggle "compartilhar com paciente" — reutilizável pros 3 tipos
 * compartilháveis (laudo/exame/anexo). Replica o padrão EXATO já usado em
 * PatientDetailDrawer.vue pro convite do Portal do Paciente (Fase 1):
 * router.post/delete do Inertia, preserveScroll/preserveState, leitura de
 * page.props.flash.
 *
 * Emite `changed` após qualquer ação bem-sucedida — o pai deve refetchar os
 * dados (mesmo endpoint JSON que já usa) pra pegar o novo document_share_id
 * (necessário pra revogar depois) em vez de tentar rastrear estado otimista aqui.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';

const props = defineProps({
    shareableType: { type: String, required: true }, // 'laudo' | 'exame' | 'anexo'
    shareableId:   { type: String, required: true },
    patientId:     { type: String, required: true },
    isShared:      { type: Boolean, default: false },
    shareId:       { type: String, default: null },   // obrigatório pra revogar
    disabled:      { type: Boolean, default: false },
});

const emit = defineEmits(['changed']);

const loading  = ref(false);
const errorMsg = ref('');

function toggle() {
    if (props.disabled || loading.value) return;

    loading.value  = true;
    errorMsg.value = '';

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const flash = page.props?.flash ?? {};
            if (flash.success) {
                emit('changed');
            } else {
                errorMsg.value = flash.error ?? 'Não foi possível atualizar o compartilhamento.';
            }
        },
        onError: () => {
            errorMsg.value = 'Não foi possível atualizar o compartilhamento.';
        },
        onFinish: () => { loading.value = false; },
    };

    if (props.isShared && props.shareId) {
        router.delete(route('panel.document-shares.destroy', props.shareId), options);
    } else {
        router.post(route('panel.document-shares.store'), {
            shareable_type: props.shareableType,
            shareable_id: props.shareableId,
            patient_id: props.patientId,
        }, options);
    }
}
</script>

<template>
    <ActionIconButton
        :icon="loading ? 'ti ti-loader-2 ee-spin' : (isShared ? 'ti ti-share-off' : 'ti ti-share')"
        :title="errorMsg || (isShared ? 'Revogar acesso do paciente a este documento' : 'Compartilhar este documento com o paciente')"
        :variant="isShared ? 'success' : 'default'"
        :disabled="disabled || loading"
        @click="toggle"
    />
</template>
