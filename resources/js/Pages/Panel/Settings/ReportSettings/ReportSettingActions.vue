<script setup>
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';

/**
 * Ações de um modelo, iguais na tabela e nos cards: pré-visualizar (nova
 * aba), editar (página do formulário) e menu com reimportar (só adotados) e
 * excluir. Editar/excluir só no modo `full` (ActionPolicy — modelo da
 * própria clínica); o backend também recusa (404) modelo de outra clínica.
 */
defineProps({
    item: { type: Object, required: true },
    t:    { type: Object, default: () => ({}) },
});

const emit = defineEmits(['reimport', 'delete']);
</script>

<template>
    <ActionIconGroup align="end" gap="tight">
        <ActionIconButton
            icon="ti ti-eye"
            :title="t.action_preview ?? 'Pré-visualizar'"
            :href="item.preview_url"
            target="_blank"
        />

        <template v-if="item.mode === 'full'">
            <ActionIconButton
                icon="ti ti-edit"
                :title="t.action_edit ?? 'Editar'"
                variant="info"
                :inertia-href="item.edit_url"
            />
            <ActionDropdown
                :title="t.more_actions ?? 'Mais ações'"
                btn-class="ee-action-icon ee-action-icon--default"
                icon="ti ti-dots-vertical"
            >
                <template v-if="item.reimport_url">
                    <li>
                        <button type="button" class="dropdown-item rounded-1" @click="emit('reimport', item)">
                            <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ t.action_reimport ?? 'Reimportar modelo global' }}
                        </button>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                </template>
                <li>
                    <button type="button" class="dropdown-item rounded-1 text-danger" @click="emit('delete', item)">
                        <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ t.action_delete ?? 'Excluir' }}
                    </button>
                </li>
            </ActionDropdown>
        </template>
    </ActionIconGroup>
</template>
