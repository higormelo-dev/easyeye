<script setup>
import { computed } from 'vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useMovementFormat } from './useMovementFormat.js';

/**
 * Cards do extrato de estoque no padrão de Patients/PatientCards: mesmo
 * paginator da tabela (sem endpoint/consulta extra), produto + tipo (badge),
 * dados principais e a mesma ação da tabela.
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['filterProduct']);

const { money, quantity, signedQuantity, dateTime, typeLabel, typeBadgeClass, directionIcon } = useMovementFormat();

const rows = computed(() => props.items?.data ?? []);

function isCurrentProduct(movement) {
    return String(props.filters.entity_product_id ?? '') === String(movement.entity_product_id);
}
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-transfer-in fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ t.empty_list ?? 'Nenhuma movimentação encontrada.' }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="m in rows" :key="m.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="flex-grow-1 text-break">
                        <h6 class="mb-0 fw-semibold lh-sm">{{ m.product_name ?? '—' }}</h6>
                        <small v-if="m.product_code" class="text-muted">{{ m.product_code }}</small>
                    </div>
                    <span :class="typeBadgeClass(m.type)" class="flex-shrink-0">
                        <i :class="directionIcon(m)" class="me-1" aria-hidden="true"></i>{{ typeLabel(m) }}
                    </span>
                </div>

                <dl class="small text-muted mt-2 mb-1">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_occurred_at ?? 'Data' }}:</dt>
                        <dd class="mb-0">{{ dateTime(m.occurred_at_iso) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_quantity ?? 'Quantidade' }}:</dt>
                        <dd class="mb-0 fw-medium" :class="m.direction === 1 ? 'text-success' : 'text-danger'">
                            {{ signedQuantity(m) }}
                        </dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_unit_cost ?? 'Custo unit.' }}:</dt>
                        <dd class="mb-0">{{ money(m.unit_cost) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_balance_after ?? 'Saldo após' }}:</dt>
                        <dd class="mb-0">{{ quantity(m.balance_after) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_lot ?? 'Lote' }}:</dt>
                        <dd class="mb-0">{{ m.lot_number ?? '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_created_by ?? 'Por' }}:</dt>
                        <dd class="mb-0">{{ m.created_by_name ?? '—' }}</dd>
                    </div>
                    <div v-if="m.note" class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_note ?? 'Observação' }}:</dt>
                        <dd class="mb-0 text-break">{{ m.note }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end">
                    <ActionIconButton
                        icon="ti ti-list-search"
                        variant="info"
                        :title="t.action_filter_product ?? 'Ver extrato deste produto'"
                        :disabled="isCurrentProduct(m)"
                        @click="emit('filterProduct', m.entity_product_id)"
                    />
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
