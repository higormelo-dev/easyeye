<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import SearchInput      from '@/Components/Panel/SearchInput.vue';
import { useViewMode }  from '@/composables/useViewMode.js';
import { useTrans }     from '@/composables/useTrans.js';
import IolLensTable     from './IolLensTable.vue';
import IolLensCards     from './IolLensCards.vue';
import IolLensFormModal from './IolLensFormModal.vue';

/**
 * Lentes de catarata (inventário IOL da clínica) — mesmo layout de
 * Panel/Patients/Index: cabeçalho com total, alternância tabela/cards
 * (tabela como padrão, preferência persistida no navegador), busca + filtro
 * de status que preservam a ordenação, e tabela/cards com as mesmas ações.
 * Os cards usam o MESMO paginator da tabela (busca/filtro server-side).
 * Textos vêm de lang/{locale}/stock_iollenses.php (prop `t`).
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    items:       { type: Object, required: true }, // paginator Laravel (through())
    filters:     { type: Object, default: () => ({}) }, // { search, status, sort, direction } — normalizados
    routes:      { type: Object, required: true },  // { index, store, search, show, update, destroy, movements_index }
    t:           { type: Object, default: () => ({}) },
});

const { tx } = useTrans(() => props.t);
const { view, setView } = useViewMode('stock_iollenses_view');

const page = usePage();
// Backend flasheia `message` (não `success`) em store/update/destroy e o
// toast do AppLayout só escuta success/error/status — alerta local.
const flashMessage = computed(() => page.props?.flash?.message ?? null);

// Fechar o alerta é estado local: `data-bs-dismiss` faria o Bootstrap remover
// do DOM um nó que o Vue controla. Cada flash novo volta a exibi-lo — mesmo
// com o texto repetido (ex.: duas edições seguidas trazem outro objeto flash).
const flashDismissed = ref(false);
watch([() => page.props?.flash, flashMessage], () => {
    flashDismissed.value = false;
});

const pageTitle = computed(() => props.t.page_title ?? 'Lentes de catarata');

// ── Busca (debounce) + status + ordenação — um preserva os outros ───────────
const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? 'all');

function currentParams(overrides = {}) {
    return {
        search:    search.value,
        status:    status.value,
        sort:      props.filters?.sort,
        direction: props.filters?.direction,
        ...overrides,
    };
}

function visit(params, options = {}) {
    router.get(props.routes.index, params, { preserveState: true, preserveScroll: true, ...options });
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
watch(status, applyFilters);

onBeforeUnmount(() => clearTimeout(searchTimer));

function onSort({ sort, direction }) {
    clearTimeout(searchTimer);
    visit(currentParams({ sort, direction }));
}

// ── Modal criar/editar ──────────────────────────────────────────────────────
const formOpen = ref(false);
const editItem = ref(null);

function openCreate() {
    editItem.value = null;
    formOpen.value = true;
}

function openEdit(lens) {
    editItem.value = lens;
    formOpen.value = true;
}

function onSaved() {
    formOpen.value = false;
    router.reload({ only: ['items'] });
}

// ── Ações da linha/card ─────────────────────────────────────────────────────
const actionError = ref(null);

// Ativar/desativar usa o mesmo update do modal: EntityIolLensRequest exige
// fabricante/modelo e o bridge regrava tipo/dioptrias/valor com o que vier,
// então o payload repete os dados da lente (sem imagem nem iol_lens_model_id
// — ambos ficam como estão quando ausentes). Esses dados vêm do registro
// ATUAL (routes.show, que re-checa posse), não da linha carregada — assim o
// clique em "Desativar" não desfaz uma edição de tipo/dioptria/valor feita
// depois que a página abriu. O status alvo é o inverso do que o usuário viu.
async function onToggleActive(lens) {
    actionError.value = null;
    let current;
    try {
        const { data } = await window.axios.get(props.routes.show.replace('__ID__', lens.id));
        current = data?.data;
    } catch {
        current = null;
    }
    if (!current) {
        actionError.value = props.t.toggle_error ?? 'Não foi possível carregar os dados atuais da lente.';
        return;
    }
    router.put(
        props.routes.update.replace('__ID__', lens.id),
        {
            manufacturer: current.manufacturer,
            model_name:   current.model_name,
            category:     current.category,
            diopter_min:  current.diopter_min,
            diopter_max:  current.diopter_max,
            price:        current.price,
            active:       !lens.active,
        },
        { preserveScroll: true },
    );
}

function onDelete(lens) {
    const name = [lens.manufacturer, lens.model_name].filter(Boolean).join(' ');
    if (!confirm(tx('confirm_delete', { name }))) return;
    router.delete(props.routes.destroy.replace('__ID__', lens.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-stock-iollenses">

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
                    <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.btn_new ?? 'Nova lente' }}
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

            <div v-if="actionError" class="alert alert-danger alert-dismissible mb-3" role="alert">
                <i class="ti ti-alert-circle me-1" aria-hidden="true"></i>{{ actionError }}
                <button
                    type="button"
                    class="btn-close"
                    :aria-label="t.close ?? 'Fechar'"
                    @click="actionError = null"
                ></button>
            </div>

            <!-- Busca + filtro de status (mesma linha) -->
            <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
                <SearchInput
                    v-model="search"
                    wrapper-class=""
                    :placeholder="t.search_placeholder ?? 'Buscar...'"
                    :clear-label="t.search_clear ?? 'Limpar busca'"
                    max-width="280px"
                />
                <select
                    v-model="status"
                    class="form-select form-select-sm w-auto"
                    :aria-label="t.filter_status_label ?? 'Status'"
                >
                    <option value="all">{{ t.filter_status_all ?? 'Todas' }}</option>
                    <option value="active">{{ t.filter_status_active ?? 'Ativas' }}</option>
                    <option value="inactive">{{ t.filter_status_inactive ?? 'Inativas' }}</option>
                </select>
            </div>

            <IolLensTable
                v-if="view === 'table'"
                :items="items"
                :filters="filters"
                :t="t"
                :movements-index-url="routes.movements_index"
                @sort="onSort"
                @edit="openEdit"
                @toggle-active="onToggleActive"
                @delete="onDelete"
            />
            <IolLensCards
                v-else
                :items="items"
                :t="t"
                :movements-index-url="routes.movements_index"
                @edit="openEdit"
                @toggle-active="onToggleActive"
                @delete="onDelete"
            />
        </div>

        <IolLensFormModal
            :open="formOpen"
            :item="editItem"
            :routes="routes"
            @close="formOpen = false"
            @saved="onSaved"
        />
    </AppLayout>
</template>
