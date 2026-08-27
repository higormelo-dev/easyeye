<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout         from '@/Layouts/AppLayout.vue';
import PageHeader        from '@/Components/Panel/PageHeader.vue';
import SearchInput       from '@/Components/Panel/SearchInput.vue';
import ActionDropdown    from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton  from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup   from '@/Components/Panel/ActionIconGroup.vue';
import CatalogFormModal  from './CatalogFormModal.vue';
import CatalogDetailDrawer from './CatalogDetailDrawer.vue';

/**
 * Página GENÉRICA de catálogo clínico, renderizada por TODOS os 11 catálogos
 * (skintypes, iristypes, additiontypes, covertesttypes, surgerytypes,
 * visualacuitytypes, lenses, covenants, nearpointconvergences, visittypes,
 * colorvisiontypes).
 *
 * Os controllers Laravel injetam `columns` e `fields` via Inertia props,
 * descrevendo schema da tabela e do form. Esta página NÃO conhece detalhes
 * de cada catálogo — é puramente schema-driven.
 *
 * Tipos de coluna suportados:
 *   - text     : valor cru
 *   - code     : <code> monospace
 *   - abbrev   : badge soft-info pequena
 *   - color    : amostra de cor + hex
 *   - yesno    : Sim/Não
 *   - numeric  : número formatado pt-BR
 *
 * Tipos de campo suportados (no FormModal):
 *   - text | numeric | color | checkbox | select
 */
const props = defineProps({
    meta:         { type: Object, required: true },
    breadcrumbs:  { type: Array,  default: () => [] },
    columns:      { type: Array,  required: true },
    fields:       { type: Array,  required: true },
    crudFields:   { type: Object, required: true },
    routes:       { type: Object, required: true },   // { index, store, cards }
    urlTemplates: { type: Object, required: true },   // { show, update, destroy, restore }
    items:        { type: Array,  default: () => [] },
    filters:      { type: Object, default: () => ({}) },
    t:            { type: Object, default: () => ({}) },
    // Tab-bar de navegação entre catálogos irmãos (ex.: os 8 sub-catálogos
    // oftalmológicos agrupados sob "Parâmetros oftalmológicos"). `null`/vazio
    // quando o catálogo não participa de nenhum grupo — tab-bar fica oculta.
    // Cada aba é um <a href> de navegação real (full-reload), não estado JS.
    tabsGroup:    { type: Array,  default: () => null },
});

// ── Search com debounce ─────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(props.routes.index, { search: val }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 400);
});

// ── Form modal ──────────────────────────────────────────────────────────────
const formOpen   = ref(false);
const editingId  = ref(null);

function openCreate() {
    editingId.value = null;
    formOpen.value  = true;
}

function openEdit(item) {
    editingId.value = item.id;
    formOpen.value  = true;
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
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
}

// ── Ações: toggleActive / onDelete / onRestore ──────────────────────────────
async function toggleActive(item) {
    // Patch via update genérico — backend respeita "active" no crudFields.
    const url = urlFor('update', item.id);
    const res = await fetch(url, {
        method: 'POST',  // _method=PATCH (compatível com FormRequest)
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
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
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    const json = await res.json();
    showToast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['items', 'meta'] });
}

async function onRestore(item) {
    if (!confirm(props.t.confirm_restore ?? 'Restaurar este registro?')) return;
    const res = await fetch(urlFor('restore', item.id), {
        method: 'GET',  // rota legada é GET; mantemos compatibilidade
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    const json = await res.json();
    showToast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['items', 'meta'] });
}

// ── Cell rendering ──────────────────────────────────────────────────────────
function cellValue(item, col) {
    const v = item[col.key];
    if (v === null || v === undefined || v === '') return '—';
    return v;
}

const totalLabel = computed(() => `${props.t.total_label ?? 'Total:'} ${props.items.length}`);
</script>

