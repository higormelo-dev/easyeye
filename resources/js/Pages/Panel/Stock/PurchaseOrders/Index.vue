<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import PurchaseOrderFormModal   from './PurchaseOrderFormModal.vue';
import ReceivePurchaseOrderModal from './ReceivePurchaseOrderModal.vue';

const props = defineProps({
    breadcrumbs:   { type: Array,  default: () => [] },
    items:         { type: Object, required: true },
    suppliers:     { type: Array,  default: () => [] },
    products:      { type: Array,  default: () => [] },
    lotsByProduct: { type: Object, default: () => ({}) },
    filters:       { type: Object, default: () => ({}) },
    routes:        { type: Object, required: true },
});

const page = usePage();
const flashMessage = computed(() => page.props?.flash?.message ?? null);

const status     = ref(props.filters?.status ?? 'all');
const supplierId = ref(props.filters?.supplier_id ?? '');

function applyFilters() {
    router.get(props.routes.index, { status: status.value, supplier_id: supplierId.value }, { preserveState: true, preserveScroll: true, replace: true });
}
watch([status, supplierId], applyFilters);

const STATUS_LABELS = {
    draft: 'Rascunho', sent: 'Enviado ao fornecedor', partially_received: 'Recebido parcialmente', received: 'Recebido', cancelled: 'Cancelado',
};
const STATUS_BADGE = {
    draft: 'badge-soft-secondary', sent: 'badge-soft-info text-info', partially_received: 'badge-soft-warning text-warning',
    received: 'badge-soft-success text-success border border-success', cancelled: 'badge-soft-danger text-danger',
};

const formOpen = ref(false);
const editItem = ref(null);
function openCreate() { editItem.value = null; formOpen.value = true; }
async function openEdit(po) {
    const { data } = await window.axios.get(props.routes.show.replace('__ID__', po.id));
    editItem.value = data.data;
    formOpen.value = true;
}
function onSaved() { formOpen.value = false; router.reload({ only: ['items'] }); }

const receiveOpen = ref(false);
const receivingPo = ref(null);
function openReceive(po) { receivingPo.value = po; receiveOpen.value = true; }
function onReceived() { receiveOpen.value = false; router.reload({ only: ['items', 'products', 'lotsByProduct'] }); }

function onSend(event, po) {
    event?.stopPropagation?.();
    if (!confirm(`Enviar o pedido ${po.code} ao fornecedor?`)) return;
    router.post(props.routes.send.replace('__ID__', po.id), {}, { preserveScroll: true });
}

function onCancel(event, po) {
    event?.stopPropagation?.();
    if (!confirm(`Cancelar o pedido ${po.code}?`)) return;
    router.post(props.routes.cancel.replace('__ID__', po.id), {}, { preserveScroll: true });
}

function onDelete(event, po) {
    event?.stopPropagation?.();
    if (!confirm(`Excluir o rascunho ${po.code}?`)) return;
    router.delete(props.routes.destroy.replace('__ID__', po.id), { preserveScroll: true });
}

function money(v) { return `R$ ${Number(v).toFixed(2)}`; }
</script>

<template>
    <AppLayout title="Pedidos de compra" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <PageHeader title="Pedidos de compra" :total="items.total">
                <template #actions>
                    <Link :href="routes.suppliers_index" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="ti ti-truck-delivery me-1"></i>Fornecedores
                    </Link>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>Novo pedido
                    </button>
                </template>
            </PageHeader>

            <div v-if="flashMessage" class="alert alert-success alert-dismissible fade show mb-3">
                <i class="ti ti-circle-check me-1"></i>{{ flashMessage }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <select v-model="status" class="form-select form-select-sm" style="max-width: 220px;">
                    <option value="all">Todos os status</option>
                    <option value="draft">Rascunho</option>
                    <option value="sent">Enviado ao fornecedor</option>
                    <option value="partially_received">Recebido parcialmente</option>
                    <option value="received">Recebido</option>
                    <option value="cancelled">Cancelado</option>
                </select>
                <select v-model="supplierId" class="form-select form-select-sm" style="max-width: 220px;">
                    <option value="">Todos os fornecedores</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>

            <div v-if="items.data.length === 0" class="text-center text-muted py-5">
                <i class="ti ti-shopping-cart-off fs-1 d-block mb-2"></i>
                Nenhum pedido de compra cadastrado.
            </div>

            <div v-else class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Fornecedor</th>
                            <th>Data</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="po in items.data" :key="po.id" role="button" @click="po.is_editable ? openEdit(po) : null">
                            <td class="text-muted small">{{ po.code }}</td>
                            <td>{{ po.supplier_name }}</td>
                            <td class="small">{{ po.order_date }}</td>
                            <td class="text-end">{{ money(po.total_amount) }}</td>
                            <td>
                                <span class="badge rounded fs-11 fw-medium" :class="STATUS_BADGE[po.status]">{{ STATUS_LABELS[po.status] }}</span>
                            </td>
                            <td class="text-end" @click.stop>
                                <ActionIconButton icon="ti ti-file-download" title="Baixar PDF" :href="routes.pdf.replace('__ID__', po.id)" />
                                <ActionIconButton v-if="po.status === 'draft'" icon="ti ti-send" title="Enviar ao fornecedor" @click="onSend($event, po)" />
                                <ActionIconButton v-if="['sent', 'partially_received'].includes(po.status)" icon="ti ti-package-import" title="Receber" variant="success" @click="openReceive(po)" />
                                <ActionIconButton v-if="!['received', 'cancelled'].includes(po.status)" icon="ti ti-x" title="Cancelar" variant="danger" @click="onCancel($event, po)" />
                                <ActionIconButton v-if="po.status === 'draft'" icon="ti ti-trash" title="Excluir" variant="danger" @click="onDelete($event, po)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <TablePagination :data="items" showing-suffix="pedidos" />

            <PurchaseOrderFormModal
                :open="formOpen"
                :item="editItem"
                :routes="routes"
                :suppliers="suppliers"
                :products="products"
                @close="formOpen = false"
                @saved="onSaved"
            />

            <ReceivePurchaseOrderModal
                :open="receiveOpen"
                :purchase-order="receivingPo"
                :routes="routes"
                :lots-by-product="lotsByProduct"
                @close="receiveOpen = false"
                @saved="onReceived"
            />

        </div>
    </AppLayout>
</template>
