<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';

/**
 * Cards de fornecedores no padrão de Patients/PatientCards, renderizando o
 * MESMO paginator da tabela (sem endpoint/consulta extra): busca, filtro de
 * status, ordenação e página continuam valendo nos dois modos.
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    t: { type: Object, default: () => ({}) },
    purchaseOrdersUrl: { type: String, default: '' },
});

const emit = defineEmits(['edit', 'delete']);

const rows = computed(() => props.items?.data ?? []);

function purchaseOrdersHref(supplier) {
    if (!props.purchaseOrdersUrl) return null;

    return `${props.purchaseOrdersUrl}?supplier_id=${encodeURIComponent(supplier.id)}`;
}
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-truck-off fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ t.empty_list ?? 'Nenhum fornecedor encontrado.' }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="s in rows" :key="s.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start gap-2 mb-2">
                    <h6 class="mb-0 fw-semibold lh-sm me-auto text-break">{{ s.name }}</h6>
                    <span
                        :class="
                            s.active
                                ? 'badge badge-soft-success rounded text-success border border-success fs-12'
                                : 'badge badge-soft-danger rounded text-danger border border-danger fs-12'
                        "
                        >{{ s.active ? (t.status_active ?? 'Ativo') : (t.status_inactive ?? 'Inativo') }}</span
                    >
                </div>

                <dl class="small text-muted mb-1">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_code ?? 'Código' }}:</dt>
                        <dd class="mb-0">{{ s.code }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_document ?? 'Documento' }}:</dt>
                        <dd class="mb-0">{{ s.document_display ?? '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_contact ?? 'Contato' }}:</dt>
                        <dd class="mb-0 text-break">{{ s.contact_name ?? '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_phone ?? 'Telefone' }}:</dt>
                        <dd class="mb-0">{{ s.phone_display ?? '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_email ?? 'E-mail' }}:</dt>
                        <dd class="mb-0 text-break">{{ s.email ?? '—' }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton
                        v-if="purchaseOrdersHref(s)"
                        icon="ti ti-shopping-cart"
                        :title="t.action_purchase_orders ?? 'Pedidos de compra deste fornecedor'"
                        variant="info"
                        :inertia-href="purchaseOrdersHref(s)"
                    />
                    <ActionDropdown
                        :title="t.more_actions ?? 'Mais ações'"
                        btn-class="ee-action-icon ee-action-icon--default"
                    >
                        <li>
                            <button type="button" class="dropdown-item rounded-1" @click="emit('edit', s)">
                                <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.action_edit ?? 'Editar' }}
                            </button>
                        </li>
                        <li><hr class="dropdown-divider" /></li>
                        <li>
                            <button
                                type="button"
                                class="dropdown-item rounded-1 text-danger"
                                @click="emit('delete', s)"
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
