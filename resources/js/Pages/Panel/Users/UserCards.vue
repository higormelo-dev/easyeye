<script setup>
import { computed } from 'vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import UserActions from './UserActions.vue';
import { useUserFormat } from './useUserFormat.js';

/**
 * Cards de usuários no padrão de Patients/PatientCards: foto, nome, selos,
 * status, dados principais em linhas rotuladas e as mesmas ações da
 * UserTable. Usa o MESMO paginator da tabela (prop `users`) — antes buscava
 * um endpoint JSON à parte, sem ordenação, sem tratar erro e voltando à
 * página 1 a cada ação.
 */
const props = defineProps({
    users: { type: Object, required: true }, // paginator Laravel
    t: { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const emit = defineEmits(['edit', 'delete', 'restore', 'toggleActive']);

const { date, extraRolesLabel } = useUserFormat(() => props.t);

const rows = computed(() => props.users?.data ?? []);
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-users fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ emptyText }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="u in rows" :key="u.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100" :class="{ 'user-card-deleted': u.deleted }">
                <div class="d-flex align-items-center gap-3">
                    <img
                        :src="u.photo_url"
                        :alt="u.name"
                        class="rounded-circle flex-shrink-0"
                        width="56"
                        height="56"
                        loading="lazy"
                        style="object-fit: cover"
                    />
                    <div class="min-w-0">
                        <h6 class="mb-1 fw-semibold lh-sm text-break">{{ u.name }}</h6>
                        <div class="d-flex flex-wrap gap-1">
                            <span
                                v-if="u.deleted"
                                class="badge badge-soft-secondary rounded text-body-secondary border fs-12"
                                >{{ t.status_deleted ?? 'Excluído' }}</span
                            >
                            <span
                                v-else
                                :class="
                                    u.active
                                        ? 'badge badge-soft-success rounded text-success border border-success fs-12'
                                        : 'badge badge-soft-danger rounded text-danger border border-danger fs-12'
                                "
                                >{{ u.active ? (t.status_active ?? 'Ativo') : (t.status_inactive ?? 'Inativo') }}</span
                            >
                            <span v-if="u.is_owner" class="badge badge-soft-warning rounded fs-11">
                                <i class="ti ti-crown me-1" aria-hidden="true"></i>{{ t.badge_owner ?? 'Proprietário' }}
                            </span>
                            <span v-if="u.is_self" class="badge badge-soft-primary rounded fs-11">{{
                                t.badge_self ?? 'Você'
                            }}</span>
                        </div>
                    </div>
                </div>

                <!-- Linhas rotuladas no estilo de PatientCards ("Rótulo: valor"). -->
                <dl class="small text-muted mt-3 mb-1">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_email ?? 'E-mail' }}:</dt>
                        <dd class="mb-0 text-break">{{ u.email }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_role ?? 'Perfil' }}:</dt>
                        <dd class="mb-0">
                            {{ u.rule_label }}<template v-if="extraRolesLabel(u)"> · {{ extraRolesLabel(u) }}</template>
                        </dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_created_at ?? 'Cadastro' }}:</dt>
                        <dd class="mb-0">{{ date(u.created_at) }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto" />

                <UserActions
                    :user="u"
                    :t="t"
                    @edit="emit('edit', $event)"
                    @delete="emit('delete', $event)"
                    @restore="emit('restore', $event)"
                    @toggle-active="emit('toggleActive', $event)"
                />
            </div>
        </div>
    </div>

    <TablePagination
        :data="users"
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
/* Removido: fundo discreto em vez de opacity (mantém o contraste do texto). */
.user-card-deleted {
    background: var(--bs-tertiary-bg);
}
</style>
