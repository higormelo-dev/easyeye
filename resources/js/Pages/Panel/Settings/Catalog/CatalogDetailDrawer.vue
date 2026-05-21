<script setup>
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

/**
 * Drawer schema-driven de detalhes do catálogo. Recebe o item já carregado
 * (vindo da listagem) e renderiza as colunas declaradas + metadata.
 */
const props = defineProps({
    open:    { type: Boolean, required: true },
    item:    { type: Object,  default: null },
    columns: { type: Array,   required: true },
    t:       { type: Object,  default: () => ({}) },
});

defineEmits(['close', 'edit']);

function display(item, col) {
    const v = item?.[col.key];
    if (v === null || v === undefined || v === '') return '—';
    if (col.type === 'yesno') return v ? (props.t.yes ?? 'Sim') : (props.t.no ?? 'Não');
    if (col.type === 'numeric') return Number(v).toLocaleString('pt-BR');
    return v;
}
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="440"
        @close="$emit('close')"
    >
        <template #header>
            <div>
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-database me-2 text-info"></i>
                    {{ item?.name ?? '—' }}
                </h5>
                <code v-if="item?.code" class="text-muted small">{{ item.code }}</code>
            </div>
            <button
                v-if="item && !item.deleted"
                class="btn btn-sm btn-outline-primary ms-2"
                @click="$emit('edit', item)"
            >
                <i class="ti ti-edit me-1"></i> {{ t.btn_edit ?? 'Editar' }}
            </button>
        </template>

        <template v-if="item">
            <!-- Status -->
            <div class="mb-4">
                <span v-if="item.deleted" class="badge bg-secondary">
                    {{ t.status_deleted ?? 'Removido' }}
                </span>
                <span v-else-if="item.active" class="badge bg-success">
                    {{ t.status_active ?? 'Ativo' }}
                </span>
                <span v-else class="badge bg-danger">
                    {{ t.status_inactive ?? 'Inativo' }}
                </span>
                <span v-if="item.is_global" class="badge bg-info ms-1">
                    <i class="ti ti-star-filled me-1"></i>{{ t.status_global ?? 'Padrão do sistema' }}
                </span>
            </div>

            <!-- Detail rows schema-driven -->
            <div class="detail-section">
                <div class="detail-table">
                    <div v-for="col in columns" :key="col.key" class="detail-row">
                        <span class="detail-label">{{ col.label }}</span>
                        <span class="detail-value">
                            <template v-if="col.type === 'code'">
                                <code>{{ display(item, col) }}</code>
                            </template>
                            <template v-else-if="col.type === 'color'">
                                <span class="d-inline-flex align-items-center gap-1">
                                    <span
                                        class="rounded-circle border"
                                        :style="`background:${item[col.key] ?? '#ccc'}; width:18px; height:18px; display:inline-block;`"
                                    ></span>
                                    <code>{{ display(item, col) }}</code>
                                </span>
                            </template>
                            <template v-else>
                                {{ display(item, col) }}
                            </template>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.detail_registered_at ?? 'Cadastrado em' }}</span>
                        <span class="detail-value">{{ item.created_at || '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.detail_origin ?? 'Origem' }}</span>
                        <span class="detail-value">
                            {{ item.is_global ? (t.detail_origin_global ?? 'Padrão do sistema') : (t.detail_origin_clinic ?? 'Cadastrado pela clínica') }}
                        </span>
                    </div>
                </div>
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.detail-section { margin-bottom: 1.5rem; }
.detail-table { display: grid; gap: .375rem; }
.detail-row {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: .5rem;
    font-size: .875rem;
    align-items: baseline;
}
.detail-label { font-weight: 600; color: var(--bs-body-color); }
.detail-value { color: var(--bs-secondary-color); word-break: break-word; }
</style>
