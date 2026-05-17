<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout          from '@/Layouts/AppLayout.vue';
import PageHeader         from '@/Components/Panel/PageHeader.vue';
import SearchInput        from '@/Components/Panel/SearchInput.vue';
import EntityTable        from './EntityTable.vue';
import EntityCards        from './EntityCards.vue';
import EntityFormModal    from './EntityFormModal.vue';
import EntityDetailDrawer from './EntityDetailDrawer.vue';

const props = defineProps({
    entities: { type: Object, required: true },
    total:    { type: Number, default: 0 },
    filters:  { type: Object, default: () => ({}) },
    t:        { type: Object, default: () => ({}) },
});

// ── View toggle ──────────────────────────────────────────────────────────────
const view = ref(localStorage.getItem('mgr_entities_view') ?? 'table');
function setView(v) { view.value = v; localStorage.setItem('mgr_entities_view', v); }

// ── Search / sort ─────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('panel.manager.entities.index'),
            { search: val, sort: props.filters.sort, direction: props.filters.direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
});

function onSort({ sort, direction }) {
    router.get(
        route('panel.manager.entities.index'),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true },
    );
}

// ── Form modal ────────────────────────────────────────────────────────────────
const formOpen     = ref(false);
const editEntityId = ref(null);

function openCreate() { editEntityId.value = null; formOpen.value = true; }
function openEdit(id) { editEntityId.value = id;   formOpen.value = true; }
function closeForm()  { formOpen.value = false; editEntityId.value = null; }

// ── Detail drawer ─────────────────────────────────────────────────────────────
const detailOpen = ref(false);
const detailId   = ref(null);

function openDetail(id) { detailId.value = id; detailOpen.value = true; }
function closeDetail()  { detailOpen.value = false; detailId.value = null; }

// ── Actions ───────────────────────────────────────────────────────────────────
function onDelete(id) {
    if (!confirm(props.t.confirm_delete ?? 'Tem certeza?')) return;
    router.delete(route('panel.manager.entities.destroy', id), { preserveScroll: true });
}

function onToggleActive(id, currentActive) {
    router.put(
        route('panel.manager.entities.update', id),
        { active: !currentActive, type_method: 'toggle' },
        { preserveScroll: true },
    );
}

const breadcrumbs = [
    { label: props.t.breadcrumb_home    ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_current ?? 'Empresas',  url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div>

            <!-- ── Page Header ──────────────────────────────────────────── -->
            <PageHeader
                :title="t.page_title"
                :total="total"
                :total-label="t.total_label"
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
                @set-view="setView"
            >
                <template #actions>
                    <button type="button" class="btn btn-primary fs-13" @click="openCreate">
                        <i class="ti ti-plus me-1"></i> {{ t.btn_new }}
                    </button>
                </template>
            </PageHeader>

            <!-- ── Search ───────────────────────────────────────────────── -->
            <SearchInput
                v-model="search"
                :placeholder="t.search_placeholder"
                max-width="380px"
            />

            <!-- ── Table / Cards ─────────────────────────────────────────── -->
            <EntityTable
                v-if="view === 'table'"
                :entities="entities"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @view="openDetail"
                @edit="openEdit"
                @delete="onDelete"
                @toggle-active="onToggleActive"
            />
            <EntityCards
                v-else
                :cards-url="route('panel.manager.entities.cards')"
                :initial-search="search"
                :t="t"
                @view="openDetail"
                @edit="openEdit"
                @delete="onDelete"
                @toggle-active="onToggleActive"
            />
        </div>

        <!-- Form offcanvas -->
        <EntityFormModal
            :open="formOpen"
            :entity-id="editEntityId"
            :t="t"
            @close="closeForm"
        />

        <!-- Detail drawer -->
        <EntityDetailDrawer
            :open="detailOpen"
            :entity-id="detailId"
            :t="t"
            @close="closeDetail"
            @edit="(id) => { closeDetail(); openEdit(id); }"
        />
    </AppLayout>
</template>
