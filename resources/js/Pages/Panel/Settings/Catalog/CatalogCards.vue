<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';
import CatalogCell from './CatalogCell.vue';

/**
 * Modo cards do catálogo, no mesmo padrão de Patients/PatientCards: busca o
 * endpoint JSON `cards` (paginado no backend) e recarrega após qualquer visita
 * Inertia bem-sucedida (busca, criar/editar/excluir/restaurar), lendo a busca
 * atual do próprio evento — um único gatilho, sem requisição por tecla digitada.
 */
const props = defineProps({
    cardsUrl: { type: String, required: true },
    search:   { type: String, default: '' },
    columns:  { type: Array,  required: true },
    t:        { type: Object, default: () => ({}) },
});

const emit = defineEmits(['view', 'edit', 'toggleActive', 'delete', 'restore']);

const records = ref([]);
const meta    = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);
const failed  = ref(false);

// Colunas exibidas no corpo do card (o nome já é o título).
const detailColumns = computed(() => props.columns.filter((c) => c.key !== 'name'));

// Busca efetivamente carregada — NÃO comparar com props.search: no Inertia 3
// o prop do filho já foi atualizado quando o evento `success` dispara.
let loadedSearch = props.search ?? '';
// Descarta respostas fora de ordem (busca/paginação rápidas).
let requestSeq = 0;

