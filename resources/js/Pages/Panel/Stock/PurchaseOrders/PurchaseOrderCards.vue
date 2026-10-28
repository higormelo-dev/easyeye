<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Cards de pedidos de compra no padrão de Patients/PatientCards,
 * renderizando o MESMO paginator da tabela (sem endpoint/consulta extra).
 * Ações com o mesmo gating por status de PurchaseOrderTable.
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    t: { type: Object, default: () => ({}) },
    pdfUrlTemplate: { type: String, default: '' }, // rota com __ID__
});

const emit = defineEmits(['edit', 'send', 'receive', 'cancel', 'delete']);

const { money, date } = useLocaleFormat();

const rows = computed(() => props.items?.data ?? []);

const STATUS_BADGE = {
    draft: 'badge-soft-secondary text-secondary border border-secondary',
    sent: 'badge-soft-info text-info border border-info',
    partially_received: 'badge-soft-warning text-warning border border-warning',
    received: 'badge-soft-success text-success border border-success',
    cancelled: 'badge-soft-danger text-danger border border-danger',
};

/** Rótulo traduzido pelo backend (PurchaseOrderStatus::label()). */
function statusLabel(po) {
    return po.status_label ?? po.status;
}

const canEdit = (po) => Boolean(po.is_editable);
const canSend = (po) => po.status === 'draft';
const canReceive = (po) => ['sent', 'partially_received'].includes(po.status);
const canCancel = (po) => !['received', 'cancelled'].includes(po.status);
const canDelete = (po) => po.status === 'draft';
const hasMenu = (po) => canEdit(po) || canCancel(po) || canDelete(po);

function pdfUrl(po) {
    return props.pdfUrlTemplate ? props.pdfUrlTemplate.replace('__ID__', po.id) : null;
}
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-shopping-cart-off fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ t.empty_list ?? 'Nenhum pedido de compra encontrado.' }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="po in rows" :key="po.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start gap-2 mb-2">
                    <div class="me-auto">
                        <h6 class="mb-1 fw-semibold lh-sm text-break">{{ po.supplier_name ?? '—' }}</h6>
                        <code class="text-muted small">{{ po.code }}</code>
                    </div>
                    <span class="badge rounded fs-12" :class="STATUS_BADGE[po.status] ?? 'badge-soft-secondary'">{{
                        statusLabel(po)
                    }}</span>
                </div>

                <dl class="small text-muted mb-1">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_order_date ?? 'Data' }}:</dt>
                        <dd class="mb-0">{{ date(po.order_date) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_expected_delivery ?? 'Previsão de entrega' }}:</dt>
                        <dd class="mb-0">{{ date(po.expected_delivery_date) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_total ?? 'Total' }}:</dt>
                        <dd class="mb-0 fw-semibold text-body">{{ money(po.total_amount) }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton
                        v-if="pdfUrl(po)"
                        icon="ti ti-file-download"
                        :title="t.action_pdf ?? 'Baixar PDF'"
                        :href="pdfUrl(po)"
                    />
                    <ActionIconButton
                        v-if="canSend(po)"
                        icon="ti ti-send"
                        :title="t.action_send ?? 'Enviar ao fornecedor'"
                        variant="info"
                        @click="emit('send', po)"
                    />
                    <ActionIconButton
                        v-if="canReceive(po)"
                        icon="ti ti-package-import"
                        :title="t.action_receive ?? 'Receber'"
                        variant="success"
                        @click="emit('receive', po)"
                    />
                    <ActionDropdown
                        v-if="hasMenu(po)"
                        :title="t.more_actions ?? 'Mais ações'"
                        btn-class="ee-action-icon ee-action-icon--default"
                    >
                        <li v-if="canEdit(po)">
                            <button type="button" class="dropdown-item rounded-1" @click="emit('edit', po)">
                                <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.action_edit ?? 'Editar' }}
                            </button>
                        </li>
                        <li v-if="canEdit(po) && (canCancel(po) || canDelete(po))"><hr class="dropdown-divider" /></li>
                        <li v-if="canCancel(po)">
                            <button
                                type="button"
                                class="dropdown-item rounded-1 text-danger"
                                @click="emit('cancel', po)"
                            >
                                <i class="ti ti-x me-1" aria-hidden="true"></i>
                                {{ t.action_cancel ?? 'Cancelar pedido' }}
                            </button>
                        </li>
                        <li v-if="canDelete(po)">
                            <button
                                type="button"
                                class="dropdown-item rounded-1 text-danger"
                                @click="emit('delete', po)"
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
