<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout          from '@/Layouts/AppLayout.vue';
import PageHeader         from '@/Components/Panel/PageHeader.vue';
import SearchInput        from '@/Components/Panel/SearchInput.vue';
import { useViewMode }    from '@/composables/useViewMode.js';
import { useTrans }       from '@/composables/useTrans.js';
import ReportSettingTable from './ReportSettingTable.vue';
import ReportSettingCards from './ReportSettingCards.vue';

/**
 * Modelos de documentação da clínica (receituários, atestados, laudos) —
 * mesmo layout de Panel/Patients/Index: cabeçalho com total, alternância
 * tabela/cards (preferência persistida no navegador), busca + filtros de
 * categoria e status server-side que preservam a ordenação, e tabela/cards
 * com as mesmas ações sobre o MESMO paginator.
 *
 * - Modelos próprios da clínica — totalmente editáveis;
 * - Modelos globais adotados (source_version controla atualizações) —
 *   "Reimportar" puxa a versão atual do modelo global.
 *
 * Textos vêm de lang/{locale}/report_settings.php (prop `t`).
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    categories:  { type: Array,  default: () => [] },        // [{ id, name }] ativas
    items:       { type: Object, required: true },           // paginator Laravel (through())
    filters:     { type: Object, default: () => ({}) },      // { search, category, status, sort, direction } — normalizados
    t:           { type: Object, default: () => ({}) },
    urls:        { type: Object, required: true },           // { index, create }
});

const { tx } = useTrans(() => props.t);
const { view, setView } = useViewMode('report_settings_view');

const page = usePage();
// Backend flasheia `message` (não `success`) e o toast do AppLayout só escuta
// success/error/status — alerta local. Erros (`error`) já saem no toast.
const flashMessage = computed(() => page.props?.flash?.message ?? null);

// Fechar o alerta é estado local (sem data-bs-dismiss, que removeria do DOM
// um nó controlado pelo Vue). Cada flash novo volta a exibi-lo.
const flashDismissed = ref(false);
watch([() => page.props?.flash, flashMessage], () => {
    flashDismissed.value = false;
});

const pageTitle = computed(() => props.t.page_title ?? 'Modelos de documentação');

const hasFilters = computed(() => Boolean(
    props.filters?.search || props.filters?.category || (props.filters?.status && props.filters.status !== 'all'),
));

const emptyText = computed(() => (hasFilters.value
    ? (props.t.empty_search ?? 'Nenhum modelo encontrado com estes filtros.')
    : (props.t.empty_list ?? 'Nenhum modelo cadastrado.')));

// ── Busca (debounce) + filtros + ordenação — um preserva os outros ──────────
const search   = ref(props.filters?.search ?? '');
const category = ref(props.filters?.category ?? '');
const status   = ref(props.filters?.status ?? 'all');

function currentParams(overrides = {}) {
    return {
        search:    search.value,
        category:  category.value,
        status:    status.value,
        sort:      props.filters?.sort,
        direction: props.filters?.direction,
        ...overrides,
    };
}

function visit(params, options = {}) {
    router.get(props.urls.index, params, { preserveState: true, preserveScroll: true, ...options });
}

let searchTimer = null;

function applyFilters() {
    clearTimeout(searchTimer);
    visit(currentParams(), { replace: true });
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});
watch([category, status], applyFilters);

onBeforeUnmount(() => clearTimeout(searchTimer));

function onSort({ sort, direction }) {
    clearTimeout(searchTimer);
    visit(currentParams({ sort, direction }));
}

// ── Ações ───────────────────────────────────────────────────────────────────
// Via router do Inertia (CSRF e redirect com a mensagem) — antes era fetch
// manual que ignorava erro e não dava retorno.
function onDelete(item) {
    if (!confirm(tx('confirm_delete', { title: item.title }))) return;
    router.delete(item.destroy_url, { preserveScroll: true });
}

function onReimport(item) {
    if (!item.reimport_url) return;
    if (!confirm(tx('confirm_reimport', { title: item.title }))) return;
    router.post(item.reimport_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-report-settings">

            <PageHeader
                :title="pageTitle"
                :total="items.total ?? 0"
                :total-label="t.total_label ?? 'Total:'"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table ?? 'Tabela'"
                :view-cards-title="t.view_cards ?? 'Cards'"
                @set-view="setView"
            >
                <template #actions>
                    <Link :href="urls.create" class="btn btn-primary fs-13 btn-md">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.btn_new ?? 'Novo modelo' }}
                    </Link>
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

            <!-- Busca + filtros (mesma linha) -->
            <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
                <SearchInput
                    v-model="search"
                    wrapper-class=""
                    :placeholder="t.search_placeholder ?? 'Buscar...'"
                    :clear-label="t.search_clear ?? 'Limpar busca'"
                    max-width="280px"
                />
                <select
                    v-if="categories.length > 0"
                    v-model="category"
                    class="form-select form-select-sm w-auto"
                    :aria-label="t.filter_category_label ?? 'Categoria'"
                >
                    <option value="">{{ t.filter_category_all ?? 'Todas as categorias' }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select
                    v-model="status"
                    class="form-select form-select-sm w-auto"
                    :aria-label="t.filter_status_label ?? 'Status'"
                >
                    <option value="all">{{ t.filter_status_all ?? 'Todos' }}</option>
                    <option value="active">{{ t.filter_status_active ?? 'Ativos' }}</option>
                    <option value="inactive">{{ t.filter_status_inactive ?? 'Inativos' }}</option>
                </select>
            </div>

            <ReportSettingTable
                v-if="view === 'table'"
                :items="items"
                :filters="filters"
                :t="t"
                :empty-text="emptyText"
                @sort="onSort"
                @reimport="onReimport"
                @delete="onDelete"
            />
            <ReportSettingCards
                v-else
                :items="items"
                :t="t"
                :empty-text="emptyText"
                @reimport="onReimport"
                @delete="onDelete"
            />
        </div>
    </AppLayout>
</template>
