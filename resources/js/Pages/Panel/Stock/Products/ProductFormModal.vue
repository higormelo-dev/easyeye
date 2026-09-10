<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

/**
 * Form de criar/editar item de estoque (App\Models\EntityProduct).
 *
 * `item` chega pronto da listagem (EntityProductResource já serializado em
 * Index.vue) — sem roundtrip extra pra `routes.show`, mesmo padrão de
 * IolLensFormModal.vue. qty_on_hand/cost_avg NUNCA aparecem neste form —
 * são somente-leitura aqui (saldo só muda via lançamento de movimentação,
 * ver Movements/Index.vue) — mostrados como texto informativo, não como
 * input editável.
 *
 * Lotes (Fase 2): quando editando um produto `requires_lot=true`, mostra a
 * lista de lotes ABAIXO do form (metadado apenas — número/validade/ativo).
 * Chamado via window.axios (JSON puro, App\Http\Controllers\Stock\
 * ProductLotsController), NÃO via router Inertia — editar um lote não deve
 * recarregar a página inteira, só a lista de lotes local.
 */
const props = defineProps({
    open:       { type: Boolean, required: true },
    item:       { type: Object,  default: null }, // null = criar; objeto = editar
    routes:     { type: Object,  required: true }, // { store, update (__ID__), lots_index (__ID__), lots_update (__ID__) }
    categories: { type: Array,   default: () => [] },
    units:      { type: Array,   default: () => [] }, // [{ value, label }]
});

const emit = defineEmits(['close', 'saved']);

const isEdit = computed(() => !!props.item);
const title  = computed(() => isEdit.value ? 'Editar produto' : 'Novo produto');

const form = useForm({
    product_category_id: null,
    sku:                  '',
    barcode:              '',
    name:                 '',
    description:          '',
    unit:                 'un',
    is_opm:               false,
    requires_lot:         false,
    sale_price:           null,
    min_qty:              0,
    max_qty:              null,
    active:               true,
});

function reset() {
    form.reset();
    form.clearErrors();
}

watch(() => props.open, (val) => {
    if (!val) return;
    reset();
    resetLots();

    if (props.item) {
        form.product_category_id = props.item.product_category_id ?? null;
        form.sku                 = props.item.sku ?? '';
        form.barcode             = props.item.barcode ?? '';
        form.name                = props.item.name ?? '';
        form.description         = props.item.description ?? '';
        form.unit                = props.item.unit ?? 'un';
        form.is_opm              = !!props.item.is_opm;
        form.requires_lot        = !!props.item.requires_lot;
        form.sale_price          = props.item.sale_price ?? null;
        form.min_qty             = props.item.min_qty ?? 0;
        form.max_qty             = props.item.max_qty ?? null;
        form.active              = props.item.active ?? true;

        if (form.requires_lot) {
            loadLots();
        }
    }
});

