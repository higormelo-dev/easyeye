<script setup>
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';
import { useCashEntryFormat } from './useCashEntryFormat.js';

/**
 * Ações de um lançamento (tabela e cards): editar/excluir, ou o motivo da
 * trava (guia vinculada / caixa fechado) no lugar dos botões. O nome acessível
 * dos botões leva a descrição — "Editar lançamento: Aluguel" — para não haver
 * dezenas de "Editar" iguais para o leitor de tela.
 */
const props = defineProps({
    entry: { type: Object,  required: true },
    busy:  { type: Boolean, default: false },
    t:     { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['edit', 'delete']);

const { lockLabel, lockHint } = useCashEntryFormat(() => props.t);

const actionLabel = (label) => `${label}: ${props.entry.description ?? ''}`;
</script>

<template>
    <span
        v-if="entry.lock_reason"
        class="badge rounded badge-soft-secondary border border-secondary fs-11 fw-medium"
        :title="lockHint(entry)"
        data-test="lock-badge"
    >
        <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ lockLabel(entry) }}
        <span class="visually-hidden">: {{ lockHint(entry) }}</span>
    </span>
    <ActionIconGroup v-else align="end" gap="tight">
        <ActionIconButton
            icon="ti ti-edit"
            :title="t.action_edit"
            :aria-label="actionLabel(t.action_edit)"
            :disabled="busy"
            data-test="edit"
            @click="emit('edit', entry)"
        />
        <ActionIconButton
            icon="ti ti-trash"
            :title="t.action_delete"
            :aria-label="actionLabel(t.action_delete)"
            variant="danger"
            :disabled="busy"
            data-test="delete"
            @click="emit('delete', entry)"
        />
    </ActionIconGroup>
</template>
