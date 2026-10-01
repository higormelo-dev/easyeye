<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import CatalogTable from './CatalogTable.vue';
import CatalogCards from './CatalogCards.vue';
import CatalogFormModal from './CatalogFormModal.vue';
import CatalogDetailDrawer from './CatalogDetailDrawer.vue';

/**
 * Página GENÉRICA de catálogo clínico, renderizada por TODOS os catálogos
 * de BaseSettingController (skintypes, iristypes, additiontypes,
 * covertesttypes, surgerytypes, visualacuitytypes, lenses, covenants,
 * nearpointconvergences, visittypes, colorvisiontypes, resources, categorias
 * de produto).
 *
 * Mesmo layout de Panel/Patients/Index: cabeçalho com total + alternância
 * tabela/cards (persistida por catálogo no navegador), busca, tabela com
 * cabeçalhos ordenáveis e menu "Colunas" (CatalogTable) ou grid de cards
 * (CatalogCards). Schema-driven: `columns`/`fields` vêm do controller.
 */
const props = defineProps({
    meta: { type: Object, required: true }, // { title, cardsUrl, storageKey, ... }
    breadcrumbs: { type: Array, default: () => [] },
    columns: { type: Array, required: true },
    fields: { type: Array, required: true },
    crudFields: { type: Object, required: true },
    routes: { type: Object, required: true }, // { index, store, cards }
    urlTemplates: { type: Object, required: true }, // { show, update, destroy, restore }
    // Paginator do Laravel ({ data, links, current_page, last_page, total, ... }).
    items: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) }, // { search, sort, dir }
    // Colunas que o backend aceita ordenar (whitelist de BaseSettingController).
    sortable: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
    // Tab-bar de navegação entre catálogos irmãos (ex.: os 8 sub-catálogos
    // oftalmológicos agrupados sob "Parâmetros oftalmológicos"). `null`/vazio
    // quando o catálogo não participa de nenhum grupo — tab-bar fica oculta.
    // Cada aba é um <a href> de navegação real (full-reload), não estado JS.
    tabsGroup: { type: Array, default: () => null },
});

// ── Alternância tabela/cards (preferência por catálogo, no navegador) ────────
const storageKey = computed(() => props.meta.storageKey ?? 'catalog_view');

function readView() {
    try {
        return window.localStorage.getItem(storageKey.value) === 'cards' ? 'cards' : 'table';
    } catch {
        return 'table';
    }
}

const view = ref(typeof window === 'undefined' ? 'table' : readView());

function setView(v) {
    view.value = v;
    try {
        window.localStorage.setItem(storageKey.value, v);
    } catch {
        // Storage bloqueado/privado — a preferência só não persiste.
    }
}

// ── Busca (debounce) e ordenação — mantêm um ao outro, como em pacientes ────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

function visit(params, options = {}) {
    router.get(props.routes.index, params, { preserveState: true, preserveScroll: true, ...options });
}

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        visit({ search: val, sort: props.filters.sort, dir: props.filters.dir }, { replace: true });
    }, 400);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

function onSort({ sort, dir }) {
    visit({ search: search.value, sort, dir });
}

// ── Form modal ──────────────────────────────────────────────────────────────
const formOpen = ref(false);
const editingId = ref(null);

function openCreate() {
    editingId.value = null;
    formOpen.value = true;
}

function openEdit(item) {
    editingId.value = item.id;
    formOpen.value = true;
}

function onSaved() {
    formOpen.value = false;
    router.reload({ only: ['items', 'meta'] });
}

// ── Detail drawer ───────────────────────────────────────────────────────────
const detailOpen = ref(false);
const detailItem = ref(null);

function openDetail(item) {
    detailItem.value = item;
    detailOpen.value = true;
}

// ── Helpers de URL — substitui __ID__ pelo id real ──────────────────────────
function urlFor(action, id) {
    return (props.urlTemplates[action] ?? '').replace('__ID__', id);
}

// ── CSRF ────────────────────────────────────────────────────────────────────
function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function showToast(msg, type = 'success') {
    if (!msg) return;
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error' && window.showErrorToast) return window.showErrorToast(msg);
}

