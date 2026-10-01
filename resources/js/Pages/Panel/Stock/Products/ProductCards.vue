<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Cards de produtos de estoque no padrão de Patients/PatientCards: nome,
 * status, indicadores (OPM/lote vencendo) e os valores numéricos em linhas
 * rotuladas (saldo, custo médio, preço) — legíveis mesmo no grid. Usa o
 * MESMO paginator da tabela (prop `items`), sem endpoint extra, e as mesmas
 * ações da ProductTable.
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    t: { type: Object, default: () => ({}) },
    movementsIndexUrl: { type: String, default: '' },
});

const emit = defineEmits(['edit', 'toggleActive', 'delete']);

const { tx } = useTrans(() => props.t);
const { money, quantity, date } = useLocaleFormat();

const rows = computed(() => props.items?.data ?? []);

function movementsUrl(product) {
    if (!props.movementsIndexUrl) return null;

    return `${props.movementsIndexUrl}?${new URLSearchParams({ entity_product_id: product.id })}`;
}
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-package-off fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ t.empty_list ?? 'Nenhum produto encontrado.' }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="p in rows" :key="p.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <h6 class="mb-1 fw-semibold lh-sm text-break">{{ p.name }}</h6>
                    <span
                        :class="
                            p.active
                                ? 'badge badge-soft-success rounded text-success border border-success fs-12'
                                : 'badge badge-soft-danger rounded text-danger border border-danger fs-12'
                        "
                        >{{ p.active ? (t.status_active ?? 'Ativo') : (t.status_inactive ?? 'Inativo') }}</span
                    >
                </div>

                <div v-if="p.is_opm || p.has_expiring_lot" class="d-flex flex-wrap gap-1 mt-1">
                    <span v-if="p.is_opm" class="badge badge-soft-info rounded fs-11" :title="t.badge_opm_title">{{
                        t.badge_opm ?? 'OPM'
                    }}</span>
                    <span
                        v-if="p.has_expiring_lot"
                        class="badge badge-soft-warning text-warning rounded fs-11"
                        :title="tx('expiring_lot_title', { date: date(p.nearest_expiry) })"
                    >
                        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                        {{ t.badge_expiring_lot ?? 'Lote vencendo' }}
                    </span>
                </div>

                <!-- Linhas rotuladas no estilo de PatientCards ("Rótulo: valor"). -->
                <dl class="small text-muted mt-2 mb-1">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_code ?? 'Código' }}:</dt>
                        <dd class="mb-0">
                            <code class="text-muted">{{ p.code ?? '—' }}</code>
                        </dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_category ?? 'Categoria' }}:</dt>
                        <dd class="mb-0 text-break">{{ p.category_name ?? '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_unit ?? 'Unidade' }}:</dt>
                        <dd class="mb-0">{{ p.unit_label ?? '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_qty_on_hand ?? 'Saldo' }}:</dt>
                        <dd class="mb-0" :class="{ 'text-danger fw-semibold': p.below_minimum }">
                            {{ quantity(p.qty_on_hand) }}
                            <i
                                v-if="p.below_minimum"
                                class="ti ti-alert-triangle ms-1"
                                role="img"
                                :title="t.below_minimum ?? 'Abaixo do mínimo'"
                                :aria-label="t.below_minimum ?? 'Abaixo do mínimo'"
                            ></i>
                        </dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_cost_avg ?? 'Custo médio' }}:</dt>
                        <dd class="mb-0">{{ money(p.cost_avg) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_sale_price ?? 'Preço' }}:</dt>
                        <dd class="mb-0">{{ money(p.sale_price) }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton
                        v-if="movementsUrl(p)"
                        icon="ti ti-transfer-in"
                        :title="t.action_movements ?? 'Movimentações do produto'"
                        variant="info"
                        :inertia-href="movementsUrl(p)"
                    />
                    <ActionDropdown
                        :title="t.more_actions ?? 'Mais ações'"
                        btn-class="ee-action-icon ee-action-icon--default"
                        icon="ti ti-dots-vertical"
                    >
                        <li>
                            <button type="button" class="dropdown-item rounded-1" @click="emit('edit', p)">
                                <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.action_edit ?? 'Editar' }}
                            </button>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item rounded-1" @click="emit('toggleActive', p)">
                                <i :class="`ti me-1 ${p.active ? 'ti-lock-open' : 'ti-lock'}`" aria-hidden="true"></i>
                                {{ p.active ? (t.action_deactivate ?? 'Desativar') : (t.action_activate ?? 'Ativar') }}
                            </button>
                        </li>
                        <li><hr class="dropdown-divider" /></li>
                        <li>
                            <button
                                type="button"
                                class="dropdown-item rounded-1 text-danger"
                                @click="emit('delete', p)"
                            >
                                <i class="ti ti-trash me-1" aria-hidden="true"></i> {{ t.action_delete ?? 'Excluir' }}
                            </button>
                        </li>
                    </ActionDropdown>
                </ActionIconGroup>
            </div>
        </div>
    </div>

    <TablePagination
        :data="items"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>
