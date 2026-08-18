<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import SearchInput      from '@/Components/Panel/SearchInput.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import IolLensFormModal from './IolLensFormModal.vue';

/**
 * Lentes de catarata (inventário IOL da clínica) — listagem em CARDS
 * (ver task original: grid, não tabela) com busca/filtro server-side
 * (paginação Inertia padrão, mesmo idioma de PatientsController/
 * CashFlowController — ver comentário em IolLensesController::index()).
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    items:       { type: Object, required: true }, // paginator Laravel (through())
    filters:     { type: Object, default: () => ({}) },
    routes:      { type: Object, required: true },  // { index, store, search, show, update, destroy }
});

const page = usePage();

// Backend flasheia `message` (não `success`) em store/update/destroy — ver
// IolLensesController — e o AppLayout global só escuta `flash.success`/
// `flash.error`/`flash.status` (HandleInertiaRequests::share()), então o
// toast automático do layout NUNCA dispararia pra essas 3 ações. Em vez de
// alterar o controller (fora do escopo desta tarefa — backend já pronto),
// mostramos esse flash localmente, mesmo padrão de alerta dismissível já
// usado em Patients/Import.vue.
const flashMessage = computed(() => page.props?.flash?.message ?? null);

// ── Filtros (search + status) ───────────────────────────────────────────
const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? 'all');

function applyFilters() {
    router.get(props.routes.index, { search: search.value, status: status.value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});
watch(status, applyFilters);

// ── Modal criar/editar ───────────────────────────────────────────────────
const formOpen  = ref(false);
const editItem  = ref(null);

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

// ── Exclusão ──────────────────────────────────────────────────────────────
// `event.stopPropagation()` explícito (em vez de `@click.stop` no template):
// ActionIconButton emite um evento CUSTOM ('click', repassando o Event nativo
// recebido), e o modificador `.stop` do Vue só reescreve com segurança
// listeners de evento NATIVO — em evento custom componentizado o comportamento
// não é garantido. Chamando stopPropagation() manualmente aqui evitamos que o
// clique no botão de excluir borbulhe pro `@click` do card (que abriria o
// offcanvas de edição junto com o confirm() de exclusão).
function onDelete(event, lens) {
    event?.stopPropagation?.();
    if (!confirm(`Excluir a lente "${lens.manufacturer} ${lens.model_name}"?`)) return;
    router.delete(props.routes.destroy.replace('__ID__', lens.id), { preserveScroll: true });
}

function statusLabel(active) {
    return active ? 'Ativa' : 'Inativa';
}
</script>

<template>
    <AppLayout title="Lentes de catarata" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <PageHeader title="Lentes de catarata" :total="items.total">
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>Nova lente
                    </button>
                </template>
            </PageHeader>

            <div v-if="flashMessage" class="alert alert-success alert-dismissible fade show mb-3">
                <i class="ti ti-circle-check me-1"></i>{{ flashMessage }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <!-- ── Filtros ──────────────────────────────────────────────────── -->
            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <SearchInput
                    v-model="search"
                    placeholder="Buscar por nome ou fabricante..."
                    style="min-width: 280px;"
                />
                <select v-model="status" class="form-select form-select-sm" style="max-width: 180px;">
                    <option value="all">Todas</option>
                    <option value="active">Ativas</option>
                    <option value="inactive">Inativas</option>
                </select>
            </div>

            <!-- ── Grid de cards ────────────────────────────────────────────── -->
            <div v-if="items.data.length === 0" class="text-center text-muted py-5">
                <i class="ti ti-eye-off fs-1 d-block mb-2"></i>
                Nenhuma lente cadastrada.
            </div>

            <div v-else class="row row-cols-1 row-cols-md-3 g-3">
                <div v-for="lens in items.data" :key="lens.id" class="col">
                    <div class="card h-100 iol-lens-card" role="button" @click="openEdit(lens)">
                        <div class="iol-lens-card__image">
                            <img v-if="lens.image_url" :src="lens.image_url" :alt="lens.model_name">
                            <i v-else class="ti ti-eye"></i>
                            <ActionIconButton
                                icon="ti ti-trash"
                                title="Excluir"
                                variant="danger"
                                class="iol-lens-card__delete"
                                @click="onDelete($event, lens)"
                            />
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <h6 class="mb-0 fw-semibold">Modelo: {{ lens.model_name }}</h6>
                                <span
                                    class="badge rounded fs-11 fw-medium text-nowrap"
                                    :class="lens.active
                                        ? 'badge-soft-success text-success border border-success'
                                        : 'badge-soft-secondary'"
                                >
                                    {{ statusLabel(lens.active) }}
                                </span>
                            </div>
                            <p class="text-muted small mb-1">Fabricante: {{ lens.manufacturer }}</p>
                            <p v-if="lens.diopter_range" class="text-muted small mb-1">
                                Dioptrias disponíveis: {{ lens.diopter_range }}
                            </p>
                            <p class="small mb-0 fw-medium">
                                Valor: {{ lens.price_formatted ?? 'Não informado' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <TablePagination :data="items" showing-suffix="lentes" />

            <IolLensFormModal
                :open="formOpen"
                :item="editItem"
                :routes="routes"
                @close="formOpen = false"
                @saved="onSaved"
            />

        </div>
    </AppLayout>
</template>

<style scoped>
.iol-lens-card {
    cursor: pointer;
    transition: box-shadow .15s ease, transform .15s ease;
}

.iol-lens-card:hover {
    box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .1);
    transform: translateY(-2px);
}

.iol-lens-card__image {
    position: relative;
    height: 160px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bs-tertiary-bg, #f8f9fa);
    border-bottom: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: calc(var(--bs-card-border-radius, .375rem) - 1px) calc(var(--bs-card-border-radius, .375rem) - 1px) 0 0;
    overflow: hidden;
}

.iol-lens-card__image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.iol-lens-card__image > i {
    font-size: 3rem;
    color: var(--bs-secondary-color, #adb5bd);
}

.iol-lens-card__delete {
    position: absolute;
    top: 8px;
    right: 8px;
    background: var(--white, #fff);
    box-shadow: 0 1px 4px rgba(0, 0, 0, .15);
}
</style>
