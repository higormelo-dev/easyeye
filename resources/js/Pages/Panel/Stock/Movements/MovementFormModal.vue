<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

/**
 * Lançamento MANUAL de movimentação (App\Services\Stock\StockService por
 * trás do StockMovementsController::store()). Só cobre os tipos de
 * StockMovementType::manualTypes() — purchase_in/consumption_out não têm
 * form aqui de propósito (nascem de compra/procedimento, Fases 3/4).
 *
 * Sem edição: um movimento é imutável (ver doc do model StockMovement) —
 * este modal só CRIA, nunca abre pré-preenchido pra editar um existente.
 *
 * Lote (Fase 2): quando o produto selecionado tem `requires_lot=true`, o
 * form exige um lote — ENTRADA pode escolher um existente OU criar um novo
 * (lot_number + validade opcional); SAÍDA só pode escolher um EXISTENTE com
 * saldo (não dá pra "inventar" um lote tirando estoque dele — mesma regra
 * do backend, ver StockMovementRequest::withValidator()).
 */
const props = defineProps({
    open:          { type: Boolean, required: true },
    routes:        { type: Object,  required: true }, // { store, scan_barcode }
    products:      { type: Array,   default: () => [] }, // [{ id, name, code, unit, qty_on_hand, requires_lot }]
    movementTypes: { type: Array,   default: () => [] }, // [{ value, label, direction }]
    lotsByProduct: { type: Object,  default: () => ({}) }, // { [productId]: [{ id, lot_number, expiry_date, qty_on_hand, is_expired }] }
});

const emit = defineEmits(['close', 'saved']);

const form = useForm({
    entity_product_id:   '',
    type:                 '',
    quantity:             null,
    unit_cost:            null,
    stock_lot_id:         '',
    new_lot_number:       '',
    new_lot_expiry_date:  '',
    note:                 '',
    occurred_at:          '',
});

// GAP fechado (revisão pós-Fase 4 — "melhorar o módulo de estoque"):
// leitor de código de barras USB/Bluetooth digita os dígitos + Enter como
// se fosse teclado — não precisa driver especial, só este campo de texto
// escutando @keyup.enter. Busca por MATCH EXATO no backend (nunca lista —
// código de barras resolve pra 1 produto só); se o produto encontrado não
// está em `products` (ex.: foi desativado entre a leitura e agora), avisa
// em vez de selecionar um id que o <select> não reconhece.
const barcodeInput   = ref('');
const barcodeError   = ref('');
const barcodeLoading = ref(false);