<template>
    <AppLayout :title="meta.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <PageHeader :title="meta.title" :subtitle="totalLabel">
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>{{ t.btn_new ?? 'Novo' }}
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

            <!-- Toolbar -->
            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder ?? 'Buscar...'"
                    style="min-width: 280px;"
                />
            </div>

            <!-- Tabela schema-driven -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th v-for="col in columns" :key="col.key">{{ col.label }}</th>
                                <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                                <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="items.length === 0">
                                <td :colspan="columns.length + 2" class="text-center text-muted py-5">
                                    <i class="ti ti-folder-off fs-1 d-block mb-2"></i>
                                    {{ t.empty_list ?? 'Nenhum registro.' }}
                                </td>
                            </tr>
                            <tr
                                v-for="item in items"
                                :key="item.id"
                                :class="{ 'table-secondary opacity-75': item.deleted }"
                            >
                                <!-- Coluna schema-driven -->
                                <td v-for="col in columns" :key="col.key">
                                    <!-- Texto cru -->
                                    <template v-if="!col.type || col.type === 'text'">
                                        <span :class="col.key === 'name' ? 'fw-medium' : 'text-muted'">
                                            {{ cellValue(item, col) }}
                                        </span>
                                    </template>

                                    <!-- Código monospace -->
                                    <code v-else-if="col.type === 'code'" class="text-muted small">
                                        {{ cellValue(item, col) }}
                                    </code>

                                    <!-- Badge abreviação -->
                                    <span v-else-if="col.type === 'abbrev'" class="badge badge-soft-info rounded fs-11 fw-medium">
                                        {{ cellValue(item, col) }}
                                    </span>

                                    <!-- Color swatch + hex -->
                                    <span v-else-if="col.type === 'color'" class="d-inline-flex align-items-center gap-1">
                                        <span
                                            class="rounded-circle border"
                                            :style="`background:${item[col.key] ?? '#ccc'}; width:18px; height:18px; display:inline-block;`"
                                        ></span>
                                        <code class="small text-muted">{{ cellValue(item, col) }}</code>
                                    </span>

                                    <!-- Yes / No -->
                                    <span v-else-if="col.type === 'yesno'"
                                          :class="`badge rounded fs-11 ${item[col.key] ? 'badge-soft-success text-success border border-success' : 'badge-soft-secondary'}`">
                                        {{ item[col.key] ? (t.yes ?? 'Sim') : (t.no ?? 'Não') }}
                                    </span>

                                    <!-- Numérico -->
                                    <span v-else-if="col.type === 'numeric'" class="font-monospace small">
                                        {{ Number(item[col.key] ?? 0).toLocaleString('pt-BR') }}
                                    </span>
                                </td>

                                <!-- Status -->
                                <td class="text-center">
                                    <span v-if="item.deleted" class="badge badge-soft-secondary rounded fs-13 fw-medium">
                                        {{ t.status_deleted ?? 'Removido' }}
                                    </span>
                                    <span v-else-if="item.active"
                                          class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium">
                                        {{ t.status_active ?? 'Ativo' }}
                                    </span>
                                    <span v-else class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium">
                                        {{ t.status_inactive ?? 'Inativo' }}
                                    </span>
                                    <span v-if="item.is_global"
                                          class="badge badge-soft-info rounded ms-1 fs-11" :title="t.status_global">
                                        <i class="ti ti-star"></i>
                                    </span>
                                </td>

                                <!-- Ações -->
                                <td class="text-end">
                                    <ActionIconGroup align="end" gap="tight">
                                        <ActionIconButton
                                            v-if="item.deleted"
                                            icon="ti ti-recycle"
                                            :title="t.action_restore ?? 'Restaurar'"
                                            @click="onRestore(item)"
                                        />
                                        <template v-else>
                                            <ActionIconButton
                                                icon="ti ti-eye"
                                                :title="t.action_view ?? 'Ver'"
                                                @click="openDetail(item)"
                                            />
                                            <ActionDropdown
                                                btn-class="ee-action-icon ee-action-icon--default"
                                                icon="ti ti-dots-vertical"
                                            >
                                                <li>
                                                    <button class="dropdown-item rounded-1" @click="openEdit(item)">
                                                        <i class="ti ti-edit me-1"></i> {{ t.action_edit ?? 'Editar' }}
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item rounded-1" @click="toggleActive(item)">
                                                        <i :class="`ti me-1 ${item.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                                        {{ item.active ? (t.action_deactivate ?? 'Desativar') : (t.action_activate ?? 'Ativar') }}
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button class="dropdown-item rounded-1 text-danger" @click="onDelete(item)">
                                                        <i class="ti ti-trash me-1"></i> {{ t.action_delete ?? 'Excluir' }}
                                                    </button>
                                                </li>
                                            </ActionDropdown>
                                        </template>
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
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
                @edit="(item) => { detailOpen = false; openEdit(item); }"
            />

        </div>
    </AppLayout>
</template>
