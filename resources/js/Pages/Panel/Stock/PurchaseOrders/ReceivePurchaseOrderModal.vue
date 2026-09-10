<script setup>
import { ref, reactive, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

/**
 * Recebimento (total ou parcial) de um pedido enviado. Busca o pedido
 * COMPLETO via window.axios ao abrir (a listagem não traz itens, só o
 * resumo — ver PurchaseOrdersController::index()), pré-preenche a
 * quantidade de cada linha com o SALDO PENDENTE (`remaining_quantity`) —
 * usuário reduz manualmente o que não veio nesta entrega (recebimento
 * parcial), nunca aumenta além do pendente (mesma trava do backend).
 *
 * Lote: cada linha `requires_lot=true` exige lote — existente (seletor,
 * filtrado por `lotsByProduct`) OU novo (número + validade). Diferente do
 * consumo em prontuário, recebimento SEMPRE pode criar lote novo (é
 * sempre entrada).
 */
const props = defineProps({
    open:          { type: Boolean, required: true },
    purchaseOrder: { type: Object,  default: null }, // { id, code, supplier_name } — resumo da linha clicada
    routes:        { type: Object,  required: true }, // { show (__ID__), receive (__ID__) }
    lotsByProduct: { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const loading = ref(false);
const loadError = ref('');
const rows = ref([]); // linhas de trabalho (ver shape abaixo)
const lotMode = reactive({}); // { [poItemId]: 'existing' | 'new' }

const form = useForm({ items: [] });

function reset() {
    rows.value = [];
    loadError.value = '';
    form.clearErrors();
}

watch(() => props.open, async (val) => {
    if (!val || !props.purchaseOrder) { reset(); return; }
    reset();
    loading.value = true;

    try {
        const { data } = await window.axios.get(props.routes.show.replace('__ID__', props.purchaseOrder.id));
        const po = data.data;

        rows.value = (po.items ?? [])
            .filter((i) => i.remaining_quantity > 0)
            .map((i) => ({
                purchase_order_item_id: i.id,
                entity_product_id:      i.entity_product_id,
                product_name:           i.product_name,
                unit_label:             i.unit_label,
                requires_lot:           i.requires_lot,
                remaining_quantity:     i.remaining_quantity,
                quantity:               i.remaining_quantity,
                stock_lot_id:           '',
                new_lot_number:         '',
                new_lot_expiry_date:    '',
            }));

        rows.value.forEach((row) => { lotMode[row.purchase_order_item_id] = 'existing'; });
    } catch {
        loadError.value = 'Não foi possível carregar o pedido.';
    } finally {
        loading.value = false;
    }
});

function availableLots(entityProductId) {
    return props.lotsByProduct[entityProductId] ?? [];
}

function submit() {
    form.items = rows.value
        .filter((r) => Number(r.quantity) > 0)
        .map((r) => ({
            purchase_order_item_id: r.purchase_order_item_id,
            quantity:                r.quantity,
            stock_lot_id:            lotMode[r.purchase_order_item_id] === 'existing' ? (r.stock_lot_id || null) : null,
            new_lot_number:          lotMode[r.purchase_order_item_id] === 'new' ? (r.new_lot_number || null) : null,
            new_lot_expiry_date:     lotMode[r.purchase_order_item_id] === 'new' ? (r.new_lot_expiry_date || null) : null,
        }));

    form.post(props.routes.receive.replace('__ID__', props.purchaseOrder.id), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}

function close() {
    if (form.processing) return;
    emit('close');
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="680" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-package-import me-2 text-primary"></i>Receber pedido {{ purchaseOrder?.code }}
            </h5>
        </template>

        <div v-if="loading" class="text-muted py-3">Carregando...</div>
        <div v-else-if="loadError" class="text-danger py-3">{{ loadError }}</div>
        <div v-else-if="rows.length === 0" class="text-muted py-3">Nada pendente de recebimento neste pedido.</div>

        <form v-else @submit.prevent="submit">
            <div v-if="form.errors.items" class="alert alert-danger py-2">{{ form.errors.items }}</div>

            <div v-for="row in rows" :key="row.purchase_order_item_id" class="border rounded p-2 mb-2">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <strong>{{ row.product_name }}</strong>
                    <small class="text-muted">pendente: {{ row.remaining_quantity }} {{ row.unit_label }}</small>
                </div>

                <div class="row g-2 align-items-start">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Quantidade recebida agora</label>
                        <input
                            v-model="row.quantity"
                            type="number"
                            step="0.001"
                            min="0"
                            :max="row.remaining_quantity"
                            class="form-control form-control-sm"
                        >
                    </div>

                    <div v-if="row.requires_lot" class="col-md-8">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="form-label small mb-0">Lote <span class="text-danger">*</span></label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn" :class="lotMode[row.purchase_order_item_id] === 'existing' ? 'btn-primary' : 'btn-outline-secondary'" @click="lotMode[row.purchase_order_item_id] = 'existing'">Existente</button>
                                <button type="button" class="btn" :class="lotMode[row.purchase_order_item_id] === 'new' ? 'btn-primary' : 'btn-outline-secondary'" @click="lotMode[row.purchase_order_item_id] = 'new'">Novo lote</button>
                            </div>
                        </div>

                        <select v-if="lotMode[row.purchase_order_item_id] === 'existing'" v-model="row.stock_lot_id" class="form-select form-select-sm">
                            <option value="" disabled>Selecione o lote...</option>
                            <option v-for="lot in availableLots(row.entity_product_id)" :key="lot.id" :value="lot.id">
                                {{ lot.lot_number }} — saldo {{ lot.qty_on_hand }}<template v-if="lot.expiry_date"> — vence {{ lot.expiry_date }}</template>
                            </option>
                        </select>

                        <div v-else class="row g-2">
                            <div class="col-7">
                                <input v-model="row.new_lot_number" type="text" class="form-control form-control-sm" placeholder="Número do lote">
                            </div>
                            <div class="col-5">
                                <input v-model="row.new_lot_expiry_date" type="date" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="form.errors[`items.${rows.indexOf(row)}.stock_lot_id`]" class="text-danger small mt-1">
                    {{ form.errors[`items.${rows.indexOf(row)}.stock_lot_id`] }}
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="close">Cancelar</button>
            <button type="button" class="btn btn-primary px-4" :disabled="form.processing || rows.length === 0" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                Confirmar recebimento
            </button>
        </template>
    </OffcanvasPanel>
</template>
