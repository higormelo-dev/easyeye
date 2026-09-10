<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

/**
 * Cria/edita RASCUNHO de pedido de compra. Só abre pra pedido novo ou
 * `is_editable` (draft) — pedido enviado/recebido não passa por aqui (ver
 * doc de PurchaseOrderService::assertEditable()); a listagem já esconde a
 * ação de editar nesses casos.
 */
const props = defineProps({
    open:      { type: Boolean, required: true },
    item:      { type: Object,  default: null }, // null = criar; objeto = editar (PurchaseOrderResource, com items)
    routes:    { type: Object,  required: true }, // { store, update (__ID__) }
    suppliers: { type: Array,   default: () => [] },
    products:  { type: Array,   default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const isEdit = computed(() => !!props.item);
const title  = computed(() => isEdit.value ? 'Editar pedido de compra' : 'Novo pedido de compra');

const form = useForm({
    supplier_id:             '',
    order_date:              new Date().toISOString().slice(0, 10),
    expected_delivery_date:  '',
    notes:                   '',
    items:                   [],
});

function emptyRow() {
    return { entity_product_id: '', quantity_ordered: null, unit_cost: null };
}

function addRow() {
    form.items.push(emptyRow());
}

function removeRow(index) {
    form.items.splice(index, 1);
}

// GAP fechado (revisão pós-Fase 4): `min_qty`/`max_qty` existiam no
// cadastro do produto desde a Fase 1 mas nunca alimentavam nada — nem
// alerta, nem sugestão de compra, só decorativos no form de produto. Aqui
// ganham utilidade real: ao escolher um produto na linha, pré-preenche
// custo (última média) e — se o produto está abaixo do mínimo e a
// quantidade ainda não foi digitada — a quantidade sugerida
// (max_qty - saldo, ou min_qty - saldo se não há máximo configurado).
// Nunca sobrescreve valor que o usuário já digitou.
function onProductPicked(row) {
    const product = props.products.find((p) => p.id === row.entity_product_id);
    if (!product) return;

    if (row.unit_cost === null || row.unit_cost === '') {
        row.unit_cost = product.cost_avg > 0 ? product.cost_avg : null;
    }
    if ((row.quantity_ordered === null || row.quantity_ordered === '') && product.suggested_qty) {
        row.quantity_ordered = product.suggested_qty;
    }
}

// Atalho: adiciona de uma vez todo produto abaixo do mínimo ainda não
// presente no pedido, já com quantidade/custo sugeridos — cobre o caso de
// uso mais comum de pedido de compra (repor o que está em falta) sem
// obrigar o usuário a procurar produto por produto.
const belowMinimumNotInOrder = computed(() => {
    const already = new Set(form.items.map((i) => i.entity_product_id).filter(Boolean));

    return props.products.filter((p) => p.below_minimum && !already.has(p.id));
});

function addBelowMinimumProducts() {
    const rows = belowMinimumNotInOrder.value.map((p) => ({
        entity_product_id: p.id,
        quantity_ordered: p.suggested_qty || null,
        unit_cost: p.cost_avg > 0 ? p.cost_avg : null,
    }));

    if (rows.length === 0) return;

    // Remove a única linha vazia inicial (se ainda estiver lá) pra não
    // deixar uma linha em branco solta no meio do pedido recém-populado.
    form.items = [
        ...form.items.filter((i) => i.entity_product_id),
        ...rows,
    ];
}

const total = computed(() => form.items.reduce((sum, row) => {
    const qty = Number(row.quantity_ordered) || 0;
    const cost = Number(row.unit_cost) || 0;

    return sum + qty * cost;
}, 0));

function reset() {
    form.reset();
    form.clearErrors();
    form.items = [emptyRow()];
}

watch(() => props.open, (val) => {
    if (!val) return;
    reset();

    if (props.item) {
        form.supplier_id            = props.item.supplier_id ?? '';
        form.order_date             = props.item.order_date ?? form.order_date;
        form.expected_delivery_date = props.item.expected_delivery_date ?? '';
        form.notes                  = props.item.notes ?? '';
        form.items = (props.item.items ?? []).map((i) => ({
            entity_product_id: i.entity_product_id,
            quantity_ordered:  i.quantity_ordered,
            unit_cost:         i.unit_cost,
        }));
        if (form.items.length === 0) form.items = [emptyRow()];
    }
});

function submit() {
    // Linhas vazias (produto não selecionado) são descartadas antes do
    // envio — a UI sempre mantém pelo menos 1 linha visível pra "adicionar
    // item" ficar óbvio, mas uma linha em branco não deve virar erro de
    // validação obrigando o usuário a removê-la manualmente.
    form.transform((data) => ({
        ...data,
        items: data.items.filter((i) => i.entity_product_id && i.quantity_ordered && i.unit_cost !== null && i.unit_cost !== ''),
    }));

    if (isEdit.value) {
        form.put(props.routes.update.replace('__ID__', props.item.id), {
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });
    } else {
        form.post(props.routes.store, {
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });
    }
}

function close() {
    if (form.processing) return;
    emit('close');
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="720" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-shopping-cart me-2 text-primary"></i>{{ title }}
            </h5>
        </template>

        <form @submit.prevent="submit">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Fornecedor <span class="text-danger">*</span></label>
                    <select v-model="form.supplier_id" class="form-select" :class="{ 'is-invalid': form.errors.supplier_id }">
                        <option value="" disabled>Selecione...</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <div v-if="form.errors.supplier_id" class="invalid-feedback">{{ form.errors.supplier_id }}</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data do pedido</label>
                    <input v-model="form.order_date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.order_date }">
                    <div v-if="form.errors.order_date" class="invalid-feedback">{{ form.errors.order_date }}</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Previsão de entrega</label>
                    <input v-model="form.expected_delivery_date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.expected_delivery_date }">
                    <div v-if="form.errors.expected_delivery_date" class="invalid-feedback">{{ form.errors.expected_delivery_date }}</div>
                </div>
            </div>

            <div class="mb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <label class="form-label mb-0">Itens</label>
                <div class="d-flex gap-2">
                    <button v-if="belowMinimumNotInOrder.length > 0" type="button" class="btn btn-sm btn-outline-warning" @click="addBelowMinimumProducts">
                        <i class="ti ti-alert-triangle"></i> Adicionar {{ belowMinimumNotInOrder.length }} produto(s) abaixo do mínimo
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addRow">
                        <i class="ti ti-plus"></i> Adicionar item
                    </button>
                </div>
            </div>

            <div v-if="form.errors.items" class="alert alert-danger py-2">{{ form.errors.items }}</div>

            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="min-width:220px;">Produto</th>
                            <th style="width:120px;">Quantidade</th>
                            <th style="width:140px;">Custo unit. (R$)</th>
                            <th class="text-end" style="width:110px;">Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in form.items" :key="index">
                            <td>
                                <select v-model="row.entity_product_id" class="form-select form-select-sm" @change="onProductPicked(row)">
                                    <option value="" disabled>Selecione...</option>
                                    <option v-for="p in products" :key="p.id" :value="p.id">
                                        {{ p.name }} ({{ p.code }})<template v-if="p.below_minimum"> ⚠ abaixo do mínimo</template>
                                    </option>
                                </select>
                            </td>
                            <td><input v-model="row.quantity_ordered" type="number" step="0.001" min="0" class="form-control form-control-sm"></td>
                            <td><input v-model="row.unit_cost" type="number" step="0.0001" min="0" class="form-control form-control-sm"></td>
                            <td class="text-end">R$ {{ ((Number(row.quantity_ordered) || 0) * (Number(row.unit_cost) || 0)).toFixed(2) }}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger" :disabled="form.items.length === 1" @click="removeRow(index)">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end fw-semibold">Total</td>
                            <td class="text-end fw-semibold">R$ {{ total.toFixed(2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mb-2">
                <label class="form-label">Observações</label>
                <textarea v-model="form.notes" class="form-control" :class="{ 'is-invalid': form.errors.notes }" rows="2" maxlength="2000"></textarea>
                <div v-if="form.errors.notes" class="invalid-feedback">{{ form.errors.notes }}</div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="close">Cancelar</button>
            <button type="button" class="btn btn-primary px-4" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? 'Salvar alterações' : 'Criar pedido' }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
