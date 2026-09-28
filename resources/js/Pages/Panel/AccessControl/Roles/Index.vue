<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout       from '@/Layouts/AppLayout.vue';
import PageHeader      from '@/Components/Panel/PageHeader.vue';
import SearchInput     from '@/Components/Panel/SearchInput.vue';
import { useViewMode } from '@/composables/useViewMode.js';
import { useTrans }    from '@/composables/useTrans.js';
import RoleTable       from './RoleTable.vue';
import RoleCards       from './RoleCards.vue';
import RoleFormModal   from './RoleFormModal.vue';

/**
 * Perfis de acesso customizados (RBAC granular ADITIVO por clínica) — mesmo
 * layout de Panel/Patients/Index: cabeçalho com total, alternância
 * tabela/cards (tabela como padrão, preferência persistida no navegador),
 * busca server-side que preserva a ordenação, e tabela/cards com as mesmas
 * ações. Os cards usam o MESMO paginator da tabela.
 *
 * Os perfis FIXOS da plataforma (ClientRule) ficam numa seção recolhível,
 * somente leitura. Textos vêm de lang/{locale}/access_control_roles.php
 * (prop `t`).
 */
const props = defineProps({
    breadcrumbs:          { type: Array,  default: () => [] },
    roles:                { type: Object, required: true },        // paginator Laravel (through())
    filters:              { type: Object, default: () => ({}) },   // { search, sort, direction } — normalizados
    // Perfis FIXOS da plataforma — somente leitura: [{ value, label, description }].
    systemProfiles:       { type: Array,  default: () => [] },
    availablePermissions: { type: Array,  default: () => [] },
    routes:               { type: Object, required: true },        // { index, store, update, destroy } — update/destroy com __ID__
    t:                    { type: Object, default: () => ({}) },
});

const { tx } = useTrans(() => props.t);
const { view, setView } = useViewMode('access_roles_view');

const page = usePage();
// Backend flasheia `message` (não `success`) em store/update/destroy e o
// toast do AppLayout só escuta success/error/status — alerta local.
const flashMessage = computed(() => page.props?.flash?.message ?? null);

// Fechar o alerta é estado local (sem data-bs-dismiss, que removeria do DOM
// um nó controlado pelo Vue). Cada flash novo volta a exibi-lo.
const flashDismissed = ref(false);
watch([() => page.props?.flash, flashMessage], () => {
    flashDismissed.value = false;
});

const pageTitle = computed(() => props.t.page_title ?? 'Perfis de acesso');

const emptyText = computed(() => (props.filters?.search
    ? (props.t.empty_search ?? 'Nenhum perfil encontrado para esta busca.')
    : (props.t.empty_list ?? 'Nenhum perfil customizado cadastrado.')));

// ── Busca (debounce) + ordenação — uma preserva a outra ─────────────────────
const search = ref(props.filters?.search ?? '');
let searchTimer = null;

function visit(params, options = {}) {
    router.get(props.routes.index, params, { preserveState: true, preserveScroll: true, ...options });
}

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        visit({ search: value, sort: props.filters?.sort, direction: props.filters?.direction }, { replace: true });
    }, 400);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

function onSort({ sort, direction }) {
    clearTimeout(searchTimer);
    visit({ search: search.value, sort, direction });
}

// ── Painel criar/editar ─────────────────────────────────────────────────────
const modalOpen   = ref(false);
const editingRole = ref(null);

function openCreate() { editingRole.value = null; modalOpen.value = true; }
function openEdit(role) { editingRole.value = role; modalOpen.value = true; }
function closeModal() { modalOpen.value = false; editingRole.value = null; }