async function fetchCards(page = 1, search = loadedSearch) {
    const seq = ++requestSeq;
    loadedSearch  = search ?? '';
    loading.value = true;
    failed.value  = false;
    try {
        const params = new URLSearchParams({ page, search: search ?? '' });
        const res    = await fetch(`${props.cardsUrl}?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const json    = await res.json();
        if (seq !== requestSeq) return;
        records.value = json.data ?? [];
        meta.value    = json.meta ?? { current_page: 1, last_page: 1, total: 0 };
    } catch {
        if (seq !== requestSeq) return;
        records.value = [];
        failed.value  = true;
    } finally {
        if (seq === requestSeq) loading.value = false;
    }
}

// Janela de páginas (atual ± 2, com primeira/última) — catálogos como
// convênios passam de 60 páginas; listar todas quebraria o layout.
const pageWindow = computed(() => {
    const { current_page: current, last_page: last } = meta.value;
    const pages = new Set([1, last]);
    for (let p = current - 2; p <= current + 2; p++) {
        if (p >= 1 && p <= last) pages.add(p);
    }
    const sorted = [...pages].sort((a, b) => a - b);

    return sorted.flatMap((p, i) => (i > 0 && p - sorted[i - 1] > 1 ? ['…', p] : [p]));
});

function goTo(page) {
    if (page < 1 || page > meta.value.last_page || page === meta.value.current_page) return;
    fetchCards(page);
}

let removeSuccessListener;
onMounted(() => {
    fetchCards(1);
    removeSuccessListener = router.on?.('success', (event) => {
        const search = event?.detail?.page?.props?.filters?.search ?? '';
        // Busca nova volta para a página 1; mesma busca (editar/excluir) mantém a página.
        fetchCards(search === loadedSearch ? meta.value.current_page : 1, search);
    });
});
onUnmounted(() => removeSuccessListener?.());
</script>

<template>
    <div v-if="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">{{ t.loading ?? 'Carregando...' }}</span>
        </div>
    </div>

    <div v-else-if="failed" class="alert alert-danger d-flex align-items-center gap-2" role="alert">
        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
        <span class="me-auto">{{ t.load_error ?? 'Não foi possível carregar os registros.' }}</span>
        <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            :title="t.retry ?? 'Tentar novamente'"
            :aria-label="t.retry ?? 'Tentar novamente'"
            @click="fetchCards(meta.current_page)"
        >
            <i class="ti ti-refresh" aria-hidden="true"></i>
        </button>
    </div>

    <template v-else>
        <div v-if="records.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-folder-off fs-1 mb-3 d-block" aria-hidden="true"></i>
            <p>{{ t.empty_list ?? 'Nenhum registro.' }}</p>
        </div>

        <div v-else class="row g-3">
            <div
                v-for="item in records"
                :key="item.id"
                class="col-12 col-sm-6 col-md-4 col-xl-3"
            >
                <div class="card card-body h-100" :class="{ 'opacity-75': item.deleted }">
                    <div class="d-flex align-items-start gap-2">
                        <h6 class="mb-1 fw-semibold lh-sm me-auto text-break">{{ item.name }}</h6>
                        <span
                            v-if="item.is_global"
                            class="badge badge-soft-info rounded fs-11"
                            :title="t.status_global"
                            :aria-label="t.status_global"
                        ><i class="ti ti-star" aria-hidden="true"></i></span>
                    </div>
                    <div class="mb-2">
                        <span v-if="item.deleted" class="badge badge-soft-secondary rounded fs-12">
                            {{ t.status_deleted ?? 'Removido' }}
                        </span>
                        <span
                            v-else
                            :class="item.active
                                ? 'badge badge-soft-success rounded text-success border border-success fs-12'
                                : 'badge badge-soft-danger rounded text-danger border border-danger fs-12'"
                        >{{ item.active ? (t.status_active ?? 'Ativo') : (t.status_inactive ?? 'Inativo') }}</span>
                    </div>

                    <dl class="small text-muted mb-1">
                        <div v-for="col in detailColumns" :key="col.key" class="d-flex align-items-center gap-1 mb-1">
                            <dt class="fw-semibold mb-0">{{ col.label }}:</dt>
                            <dd class="mb-0"><CatalogCell :item="item" :col="col" :t="t" /></dd>
                        </div>
                    </dl>

                    <hr class="my-2 mt-auto">

                    <ActionIconGroup align="end" gap="tight">
                        <ActionIconButton
                            v-if="item.mode === 'restore'"
                            icon="ti ti-recycle"
                            :title="t.action_restore ?? 'Restaurar'"
                            @click="emit('restore', item)"
                        />
                        <template v-else-if="item.mode === 'view_only' || item.mode === 'full'">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="t.action_view ?? 'Ver'"
                                @click="emit('view', item)"
                            />
                            <ActionDropdown
                                v-if="item.mode === 'full'"
                                :title="t.more_actions ?? 'Mais ações'"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" @click="emit('edit', item)">
                                        <i class="ti ti-edit me-1"></i> {{ t.action_edit ?? 'Editar' }}
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item rounded-1" @click="emit('toggleActive', item)">
                                        <i :class="`ti me-1 ${item.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                        {{ item.active ? (t.action_deactivate ?? 'Desativar') : (t.action_activate ?? 'Ativar') }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button class="dropdown-item rounded-1 text-danger" @click="emit('delete', item)">
                                        <i class="ti ti-trash me-1"></i> {{ t.action_delete ?? 'Excluir' }}
                                    </button>
                                </li>
                            </ActionDropdown>
                        </template>
                    </ActionIconGroup>
                </div>
            </div>
        </div>

        <div v-if="meta.last_page > 1" class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2">
            <p class="text-muted small mb-0">
                {{ t.pagination_showing ?? 'Exibindo' }} {{ records.length }}
                {{ t.pagination_of ?? 'de' }} {{ meta.total }} {{ t.pagination_suffix ?? '' }}
            </p>
            <nav :aria-label="t.pagination_label ?? 'Paginação'">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
                        <button type="button" class="page-link" :aria-label="t.pagination_previous ?? 'Anterior'" @click="goTo(meta.current_page - 1)">
                            <i class="ti ti-arrow-left" aria-hidden="true"></i>
                        </button>
                    </li>
                    <li
                        v-for="(p, i) in pageWindow"
                        :key="`${p}-${i}`"
                        class="page-item"
                        :class="{ active: p === meta.current_page, disabled: p === '…' }"
                    >
                        <span v-if="p === '…'" class="page-link">…</span>
                        <button
                            v-else
                            type="button"
                            class="page-link"
                            :aria-current="p === meta.current_page ? 'page' : undefined"
                            @click="goTo(p)"
                        >{{ p }}</button>
                    </li>
                    <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
                        <button type="button" class="page-link" :aria-label="t.pagination_next ?? 'Próxima'" @click="goTo(meta.current_page + 1)">
                            <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </button>
                    </li>
                </ul>
            </nav>
        </div>
    </template>
</template>
