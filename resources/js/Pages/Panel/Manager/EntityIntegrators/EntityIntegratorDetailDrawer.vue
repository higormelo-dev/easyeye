<script setup>
import { ref, watch } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

/**
 * Drawer de detalhes do Integrador.
 * Mostra identidade, dados de rede e contagem de tokens Sanctum ativos
 * (emitidos pelo equipamento via POST /api/integrators).
 */
const props = defineProps({
    open:    { type: Boolean, required: true },
    showUrl: { type: String,  default: '' },
    t:       { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close', 'edit']);
const loading = ref(false);
const item    = ref(null);

async function loadDetail(url) {
    loading.value = true;
    item.value    = null;
    try {
        const res  = await fetch(url, { headers: { Accept: 'application/json' } });
        const json = await res.json();
        item.value = json.data;
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val && props.showUrl) {
        loadDetail(props.showUrl);
    }
    if (!val) {
        item.value = null;
    }
});
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="480"
        :loading="loading"
        :loading-label="t.detail_loading"
        @close="$emit('close')"
    >
        <!-- Header -->
        <template #header>
            <div>
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-plug me-2 text-info"></i>
                    {{ item?.name ?? t.detail_loading }}
                </h5>
                <code v-if="item" class="text-muted small">{{ item.code }}</code>
            </div>
            <button
                v-if="item && !item.deleted"
                class="btn btn-sm btn-outline-primary ms-2"
                @click="$emit('edit', item)"
            >
                <i class="ti ti-edit me-1"></i> {{ t.detail_btn_edit ?? 'Editar' }}
            </button>
        </template>

        <!-- Body -->
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
            </div>

            <!-- Section: Identidade -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-id-badge-2 me-1"></i> {{ t.detail_section_identity ?? 'Identidade' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.col_code ?? 'Código' }}</span>
                        <span class="detail-value"><code>{{ item.code }}</code></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.col_name ?? 'Nome' }}</span>
                        <span class="detail-value">{{ item.name }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.detail_registered_at ?? 'Cadastrado em' }}</span>
                        <span class="detail-value">{{ item.created_at || '—' }}</span>
                    </div>
                </div>
            </div>

            <!-- Section: Rede -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-network me-1"></i> {{ t.detail_section_network ?? 'Rede' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.col_ip ?? 'IP' }}</span>
                        <span class="detail-value"><code>{{ item.ip || '—' }}</code></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.col_mac ?? 'MAC' }}</span>
                        <span class="detail-value"><code>{{ item.mac || '—' }}</code></span>
                    </div>
                </div>
            </div>

            <!-- Section: Segurança / Tokens -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-shield-lock me-1"></i> {{ t.detail_section_security ?? 'Segurança' }}
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.detail_active_tokens ?? 'Tokens ativos' }}</span>
                        <span class="detail-value">
                            <span class="badge bg-info-subtle text-info-emphasis fw-medium">
                                {{ item.active_tokens ?? 0 }}
                            </span>
                        </span>
                    </div>
                </div>
                <small class="text-muted d-block mt-1">
                    {{ t.detail_active_tokens_hint }}
                </small>
            </div>

            <!-- Section: Equipamentos vinculados -->
            <div class="detail-section">
                <div class="detail-section__title">
                    <i class="ti ti-device-laptop me-1"></i> {{ t.detail_section_equipments ?? 'Equipamentos vinculados' }}
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <span class="fs-5 fw-semibold text-info">
                        {{ item.equipments_count ?? 0 }}
                    </span>
                    <a
                        v-if="item.equipments_url && !item.deleted"
                        :href="item.equipments_url"
                        class="btn btn-sm btn-outline-info"
                    >
                        <i class="ti ti-external-link me-1"></i>
                        {{ t.detail_open_equipments ?? 'Abrir equipamentos' }}
                    </a>
                </div>
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.detail-section { margin-bottom: 1.5rem; }
.detail-section__title {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--bs-secondary-color);
    margin-bottom: .5rem;
    padding-bottom: .25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
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
