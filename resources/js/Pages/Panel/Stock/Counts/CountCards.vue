<script setup>
import { computed, ref } from 'vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useCountFormat } from './useCountFormat.js';

/**
 * Cards da contagem de estoque no padrão de Patients/PatientCards: mesmo
 * paginator da tabela (sem endpoint/consulta extra), produto + diferença
 * (status), dados principais, o campo "Contado" e a mesma ação da tabela —
 * prático para contar pelo celular/tablet no estoque.
 *
 * O valor digitado mora no pai (Index) — aqui só exibe e emite `count`.
 */
const props = defineProps({
    products: { type: Object, required: true }, // paginator Laravel
    counted: { type: Object, default: () => ({}) },
    deltas: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['count']);

const { quantity, unitLabel, differenceBadge } = useCountFormat(() => props.t);

const rows = computed(() => props.products?.data ?? []);

// Atalho abre em nova aba (a contagem digitada mora nesta): o título avisa.
const movementsTitle = computed(
    () =>
        `${props.t.action_movements ?? 'Ver movimentações do produto'} (${props.t.opens_new_tab ?? 'abre em nova aba'})`,
);

// Mesmo atalho da tabela: Enter leva ao próximo campo "Contado".
const gridEl = ref(null);

function focusNextCount(event) {
    const inputs = [...(gridEl.value?.querySelectorAll('[data-count-input]') ?? [])];
    const next = inputs[inputs.indexOf(event.currentTarget) + 1];
    if (!next) return;

    next.focus();
    next.select();
}
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-clipboard-off fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ t.empty_list ?? 'Nenhum produto ativo encontrado para contar.' }}</p>
    </div>

    <div v-else ref="gridEl" class="row g-3">
        <div v-for="p in rows" :key="p.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="flex-grow-1 text-break">
                        <h6 class="mb-0 fw-semibold lh-sm">{{ p.name }}</h6>
                        <small class="text-muted">{{ p.code }}</small>
                    </div>
                    <span
                        v-if="differenceBadge(deltas[p.id])"
                        :class="differenceBadge(deltas[p.id]).class"
                        class="flex-shrink-0"
                        >{{ differenceBadge(deltas[p.id]).text }}</span
                    >
                </div>

                <div v-if="p.requires_lot" class="mt-1">
                    <span class="badge badge-soft-info rounded text-info border border-info fs-11">{{
                        t.requires_lot ?? 'Exige lote'
                    }}</span>
                </div>

                <dl class="small text-muted mt-2 mb-2">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_category ?? 'Categoria' }}:</dt>
                        <dd class="mb-0">{{ p.category_name ?? '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_qty_on_hand ?? 'Saldo do sistema' }}:</dt>
                        <dd class="mb-0">{{ quantity(p.qty_on_hand) }} {{ unitLabel(p) }}</dd>
                    </div>
                </dl>

                <label :for="`count-card-${p.id}`" class="form-label small fw-semibold mb-1">{{
                    t.col_counted ?? 'Contado'
                }}</label>
                <input
                    :id="`count-card-${p.id}`"
                    type="number"
                    step="0.001"
                    min="0"
                    inputmode="decimal"
                    class="form-control form-control-sm"
                    placeholder="—"
                    data-count-input
                    :value="counted[p.id] ?? ''"
                    @input="emit('count', p.id, $event.target.value)"
                    @keydown.enter.prevent="focusNextCount"
                />

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end">
                    <ActionIconButton
                        icon="ti ti-transfer-in"
                        variant="info"
                        :title="movementsTitle"
                        :href="p.movements_url"
                        target="_blank"
                    />
                </ActionIconGroup>
            </div>
        </div>
    </div>

    <TablePagination
        :data="products"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>