// ── Ações: toggleActive / onDelete / onRestore ──────────────────────────────
async function toggleActive(item) {
    // Patch via update genérico — backend respeita "active" no crudFields.
    const url = urlFor('update', item.id);
    const res = await fetch(url, {
        method: 'POST', // _method=PATCH (compatível com FormRequest)
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ ...crudPayloadFor(item), active: !item.active, _method: 'PATCH' }),
    });
    const json = await res.json();
    showToast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['items'] });
}

/**
 * Constrói o payload de update preservando os crudFields atuais do item
 * (mass-assignment guard do FormRequest exige todos os campos).
 */
function crudPayloadFor(item) {
    const payload = {};
    for (const key of Object.keys(props.crudFields)) {
        payload[key] = item[key];
    }
    return payload;
}

async function onDelete(item) {
    if (!confirm(props.t.confirm_delete ?? 'Excluir este registro?')) return;
    const res = await fetch(urlFor('destroy', item.id), {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    const json = await res.json();
    showToast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['items', 'meta'] });
}

async function onRestore(item) {
    if (!confirm(props.t.confirm_restore ?? 'Restaurar este registro?')) return;
    const res = await fetch(urlFor('restore', item.id), {
        method: 'GET', // rota legada é GET; mantemos compatibilidade
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    const json = await res.json();
    showToast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['items', 'meta'] });
}

// Total do filtro atual (todas as páginas), não só as linhas da página exibida.
const total = computed(() => props.items?.total ?? props.items?.data?.length ?? 0);
</script>

<template>
    <AppLayout :title="meta.title" :breadcrumbs="breadcrumbs">
        <div class="page-catalog">
            <PageHeader
                :title="meta.title"
                :total="total"
                :total-label="t.total_label ?? 'Total:'"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table ?? 'Tabela'"
                :view-cards-title="t.view_cards ?? 'Cards'"
                @set-view="setView"
            >
                <template #actions>
                    <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.btn_new ?? 'Novo' }}
                    </button>
                </template>
            </PageHeader>

            <!-- Tab-bar entre catálogos irmãos (ex.: sub-catálogos oftalmológicos).
                 Navegação real de página — cada aba é um <a href> normal. -->
            <ul v-if="tabsGroup?.length" class="nav nav-pills mb-3">
                <li v-for="tab in tabsGroup" :key="tab.url" class="nav-item">
                    <a :href="tab.url" class="nav-link" :class="{ active: tab.active }">
                        {{ tab.label }}
                    </a>
                </li>
            </ul>

            <SearchInput
                v-model="search"
                :placeholder="t.search_placeholder ?? 'Buscar...'"
                :clear-label="t.search_clear ?? 'Limpar busca'"
                max-width="280px"
            />

            <CatalogTable
                v-if="view === 'table'"
                :items="items"
                :columns="columns"
                :sortable="sortable"
                :filters="filters"
                :t="t"
                :storage-key="storageKey"
                @sort="onSort"
                @view="openDetail"
                @edit="openEdit"
                @toggle-active="toggleActive"
                @delete="onDelete"
                @restore="onRestore"
            />

            <CatalogCards
                v-else
                :cards-url="meta.cardsUrl ?? routes.cards"
                :search="filters.search ?? ''"
                :columns="columns"
                :t="t"
                @view="openDetail"
                @edit="openEdit"
                @toggle-active="toggleActive"
                @delete="onDelete"
                @restore="onRestore"
            />
        </div>

        <CatalogFormModal
            :open="formOpen"
            :item-id="editingId"
            :fields="fields"
            :crud-fields="crudFields"
            :url-templates="urlTemplates"
            :store-url="routes.store"
            :t="t"
            @close="formOpen = false"
            @saved="onSaved"
        />

        <CatalogDetailDrawer
            :open="detailOpen"
            :item="detailItem"
            :columns="columns"
            :t="t"
            @close="detailOpen = false"
            @edit="
                (item) => {
                    detailOpen = false;
                    openEdit(item);
                }
            "
        />
    </AppLayout>
</template>
