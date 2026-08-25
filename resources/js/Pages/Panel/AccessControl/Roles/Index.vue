<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout      from '@/Layouts/AppLayout.vue';
import PageHeader     from '@/Components/Panel/PageHeader.vue';
import SearchInput    from '@/Components/Panel/SearchInput.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';
import RoleFormModal   from './RoleFormModal.vue';

/**
 * Listagem de Roles customizadas (RBAC granular ADITIVO por clínica).
 *
 * `roles` chega como array plano (RoleResource::collection sem wrap — ver
 * RolesController::index()), já com permissions/permission_ids/users_count
 * carregados. Não há paginação/busca no backend (lista tende a ser pequena,
 * poucas dezenas de perfis por clínica no máximo) — a busca abaixo é
 * client-side, mesmo racional do BaseSettingController::index() que também
 * não pagina catálogos pequenos.
 */
const props = defineProps({
    roles:               { type: Array,  default: () => [] },
    // Perfis FIXOS da plataforma (ClientRule) — pré-definidos pelo SaaS,
    // somente leitura: [{ value, label, description }].
    systemProfiles:      { type: Array,  default: () => [] },
    availablePermissions: { type: Array,  default: () => [] },
    breadcrumbs:         { type: Array,  default: () => [] },
    routes:              { type: Object, required: true }, // { index, store, update, destroy } — update/destroy com __ID__
});

// ── Busca client-side (sem endpoint de listagem paginado no backend) ───────
const search = ref('');

const filteredRoles = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.roles;

    return props.roles.filter((role) => (
        role.name?.toLowerCase().includes(term)
        || role.description?.toLowerCase().includes(term)
    ));
});

const filteredSystemProfiles = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.systemProfiles;

    return props.systemProfiles.filter((profile) => (
        profile.label?.toLowerCase().includes(term)
        || profile.description?.toLowerCase().includes(term)
    ));
});

// ── Form modal (criar/editar) ───────────────────────────────────────────────
const modalOpen  = ref(false);
const editingRole = ref(null);

function openCreate() { editingRole.value = null; modalOpen.value = true; }
function openEdit(role) { editingRole.value = role; modalOpen.value = true; }
function closeModal() { modalOpen.value = false; editingRole.value = null; }

// ── Exclusão ─────────────────────────────────────────────────────────────────
function onDelete(role) {
    const msg = role.users_count > 0
        ? `Excluir o perfil "${role.name}"? ${role.users_count} usuário(s) perderão estas permissões adicionais.`
        : `Excluir o perfil "${role.name}"?`;
    if (!confirm(msg)) return;

    router.delete(props.routes.destroy.replace('__ID__', role.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Perfis de acesso" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <!-- ── Header ─────────────────────────────────────────────────── -->
            <PageHeader title="Perfis de acesso" :total="filteredSystemProfiles.length + filteredRoles.length">
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>Novo perfil
                    </button>
                </template>
            </PageHeader>

            <!-- ── Aviso de limite do sistema ────────────────────────────── -->
            <div class="alert alert-info small py-2 mb-3">
                <i class="ti ti-info-circle me-1"></i>
                Os <strong>perfis do sistema</strong> já vêm pré-definidos pela plataforma e são
                atribuídos a cada usuário no cadastro de usuários. Os <strong>perfis
                customizados</strong> concedem permissões administrativas adicionais. Ações
                clínicas (laudos, prescrições) continuam exclusivas de médicos, independente
                de perfil.
            </div>

            <!-- ── Busca ──────────────────────────────────────────────────── -->
            <SearchInput
                v-model="search"
                placeholder="Buscar por nome ou descrição..."
                max-width="340px"
            />

            <!-- ── Perfis do sistema (pré-definidos pelo SaaS) ────────────── -->
            <template v-if="filteredSystemProfiles.length > 0">
                <h6 class="text-uppercase text-muted fs-12 fw-semibold mt-3 mb-2">
                    <i class="ti ti-building-store me-1"></i>Perfis do sistema
                </h6>
                <div class="row g-3 mb-4">
                    <div v-for="profile in filteredSystemProfiles" :key="profile.value" class="col-sm-6 col-lg-4 col-xl-3">
                        <div class="card card-body h-100 border-primary-subtle bg-light-subtle">
                            <div class="d-flex align-items-start justify-content-between mb-2">
                                <h6 class="mb-0 fw-semibold text-truncate" :title="profile.label">
                                    <i class="ti ti-shield-check me-1 text-primary"></i>{{ profile.label }}
                                </h6>
                                <span class="badge badge-soft-primary rounded fs-11 flex-shrink-0 ms-1">
                                    <i class="ti ti-lock me-1"></i>Padrão
                                </span>
                            </div>
                            <p class="small text-muted mb-0">{{ profile.description }}</p>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── Perfis customizados da clínica ─────────────────────────── -->
            <h6 class="text-uppercase text-muted fs-12 fw-semibold mt-3 mb-2">
                <i class="ti ti-adjustments me-1"></i>Perfis customizados
            </h6>

            <!-- ── Empty state ────────────────────────────────────────────── -->
            <div v-if="filteredRoles.length === 0" class="text-center text-muted py-5">
                <i class="ti ti-shield-off fs-1 mb-2 d-block opacity-40"></i>
                <p class="small mb-0">
                    {{ roles.length === 0 ? 'Nenhum perfil customizado cadastrado. Os perfis do sistema acima já cobrem os papéis padrão da clínica.' : 'Nenhum perfil encontrado para esta busca.' }}
                </p>
            </div>

            <!-- ── Cards ──────────────────────────────────────────────────── -->
            <div v-else class="row g-3">
                <div v-for="role in filteredRoles" :key="role.id" class="col-sm-6 col-lg-4 col-xl-3">
                    <div class="card card-body h-100">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <div class="min-w-0">
                                <h6 class="mb-0 fw-semibold text-truncate" :title="role.name">
                                    <i class="ti ti-shield-lock me-1 text-primary"></i>{{ role.name }}
                                </h6>
                            </div>
                        </div>

                        <p v-if="role.description" class="small text-muted mb-2" style="min-height:2.5em;">
                            {{ role.description }}
                        </p>
                        <p v-else class="small text-muted fst-italic mb-2" style="min-height:2.5em;">
                            Sem descrição
                        </p>

                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <span class="badge badge-soft-info rounded fs-11">
                                <i class="ti ti-key me-1"></i>{{ role.permissions.length }}
                                {{ role.permissions.length === 1 ? 'permissão' : 'permissões' }}
                            </span>
                            <span class="badge badge-soft-secondary rounded fs-11">
                                <i class="ti ti-users me-1"></i>{{ role.users_count }}
                                {{ role.users_count === 1 ? 'usuário' : 'usuários' }}
                            </span>
                        </div>

                        <hr class="my-2">

                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                icon="ti ti-edit"
                                title="Editar"
                                @click="openEdit(role)"
                            />
                            <ActionIconButton
                                icon="ti ti-trash"
                                title="Excluir"
                                variant="danger"
                                @click="onDelete(role)"
                            />
                        </ActionIconGroup>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Form modal ─────────────────────────────────────────────────── -->
        <RoleFormModal
            :open="modalOpen"
            :role="editingRole"
            :available-permissions="availablePermissions"
            :routes="routes"
            @close="closeModal"
        />
    </AppLayout>
</template>
