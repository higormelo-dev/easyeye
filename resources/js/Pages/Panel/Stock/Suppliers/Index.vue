<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import SearchInput      from '@/Components/Panel/SearchInput.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import SupplierFormModal from './SupplierFormModal.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    items:       { type: Object, required: true },
    filters:     { type: Object, default: () => ({}) },
    routes:      { type: Object, required: true },
});

const page = usePage();
const flashMessage = computed(() => page.props?.flash?.message ?? null);

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? 'all');

function applyFilters() {
    router.get(props.routes.index, { search: search.value, status: status.value }, { preserveState: true, preserveScroll: true, replace: true });
}

let searchTimer = null;
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400); });
watch(status, applyFilters);

const formOpen = ref(false);
const editItem = ref(null);

function openCreate() { editItem.value = null; formOpen.value = true; }
function openEdit(supplier) { editItem.value = supplier; formOpen.value = true; }
function onSaved() { formOpen.value = false; router.reload({ only: ['items'] }); }

function onDelete(event, supplier) {
    event?.stopPropagation?.();
    if (!confirm(`Desativar o fornecedor "${supplier.name}"?`)) return;
    router.delete(props.routes.destroy.replace('__ID__', supplier.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Fornecedores" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <PageHeader title="Fornecedores" :total="items.total">
                <template #actions>
                    <Link :href="routes.purchase_orders_index" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="ti ti-shopping-cart me-1"></i>Pedidos de compra
                    </Link>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>Novo fornecedor
                    </button>
                </template>
            </PageHeader>

            <div v-if="flashMessage" class="alert alert-success alert-dismissible fade show mb-3">
                <i class="ti ti-circle-check me-1"></i>{{ flashMessage }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <SearchInput v-model="search" placeholder="Buscar por nome ou documento..." style="min-width: 260px;" />
                <select v-model="status" class="form-select form-select-sm" style="max-width: 160px;">
                    <option value="all">Todos</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                </select>
            </div>

            <div v-if="items.data.length === 0" class="text-center text-muted py-5">
                <i class="ti ti-truck-off fs-1 d-block mb-2"></i>
                Nenhum fornecedor cadastrado.
            </div>

            <div v-else class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nome</th>
                            <th>Documento</th>
                            <th>Contato</th>
                            <th>Telefone</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in items.data" :key="s.id" role="button" @click="openEdit(s)">
                            <td class="text-muted small">{{ s.code }}</td>
                            <td>{{ s.name }}</td>
                            <td>{{ s.document ?? '—' }}</td>
                            <td>{{ s.contact_name ?? '—' }}</td>
                            <td>{{ s.phone ?? '—' }}</td>
                            <td>
                                <span class="badge rounded fs-11 fw-medium" :class="s.active ? 'badge-soft-success text-success border border-success' : 'badge-soft-secondary'">
                                    {{ s.active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <ActionIconButton icon="ti ti-trash" title="Desativar" variant="danger" @click="onDelete($event, s)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <TablePagination :data="items" showing-suffix="fornecedores" />

            <SupplierFormModal :open="formOpen" :item="editItem" :routes="routes" @close="formOpen = false" @saved="onSaved" />

        </div>
    </AppLayout>
</template>
