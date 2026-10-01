<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout       from '@/Layouts/AppLayout.vue';
import PageHeader      from '@/Components/Panel/PageHeader.vue';
import SearchInput     from '@/Components/Panel/SearchInput.vue';
import { useViewMode } from '@/composables/useViewMode.js';
import { useTrans }    from '@/composables/useTrans.js';
import UserTable       from './UserTable.vue';
import UserCards       from './UserCards.vue';
import UserFormModal   from './UserFormModal.vue';
import UserInviteModal from './UserInviteModal.vue';
import UserInvitationsPending from './UserInvitationsPending.vue';

/**
 * Usuários da clínica — mesmo layout de Panel/Patients/Index: cabeçalho com
 * total, alternância tabela/cards (tabela como padrão, preferência
 * persistida no navegador), busca server-side que preserva a ordenação, e
 * tabela/cards com as mesmas ações sobre o MESMO paginator.
 * Textos vêm de lang/{locale}/access_control.php (prop `t`).
 */
const props = defineProps({
    breadcrumbs: { type: Array,   default: () => [] },
    users:       { type: Object,  required: true },        // paginator Laravel (through())
    roles:       { type: Object,  default: () => ({}) },   // perfil base: rule → rótulo
    isClient:    { type: Boolean, default: true },
    filters:     { type: Object,  default: () => ({}) },   // { search, sort, direction } — normalizados
    t:           { type: Object,  default: () => ({}) },
    // Convite a quem já usa o EasyEye: perfis convidáveis + pendentes.
    invitableRoles:     { type: Object, default: () => ({}) },
    pendingInvitations: { type: Array,  default: () => [] },
});

const inviteOpen = ref(false);
const canInvite = computed(() => Object.keys(props.invitableRoles).length > 0);

const { tx } = useTrans(() => props.t);
// Mesma chave de antes: quem já tinha escolhido cards continua em cards.
const { view, setView } = useViewMode('users_view');

const page = usePage();
// Backend flasheia `message` (não `success`) e o toast do AppLayout só escuta
// success/error/status — sem este alerta, salvar/excluir não dava retorno.
const flashMessage = computed(() => page.props?.flash?.message ?? null);

// Fechar o alerta é estado local (sem data-bs-dismiss, que removeria do DOM
// um nó controlado pelo Vue). Cada flash novo volta a exibi-lo.
const flashDismissed = ref(false);
watch([() => page.props?.flash, flashMessage], () => {
    flashDismissed.value = false;
});

const pageTitle = computed(() => props.t.page_title ?? 'Usuários');

const emptyText = computed(() => (props.filters?.search
    ? (props.t.empty_search ?? 'Nenhum usuário encontrado para esta busca.')
    : (props.t.empty ?? 'Nenhum usuário cadastrado.')));

// ── Busca (debounce) + ordenação — uma preserva a outra ─────────────────────
const search = ref(props.filters?.search ?? '');
let searchTimer = null;

function visit(params, options = {}) {
    router.get(route('panel.accesscontrol.users.index'), params, { preserveState: true, preserveScroll: true, ...options });
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
const editingUser = ref(null);

function openCreate() { editingUser.value = null; modalOpen.value = true; }
function openEdit(user) { editingUser.value = user; modalOpen.value = true; }
function closeModal() { modalOpen.value = false; editingUser.value = null; }

// ── Ações da linha/card ─────────────────────────────────────────────────────
function onDelete(user) {
    if (!confirm(tx('confirm_delete', { name: user.name }))) return;
    router.delete(route('panel.accesscontrol.users.destroy', user.id), { preserveScroll: true });
}

// PATCH (não GET): restaurar devolve o acesso e precisa do token CSRF.
function onRestore(user) {
    if (!confirm(tx('confirm_restore', { name: user.name }))) return;
    router.patch(route('panel.accesscontrol.users.restore', user.id), {}, { preserveScroll: true });
}

function onToggleActive(user) {
    router.put(
        route('panel.accesscontrol.users.update', user.id),
        { active: !user.active, type_method: 'toggle' },
        { preserveScroll: true },
    );
}
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-access-users">

            <PageHeader
                :title="pageTitle"
                :total="users.total ?? 0"
                :total-label="t.total_label ?? 'Total:'"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table ?? 'Tabela'"
                :view-cards-title="t.view_cards ?? 'Cards'"
                @set-view="setView"
            >
                <template #actions>
                    <div class="d-flex align-items-center gap-2">
                        <Link :href="route('panel.accesscontrol.roles.index')" class="btn btn-outline-secondary fs-13 btn-md">
                            <i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ t.roles_link ?? 'Perfis e permissões' }}
                        </Link>
                        <button v-if="canInvite" type="button" class="btn btn-outline-primary fs-13 btn-md" @click="inviteOpen = true">
                            <i class="ti ti-mail-forward me-1" aria-hidden="true"></i>{{ t.invitation?.button }}
                        </button>
                        <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.new_user ?? 'Novo usuário' }}
                        </button>
                    </div>
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

            <SearchInput
                v-model="search"
                :placeholder="t.search_placeholder ?? 'Buscar...'"
                :clear-label="t.search_clear ?? 'Limpar busca'"
                max-width="320px"
            />

            <UserTable
                v-if="view === 'table'"
                :users="users"
                :filters="filters"
                :t="t"
                :empty-text="emptyText"
                @sort="onSort"
                @edit="openEdit"
                @delete="onDelete"
                @restore="onRestore"
                @toggle-active="onToggleActive"
            />
            <UserCards
                v-else
                :users="users"
                :t="t"
                :empty-text="emptyText"
                @edit="openEdit"
                @delete="onDelete"
                @restore="onRestore"
                @toggle-active="onToggleActive"
            />
        </div>

        <UserInvitationsPending
            v-if="pendingInvitations.length"
            :invitations="pendingInvitations"
            :t="t"
        />

        <UserInviteModal
            :open="inviteOpen"
            :roles="invitableRoles"
            :t="t"
            @close="inviteOpen = false"
        />

        <UserFormModal
            :open="modalOpen"
            :user-id="editingUser?.id ?? null"
            :roles="roles"
            :is-client="isClient"
            :t="t"
            :lock-active="!!editingUser?.is_self"
            @close="closeModal"
        />
    </AppLayout>
</template>