async function onBarcodeScanned() {
    const code = barcodeInput.value.trim();
    if (!code) return;

    barcodeError.value = '';
    barcodeLoading.value = true;
    try {
        const res = await fetch(`${props.routes.scan_barcode}?barcode=${encodeURIComponent(code)}`, {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
            barcodeError.value = json.message ?? 'Produto não encontrado.';
            return;
        }

        const found = props.products.find((p) => p.id === json.data.id);
        if (!found) {
            barcodeError.value = 'Produto encontrado, mas não está disponível pra lançamento agora (inativo?).';
            return;
        }

        form.entity_product_id = found.id;
        barcodeInput.value = '';
    } catch (e) {
        console.error('Barcode scan error:', e);
        barcodeError.value = 'Não foi possível buscar o produto.';
    } finally {
        barcodeLoading.value = false;
    }
}

// Custo unitário só faz sentido em ENTRADA (recalcula custo médio
// ponderado — ver StockService::registerMovement()); em saída é sempre
// ignorado pelo backend, então nem mostramos o campo pra não confundir.
const selectedTypeMeta = computed(() => props.movementTypes.find((t) => t.value === form.type) ?? null);
const isInbound        = computed(() => selectedTypeMeta.value?.direction === 1);

const selectedProduct = computed(() => props.products.find((p) => p.id === form.entity_product_id) ?? null);
const requiresLot      = computed(() => !!selectedProduct.value?.requires_lot);

const availableLots = computed(() => props.lotsByProduct[form.entity_product_id] ?? []);

// Saída só pode usar lote EXISTENTE — "criar novo lote" só aparece pra quem
// tá dando entrada. Alterna automaticamente o modo quando o usuário troca
// entrada<->saída com "novo lote" ainda selecionado (evita ficar preso num
// estado que o backend rejeitaria).
const lotMode = computed({
    get: () => (form.new_lot_number !== '' ? 'new' : 'existing'),
    set: (mode) => {
        if (mode === 'new') {
            form.stock_lot_id = '';
        } else {
            form.new_lot_number = '';
            form.new_lot_expiry_date = '';
        }
    },
});

watch(isInbound, (inbound) => {
    if (!inbound && lotMode.value === 'new') {
        lotMode.value = 'existing';
    }
});

// Trocar de produto invalida qualquer lote selecionado do produto anterior.
watch(() => form.entity_product_id, () => {
    form.stock_lot_id = '';
    form.new_lot_number = '';
    form.new_lot_expiry_date = '';
});

function reset() {
    form.reset();
    form.clearErrors();
    barcodeInput.value = '';
    barcodeError.value = '';
}

watch(() => props.open, (val) => {
    if (val) reset();
});

function submit() {
    form.post(props.routes.store, {
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
    <OffcanvasPanel :open="open" :width="520" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-transfer-in me-2 text-primary"></i>Nova movimentação
            </h5>
        </template>

        <form @submit.prevent="submit">
            <!-- GAP fechado (revisão pós-Fase 4): leitor de código de
                 barras — digita e aperta Enter, seleciona o produto
                 sozinho. Opcional: quem não tem leitor usa o <select>
                 abaixo normalmente. -->
            <div class="mb-3">
                <label class="form-label">Código de barras</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="ti ti-barcode"></i></span>
                    <input
                        v-model="barcodeInput"
                        type="text"
                        class="form-control"
                        placeholder="Escaneie ou digite e aperte Enter"
                        @keyup.enter="onBarcodeScanned"
                    >
                    <span v-if="barcodeLoading" class="input-group-text bg-transparent">
                        <span class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem;"></span>
                    </span>
                </div>
                <small v-if="barcodeError" class="text-danger d-block mt-1">{{ barcodeError }}</small>
            </div>

            <div class="mb-3">
                <label class="form-label">Produto <span class="text-danger">*</span></label>
                <select
                    v-model="form.entity_product_id"
                    class="form-select"
                    :class="{ 'is-invalid': form.errors.entity_product_id }"
                >
                    <option value="" disabled>Selecione...</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">
                        {{ p.name }} ({{ p.code }}) — saldo atual: {{ p.qty_on_hand }}
                    </option>
                </select>
                <div v-if="form.errors.entity_product_id" class="invalid-feedback">{{ form.errors.entity_product_id }}</div>
                <small v-if="selectedProduct" class="text-muted d-block mt-1">
                    Saldo atual: <strong>{{ selectedProduct.qty_on_hand }} {{ selectedProduct.unit }}</strong>
                    <span v-if="requiresLot" class="badge badge-soft-info ms-1">Exige lote</span>
                </small>
            </div>

            <div class="mb-3">
                <label class="form-label">Tipo <span class="text-danger">*</span></label>
                <select
                    v-model="form.type"
                    class="form-select"
                    :class="{ 'is-invalid': form.errors.type }"
                >
                    <option value="" disabled>Selecione...</option>
                    <option v-for="t in movementTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <div v-if="form.errors.type" class="invalid-feedback">{{ form.errors.type }}</div>
            </div>

            <!-- Lote (Fase 2) — só aparece pra produto requires_lot=true -->
            <div v-if="requiresLot" class="mb-3 p-2 border rounded bg-light-subtle">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label mb-0">Lote <span class="text-danger">*</span></label>
                    <div v-if="isInbound" class="btn-group btn-group-sm" role="group">
                        <button
                            type="button"
                            class="btn"
                            :class="lotMode === 'existing' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="lotMode = 'existing'"
                        >Existente</button>
                        <button
                            type="button"
                            class="btn"
                            :class="lotMode === 'new' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="lotMode = 'new'"
                        >Novo lote</button>
                    </div>
                </div>

                <template v-if="lotMode === 'existing'">
                    <select
                        v-model="form.stock_lot_id"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.stock_lot_id }"
                    >
                        <option value="" disabled>Selecione o lote...</option>
                        <option v-for="lot in availableLots" :key="lot.id" :value="lot.id">
                            {{ lot.lot_number }} — saldo {{ lot.qty_on_hand }}
                            <template v-if="lot.expiry_date"> — vence {{ lot.expiry_date }}</template>
                            <template v-if="lot.is_expired"> (VENCIDO)</template>
                        </option>
                    </select>
                    <div v-if="form.errors.stock_lot_id" class="invalid-feedback d-block">{{ form.errors.stock_lot_id }}</div>
                    <small v-if="selectedProduct && availableLots.length === 0" class="text-danger d-block mt-1">
                        Nenhum lote com saldo pra este produto.
                    </small>
                </template>

                <template v-else>
                    <div class="row g-2">
                        <div class="col-7">
                            <input
                                v-model="form.new_lot_number"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.new_lot_number }"
                                placeholder="Número do lote"
                                maxlength="100"
                            >
                            <div v-if="form.errors.new_lot_number" class="invalid-feedback">{{ form.errors.new_lot_number }}</div>
                        </div>
                        <div class="col-5">
                            <input
                                v-model="form.new_lot_expiry_date"
                                type="date"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.new_lot_expiry_date }"
                                placeholder="Validade"
                            >
                            <div v-if="form.errors.new_lot_expiry_date" class="invalid-feedback">{{ form.errors.new_lot_expiry_date }}</div>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-1">Cadastra o lote automaticamente ao registrar esta entrada.</small>
                </template>
            </div>

            <div class="row g-3 mb-3">
                <div :class="isInbound ? 'col-md-6' : 'col-md-12'">
                    <label class="form-label">Quantidade <span class="text-danger">*</span></label>
                    <input
                        v-model="form.quantity"
                        type="number"
                        step="0.001"
                        min="0"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.quantity }"
                    >
                    <div v-if="form.errors.quantity" class="invalid-feedback">{{ form.errors.quantity }}</div>
                </div>
                <div v-if="isInbound" class="col-md-6">
                    <label class="form-label">Custo unitário (R$)</label>
                    <input
                        v-model="form.unit_cost"
                        type="number"
                        step="0.0001"
                        min="0"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.unit_cost }"
                        placeholder="Recalcula o custo médio"
                    >
                    <div v-if="form.errors.unit_cost" class="invalid-feedback">{{ form.errors.unit_cost }}</div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Data do fato</label>
                <input
                    v-model="form.occurred_at"
                    type="datetime-local"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.occurred_at }"
                >
                <div v-if="form.errors.occurred_at" class="invalid-feedback">{{ form.errors.occurred_at }}</div>
                <small class="text-muted">Vazio = agora. Só pra registrar um fato retroativo.</small>
            </div>

            <div class="mb-2">
                <label class="form-label">Observação</label>
                <textarea
                    v-model="form.note"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.note }"
                    rows="2"
                    maxlength="1000"
                ></textarea>
                <div v-if="form.errors.note" class="invalid-feedback">{{ form.errors.note }}</div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="close">
                Cancelar
            </button>
            <button type="button" class="btn btn-primary px-4" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                Registrar
            </button>
        </template>
    </OffcanvasPanel>
</template>
