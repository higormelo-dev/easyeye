<script setup>
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout     from '@/Layouts/AppLayout.vue';
import PageHeader    from '@/Components/Panel/PageHeader.vue';
import SearchInput   from '@/Components/Panel/SearchInput.vue';
import UserTable     from './UserTable.vue';
import UserCards     from './UserCards.vue';
import UserFormModal from './UserFormModal.vue';

const props = defineProps({
    users:    { type: Object,  required: true },
    total:    { type: Number,  default: 0 },
    roles:    { type: Object,  default: () => ({}) },
    isClient: { type: Boolean, default: true },
    filters:  { type: Object,  default: () => ({}) },
    t:        { type: Object,  default: () => ({}) },
});

// ── View toggle ───────────────────────────────────────────────────────────────
const view = ref(localStorage.getItem('users_view') ?? 'table');
function setView(v) { view.value = v; localStorage.setItem('users_view', v); }

// ── Search / sort ─────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('panel.accesscontrol.users.index'),
            { search: val, sort: props.filters.sort, direction: props.filters.direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
});

function onSort({ sort, direction }) {
    router.get(
        route('panel.accesscontrol.users.index'),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true },
    );
}

// ── CRUD actions ──────────────────────────────────────────────────────────────
const modalOpen  = ref(false);
const editUserId = ref(null);

function openCreate() { editUserId.value = null; modalOpen.value = true; }
function openEdit(id) { editUserId.value = id;   modalOpen.value = true; }
function closeModal()  { modalOpen.value = false; editUserId.value = null; }

function onDelete(id) {
    if (!confirm(props.t.confirm_delete)) return;
    router.delete(route('panel.accesscontrol.users.destroy', id), { preserveScroll: true });
}

function onRestore(id) {
    if (!confirm(props.t.confirm_restore)) return;
    router.get(route('panel.accesscontrol.users.restore', id), {}, { preserveScroll: true });
}

function onToggleActive(id, currentActive) {
    router.put(
        route('panel.accesscontrol.users.update', id),
        { active: !currentActive, type_method: 'toggle' },
        { preserveScroll: true },
    );
}

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs = [
    { label: props.t.breadcrumb_home    ?? 'Dashboard', url: route('panel.dashboard') },
    { label: props.t.breadcrumb_current ?? 'Usuários' },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <!-- ── Header ─────────────────────────────────────────────────── -->
            <PageHeader
                :title="t.page_title"
                :total="total"
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
                :show-view-toggle="true"
                @set-view="setView"
            >
                <template #actions>
                    <Link :href="route('panel.accesscontrol.roles.index')" class="btn btn-light btn-sm">
                        <i class="ti ti-shield-lock me-1"></i>Perfis e permissões
                    </Link>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>{{ t.new_user }}
                    </button>
                </template>
            </PageHeader>

            <!-- ── Search ─────────────────────────────────────────────────── -->
            <div class="mb-3">
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder"
                    max-width="340px"
                />
            </div>

            <!-- ── Table / Cards ──────────────────────────────────────────── -->
            <UserTable
                v-if="view === 'table'"
                :users="users"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @edit="openEdit"
                @delete="onDelete"
                @restore="onRestore"
                @toggle-active="onToggleActive"
            />

            <UserCards
                v-else
                :cards-url="route('panel.accesscontrol.users.cards')"
                :initial-search="search"
                :t="t"
                @edit="openEdit"
                @delete="onDelete"
                @restore="onRestore"
                @toggle-active="onToggleActive"
            />

        </div>

        <!-- ── Form modal ─────────────────────────────────────────────────── -->
        <UserFormModal
            :open="modalOpen"
            :user-id="editUserId"
            :roles="roles"
            :is-client="isClient"
            :t="t"
            @close="closeModal"
        />
    </AppLayout>
</template>