// ── Exclusão ────────────────────────────────────────────────────────────────
function onDelete(role) {
    const key = role.users_count > 0 ? 'confirm_delete_with_users' : 'confirm_delete';
    if (!confirm(tx(key, { name: role.name, count: role.users_count }))) return;

    router.delete(props.routes.destroy.replace('__ID__', role.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-access-roles">

            <PageHeader
                :title="pageTitle"
                :total="roles.total ?? 0"
                :total-label="t.total_label ?? 'Total:'"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table ?? 'Tabela'"
                :view-cards-title="t.view_cards ?? 'Cards'"
                @set-view="setView"
            >
                <template #actions>
                    <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.btn_new ?? 'Novo perfil' }}
                    </button>
                </template>
            </PageHeader>

            <div v-if="flashMessage && !flashDismissed" class="alert alert-success alert-dismissible mb-3" role="status">
                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ flashMessage }}
                <button
                    type="button"
                    class="btn-close"
                    :aria-label="t.close ?? 'Fechar'"
                    @click="flashDismissed = true"
                ></button>
            </div>

            <!-- Perfis fixos da plataforma: contexto, não o foco da tela -->
            <details v-if="systemProfiles.length > 0" class="system-profiles border rounded mb-3">
                <summary class="system-profiles-summary d-flex align-items-center gap-2 px-3 py-2">
                    <i class="ti ti-chevron-right system-profiles-chevron text-muted" aria-hidden="true"></i>
                    <i class="ti ti-building-store text-primary" aria-hidden="true"></i>
                    <span class="fw-semibold">{{ t.system_profiles_title ?? 'Perfis do sistema' }}</span>
                    <span class="text-muted small">· {{ tx('system_profiles_count', { count: systemProfiles.length }) }}</span>
                </summary>

                <div class="px-3 pb-3">
                    <p class="small text-muted mb-3">
                        <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.notice }}
                    </p>
                    <ul class="row g-2 list-unstyled mb-0">
                        <li v-for="profile in systemProfiles" :key="profile.value" class="col-sm-6 col-lg-4 col-xl-3">
                            <div class="system-profile h-100 rounded border px-3 py-2">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                    <span class="fw-semibold small text-truncate">
                                        <i class="ti ti-shield-check me-1 text-primary" aria-hidden="true"></i>{{ profile.label }}
                                    </span>
                                    <span class="badge badge-soft-primary rounded fs-11 flex-shrink-0">
                                        <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ t.system_profile_badge ?? 'Padrão' }}
                                    </span>
                                </div>
                                <p class="small text-muted mb-0">{{ profile.description }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </details>

            <SearchInput
                v-model="search"
                :placeholder="t.search_placeholder ?? 'Buscar...'"
                :clear-label="t.search_clear ?? 'Limpar busca'"
                max-width="320px"
            />

            <RoleTable
                v-if="view === 'table'"
                :roles="roles"
                :filters="filters"
                :t="t"
                :empty-text="emptyText"
                @sort="onSort"
                @edit="openEdit"
                @delete="onDelete"
            />
            <RoleCards
                v-else
                :roles="roles"
                :t="t"
                :empty-text="emptyText"
                @edit="openEdit"
                @delete="onDelete"
            />
        </div>

        <RoleFormModal
            :open="modalOpen"
            :role="editingRole"
            :available-permissions="availablePermissions"
            :routes="routes"
            :t="t"
            @close="closeModal"
        />
    </AppLayout>
</template>

<style scoped>
.system-profiles {
    background: var(--bs-body-bg);
}
.system-profiles-summary {
    cursor: pointer;
    list-style: none;
    user-select: none;
}
.system-profiles-summary::-webkit-details-marker {
    display: none;
}
.system-profiles-summary:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
    border-radius: var(--bs-border-radius);
}
.system-profiles-chevron {
    transition: transform 160ms cubic-bezier(0.16, 1, 0.3, 1);
}
.system-profiles[open] .system-profiles-chevron {
    transform: rotate(90deg);
}
.system-profile {
    background: var(--bs-tertiary-bg);
}
@media (prefers-reduced-motion: reduce) {
    .system-profiles-chevron {
        transition: none;
    }
}
</style>