function submit() {
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

// ── Lotes (Fase 2) ──────────────────────────────────────────────────────
const lots        = ref([]);
const lotsLoading  = ref(false);
const lotsError    = ref('');
const lotSaving    = reactive({}); // { [lotId]: boolean }
const lotErrors    = reactive({}); // { [lotId]: string }

function resetLots() {
    lots.value = [];
    lotsError.value = '';
}

async function loadLots() {
    if (!isEdit.value) return;

    lotsLoading.value = true;
    lotsError.value = '';

    try {
        const { data } = await window.axios.get(props.routes.lots_index.replace('__ID__', props.item.id));
        lots.value = data.data ?? [];
    } catch {
        lotsError.value = 'Não foi possível carregar os lotes.';
    } finally {
        lotsLoading.value = false;
    }
}

function lotStatus(lot) {
    if (lot.is_expired) return { label: 'Vencido', class: 'badge-soft-danger text-danger' };
    if (lot.days_to_expiry !== null && lot.days_to_expiry <= 30) return { label: `Vence em ${lot.days_to_expiry}d`, class: 'badge-soft-warning text-warning' };

    return { label: 'OK', class: 'badge-soft-success text-success' };
}

async function saveLot(lot) {
    lotSaving[lot.id] = true;
    lotErrors[lot.id] = '';

    try {
        const { data } = await window.axios.put(props.routes.lots_update.replace('__ID__', lot.id), {
            lot_number:  lot.lot_number,
            expiry_date: lot.expiry_date,
            active:      lot.active,
        });

        Object.assign(lot, data.data);
    } catch (e) {
        lotErrors[lot.id] = e.response?.data?.errors?.lot_number?.[0]
            ?? e.response?.data?.message
            ?? 'Erro ao salvar o lote.';
    } finally {
        lotSaving[lot.id] = false;
    }
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="620" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-package me-2 text-primary"></i>{{ title }}
            </h5>
        </template>

        <form @submit.prevent="submit">
            <!-- Saldo/custo — somente leitura, só existe em edição -->
            <div v-if="isEdit" class="alert alert-light border d-flex gap-4 mb-3 py-2">
                <div>
                    <small class="text-muted d-block">Saldo atual</small>
                    <strong>{{ item.qty_on_hand }} {{ item.unit_label }}</strong>
                </div>
                <div>
                    <small class="text-muted d-block">Custo médio</small>
                    <strong>R$ {{ Number(item.cost_avg).toFixed(2) }}</strong>
                </div>
                <div class="ms-auto align-self-center">
                    <small class="text-muted">Saldo só muda em <em>Movimentação</em></small>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label">Nome <span class="text-danger">*</span></label>
                    <input
                        v-model="form.name"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.name }"
                        maxlength="255"
                    >
                    <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Código/SKU externo</label>
                    <input
                        v-model="form.sku"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.sku }"
                        maxlength="100"
                        placeholder="Cód. do fabricante"
                    >
                    <div v-if="form.errors.sku" class="invalid-feedback">{{ form.errors.sku }}</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <!-- GAP fechado (revisão pós-Fase 4): código de barras
                         próprio — leitor USB/Bluetooth digita aqui igual um
                         teclado, sem precisar de driver especial. -->
                    <label class="form-label">Código de barras</label>
                    <input
                        v-model="form.barcode"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.barcode }"
                        maxlength="64"
                        placeholder="Escaneie ou digite o EAN/UPC"
                    >
                    <div v-if="form.errors.barcode" class="invalid-feedback">{{ form.errors.barcode }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Categoria</label>
                    <select
                        v-model="form.product_category_id"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.product_category_id }"
                    >
                        <option :value="null">Sem categoria</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <div v-if="form.errors.product_category_id" class="invalid-feedback">{{ form.errors.product_category_id }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Unidade <span class="text-danger">*</span></label>
                    <select
                        v-model="form.unit"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.unit }"
                    >
                        <option v-for="u in units" :key="u.value" :value="u.value">{{ u.label }}</option>
                    </select>
                    <div v-if="form.errors.unit" class="invalid-feedback">{{ form.errors.unit }}</div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Descrição</label>
                <textarea
                    v-model="form.description"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.description }"
                    rows="2"
                    maxlength="2000"
                ></textarea>
                <div v-if="form.errors.description" class="invalid-feedback">{{ form.errors.description }}</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Preço de venda (R$)</label>
                    <input
                        v-model="form.sale_price"
                        type="number"
                        step="0.01"
                        min="0"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.sale_price }"
                        placeholder="0,00"
                    >
                    <div v-if="form.errors.sale_price" class="invalid-feedback">{{ form.errors.sale_price }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Estoque mínimo</label>
                    <input
                        v-model="form.min_qty"
                        type="number"
                        step="0.001"
                        min="0"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.min_qty }"
                    >
                    <div v-if="form.errors.min_qty" class="invalid-feedback">{{ form.errors.min_qty }}</div>
                    <small class="text-muted">Alerta de reposição.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Estoque máximo</label>
                    <input
                        v-model="form.max_qty"
                        type="number"
                        step="0.001"
                        min="0"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.max_qty }"
                    >
                    <div v-if="form.errors.max_qty" class="invalid-feedback">{{ form.errors.max_qty }}</div>
                </div>
            </div>

            <div class="row g-3 mb-2">
                <div class="col-md-6">
                    <div class="form-check">
                        <input
                            id="product_is_opm"
                            v-model="form.is_opm"
                            type="checkbox"
                            class="form-check-input"
                        >
                        <label class="form-check-label" for="product_is_opm">
                            É OPM (órtese/prótese/material especial)
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input
                            id="product_requires_lot"
                            v-model="form.requires_lot"
                            type="checkbox"
                            class="form-check-input"
                        >
                        <label class="form-check-label" for="product_requires_lot">
                            Exige lote/validade
                        </label>
                    </div>
                    <small class="text-muted d-block">
                        Toda movimentação deste produto vai exigir um lote. Lotes nascem ao
                        registrar uma entrada — não são cadastrados aqui.
                    </small>
                </div>
            </div>

            <div class="mb-2">
                <div class="form-check form-switch">
                    <input
                        id="product_active"
                        v-model="form.active"
                        type="checkbox"
                        class="form-check-input"
                        role="switch"
                    >
                    <label class="form-check-label" for="product_active">
                        {{ form.active ? 'Ativo' : 'Inativo' }}
                    </label>
                </div>
            </div>
        </form>

        <!-- Lotes (Fase 2) — só em edição de produto requires_lot=true -->
        <div v-if="isEdit && item.requires_lot" class="mt-4 pt-3 border-top">
            <h6 class="fw-semibold mb-2"><i class="ti ti-barcode me-1"></i>Lotes</h6>

            <div v-if="lotsLoading" class="text-muted small py-2">Carregando lotes...</div>
            <div v-else-if="lotsError" class="text-danger small py-2">{{ lotsError }}</div>
            <div v-else-if="lots.length === 0" class="text-muted small py-2">
                Nenhum lote ainda — o primeiro nasce na próxima entrada deste produto (tela Movimentação).
            </div>

            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Validade</th>
                            <th class="text-end">Saldo</th>
                            <th>Status</th>
                            <th>Ativo</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="lot in lots" :key="lot.id">
                            <td style="min-width:140px;">
                                <input v-model="lot.lot_number" type="text" class="form-control form-control-sm" maxlength="100">
                            </td>
                            <td style="min-width:150px;">
                                <input v-model="lot.expiry_date" type="date" class="form-control form-control-sm">
                            </td>
                            <td class="text-end">{{ lot.qty_on_hand }}</td>
                            <td>
                                <span class="badge rounded fs-11 fw-medium" :class="lotStatus(lot).class">{{ lotStatus(lot).label }}</span>
                            </td>
                            <td>
                                <div class="form-check form-switch mb-0">
                                    <input v-model="lot.active" type="checkbox" class="form-check-input" role="switch">
                                </div>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    :disabled="lotSaving[lot.id]"
                                    @click="saveLot(lot)"
                                >
                                    <span v-if="lotSaving[lot.id]" class="spinner-border spinner-border-sm"></span>
                                    <i v-else class="ti ti-device-floppy"></i>
                                </button>
                                <div v-if="lotErrors[lot.id]" class="text-danger small mt-1" style="max-width:160px;">{{ lotErrors[lot.id] }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="close">
                Cancelar
            </button>
            <button type="button" class="btn btn-primary px-4" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? 'Salvar alterações' : 'Cadastrar produto' }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
