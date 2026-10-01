<script setup>
import { computed } from 'vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useRoleFormat } from './useRoleFormat.js';

/**
 * Cards de perfis customizados no padrão de Patients/PatientCards: ícone,
 * nome, descrição, dados principais em linhas rotuladas e as mesmas ações
 * da RoleTable. Usa o MESMO paginator da tabela (prop `roles`), sem
 * endpoint extra — busca/ordenação/página continuam server-side.
 */
const props = defineProps({
    roles: { type: Object, required: true }, // paginator Laravel
    t: { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const emit = defineEmits(['edit', 'delete']);

const { date, permissionsLabel, usersLabel, permissionGroups } = useRoleFormat(() => props.t);

const rows = computed(() => props.roles?.data ?? []);
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-shield-off fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ emptyText }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="role in rows" :key="role.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start gap-3">
                    <span class="role-avatar flex-shrink-0" aria-hidden="true">
                        <i class="ti ti-shield-lock"></i>
                    </span>
                    <div class="min-w-0">
                        <h6 class="mb-1 fw-semibold lh-sm text-break">{{ role.name }}</h6>
                        <p
                            class="small mb-0 role-description"
                            :class="role.description ? 'text-muted' : 'text-body-secondary fst-italic'"
                        >
                            {{ role.description || (t.no_description ?? 'Sem descrição') }}
                        </p>
                    </div>
                </div>

                <!-- Linhas rotuladas no estilo de PatientCards ("Rótulo: valor"). -->
                <dl class="small text-muted mt-3 mb-1">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_permissions ?? 'Permissões' }}:</dt>
                        <dd class="mb-0 text-break">
                            {{ permissionsLabel(role)
                            }}<template v-if="permissionGroups(role)"> · {{ permissionGroups(role) }}</template>
                        </dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_users ?? 'Usuários' }}:</dt>
                        <dd class="mb-0">{{ usersLabel(role) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_created_at ?? 'Cadastro' }}:</dt>
                        <dd class="mb-0">{{ date(role.created_at) }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton
                        icon="ti ti-edit"
                        :title="t.action_edit ?? 'Editar'"
                        @click="emit('edit', role)"
                    />
                    <ActionIconButton
                        icon="ti ti-trash"
                        :title="t.action_delete ?? 'Excluir'"
                        variant="danger"
                        @click="emit('delete', role)"
                    />
                </ActionIconGroup>
            </div>
        </div>
    </div>

    <TablePagination
        :data="roles"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>

<style scoped>
.min-w-0 {
    min-width: 0;
}
.role-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    color: var(--bs-primary);
    background: var(--bs-primary-bg-subtle);
}
/* Descrição longa: até 3 linhas no card, sem esticar a grade. */
.role-description {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
