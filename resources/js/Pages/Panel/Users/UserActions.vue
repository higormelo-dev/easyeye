<script setup>
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';

/**
 * Ações de um usuário, iguais na UserTable e nos UserCards, por `mode`
 * (ActionPolicy) e pelas proteções do backend (EntityUserService/
 * UsersController::destroy):
 *   - removido (restore): só Restaurar;
 *   - proprietário: nada editável — o backend recusa (403) qualquer alteração,
 *     então no lugar do botão fica um cadeado com a explicação;
 *   - própria conta: Editar, sem Desativar/Excluir;
 *   - demais: Editar + menu (Ativar/Desativar, Excluir).
 */
defineProps({
    user: { type: Object, required: true },
    t:    { type: Object, default: () => ({}) },
});

const emit = defineEmits(['edit', 'delete', 'restore', 'toggleActive']);
</script>

<template>
    <ActionIconGroup align="end" gap="tight">
        <ActionIconButton
            v-if="user.mode === 'restore'"
            icon="ti ti-recycle"
            :title="t.btn_restore ?? 'Restaurar'"
            variant="success"
            @click="emit('restore', user)"
        />

        <template v-else-if="user.mode === 'full'">
            <span
                v-if="user.is_owner"
                class="user-owner-lock text-body-secondary"
                :title="t.owner_locked"
            >
                <i class="ti ti-lock" aria-hidden="true"></i>
                <span class="visually-hidden">{{ t.owner_locked }}</span>
            </span>

            <template v-else>
                <ActionIconButton
                    icon="ti ti-edit"
                    :title="t.btn_edit ?? 'Editar'"
                    @click="emit('edit', user)"
                />
                <ActionDropdown
                    v-if="!user.is_self"
                    :title="t.more_actions ?? 'Mais ações'"
                    btn-class="ee-action-icon ee-action-icon--default"
                    icon="ti ti-dots-vertical"
                >
                    <li>
                        <button type="button" class="dropdown-item rounded-1" @click="emit('toggleActive', user)">
                            <i :class="`ti me-1 ${user.active ? 'ti-lock-open' : 'ti-lock'}`" aria-hidden="true"></i>
                            {{ user.active ? (t.btn_deactivate ?? 'Desativar') : (t.btn_activate ?? 'Ativar') }}
                        </button>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <button type="button" class="dropdown-item rounded-1 text-danger" @click="emit('delete', user)">
                            <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ t.btn_delete ?? 'Excluir' }}
                        </button>
                    </li>
                </ActionDropdown>
            </template>
        </template>
    </ActionIconGroup>
</template>

<style scoped>
.user-owner-lock {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    cursor: help;
}
</style>
