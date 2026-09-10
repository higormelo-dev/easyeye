<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout   from '@/Layouts/AppLayout.vue';
import PageHeader  from '@/Components/Panel/PageHeader.vue';

/**
 * Contagem física de estoque em MASSA (GAP fechado — revisão pós-Fase 4,
 * "melhorar o módulo de estoque"). App\Services\Stock\StockService::
 * adjustToCountedQuantity() já existia desde a Fase 1 (usado produto a
 * produto na tela de Movimentação), mas nunca teve uma tela pra contar
 * MUITOS produtos de uma vez — aqui lista tudo, usuário digita o contado
 * ao lado do saldo do sistema, aplica tudo junto.
 *
 * v1 é POR PRODUTO (agregado), não por lote — ver docblock de
 * StockCountRequest.
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    products:    { type: Array,  required: true },
    categories:  { type: Array,  default: () => [] },
    filters:     { type: Object, default: () => ({}) },
    routes:      { type: Object, required: true },
});

const categoryId = ref(props.filters?.category_id ?? '');

function applyFilters() {
    router.get(props.routes.index, { category_id: categoryId.value }, { preserveState: true, preserveScroll: true, replace: true });
}

// `counted` fica em branco até o usuário digitar — item sem contagem
// digitada NUNCA vai no submit (não é "contei zero", é "ainda não contei
// este aqui"), evita zerar por engano tudo que não deu tempo de contar.
const counted = ref(Object.fromEntries(props.products.map((p) => [p.id, ''])));

const touchedCount = computed(() => Object.values(counted.value).filter((v) => v !== '').length);

function delta(product) {
    const v = counted.value[product.id];
    if (v === '') return null;

    return Math.round((Number(v) - product.qty_on_hand) * 1000) / 1000;
}

const submitting = ref(false);
const result      = ref(null); // { message, variances } | null
const errorMsg    = ref('');

async function submit() {
    const items = props.products
        .filter((p) => counted.value[p.id] !== '')
        .map((p) => ({ entity_product_id: p.id, counted_qty: Number(counted.value[p.id]) }));

    if (items.length === 0) return;

    submitting.value = true;
    errorMsg.value = '';
    result.value = null;
    try {
        const { data } = await window.axios.post(props.routes.store, { items });
        result.value = data;
        // Reseta só os campos ENVIADOS — recém-carregados/saldo agora
        // reflete o valor contado (evita reabrir a tela pra ver o saldo
        // novo bater com o que acabou de digitar).
        items.forEach((i) => {
            const p = props.products.find((pp) => pp.id === i.entity_product_id);
            if (p) p.qty_on_hand = i.counted_qty;
            counted.value[i.entity_product_id] = '';
        });
    } catch (e) {
        errorMsg.value = e.response?.data?.message ?? 'Não foi possível aplicar a contagem.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppLayout title="Contagem de estoque" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Contagem de estoque" />

            <p class="text-muted small">
                Digite a quantidade CONTADA fisicamente ao lado de cada produto. Item deixado em branco não é alterado —
                só quem tem um valor digitado entra no ajuste. Contagem igual ao saldo do sistema não gera movimentação.
            </p>

            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <select v-model="categoryId" class="form-select form-select-sm" style="max-width:220px;" @change="applyFilters">
                    <option value="">Todas as categorias</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <span class="text-muted small">{{ touchedCount }} de {{ products.length }} produto(s) com contagem digitada</span>
            </div>

            <div v-if="result" class="alert alert-success py-2">
                {{ result.message }}
                <ul v-if="result.variances.length > 0" class="mb-0 mt-2 small">
                    <li v-for="v in result.variances" :key="v.entity_product_id">
                        {{ v.product_name }}: {{ v.before }} → {{ v.counted }}
                        (<span :class="v.delta > 0 ? 'text-success' : 'text-danger'">{{ v.delta > 0 ? '+' : '' }}{{ v.delta }}</span>)
                    </li>
                </ul>
            </div>
            <div v-if="errorMsg" class="alert alert-danger py-2">{{ errorMsg }}</div>

            <div v-if="products.length === 0" class="text-center text-muted py-5">
                Nenhum produto ativo pra contar.
            </div>

            <div v-else class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th>Categoria</th>
                            <th class="text-end">Saldo do sistema</th>
                            <th style="width:160px;">Contado</th>
                            <th class="text-end">Diferença</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in products" :key="p.id">
                            <td>
                                {{ p.name }} <span class="text-muted small">({{ p.code }})</span>
                                <span v-if="p.requires_lot" class="badge badge-soft-info ms-1">Exige lote</span>
                            </td>
                            <td class="small text-muted">{{ p.category_name ?? '—' }}</td>
                            <td class="text-end">{{ p.qty_on_hand }} {{ p.unit_label }}</td>
                            <td>
                                <input v-model="counted[p.id]" type="number" step="0.001" min="0" class="form-control form-control-sm" placeholder="—">
                            </td>
                            <td class="text-end small" :class="{ 'text-success': delta(p) > 0, 'text-danger': delta(p) < 0 }">
                                <template v-if="delta(p) !== null">{{ delta(p) > 0 ? '+' : '' }}{{ delta(p) }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="products.length > 0" class="d-flex justify-content-end mt-3">
                <button type="button" class="btn btn-primary" :disabled="submitting || touchedCount === 0" @click="submit">
                    <span v-if="submitting" class="spinner-border spinner-border-sm me-1"></span>
                    Aplicar contagem ({{ touchedCount }})
                </button>
            </div>
        </div>
    </AppLayout>
</template>
