<script setup>
import { ref, watch } from 'vue';
import { useTrans } from '@/composables/useTrans.js';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:     { type: Boolean, required: true },
    entityId: { type: String,  default: null },
    t:        { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close', 'edit']);
const loading = ref(false);
const entity  = ref(null);

const { tx } = useTrans(() => props.t);

async function loadDetail(id) {
    loading.value = true; entity.value = null;
    try {
        const res  = await fetch(route('manager.entities.show', id));
        const json = await res.json();
        entity.value = json.data;
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val && props.entityId) loadDetail(props.entityId);
    if (!val) entity.value = null;
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
                    <i class="ti ti-building me-2 text-primary"></i>
                    {{ entity?.name ?? t.detail_loading }}
                </h5>
                <code v-if="entity" class="text-muted small">{{ entity.code }}</code>
            </div>
            <button v-if="entity" class="btn btn-sm btn-outline-primary ms-2" @click="$emit('edit', entity.id)">
                <i class="ti ti-edit me-1"></i> {{ t.detail_btn_edit }}
            </button>
        </template>

        <!-- Body -->
        <template v-if="entity">
            <!-- Status -->
            <div class="mb-4">
                <span v-if="entity.deleted_at" class="badge bg-danger">
                    {{ tx('detail_deleted_at', { date: entity.deleted_at }) }}
                </span>
                <span v-else-if="entity.active" class="badge bg-success">{{ t.status_active }}</span>
                <span v-else class="badge bg-secondary">{{ t.status_inactive }}</span>
            </div>

            <!-- Section: Dados da Empresa -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-building me-1"></i> {{ t.section_data }}</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">{{ t.detail_email }}</span><span class="detail-value">{{ entity.email || '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">{{ t.detail_subdomain }}</span><span class="detail-value">{{ entity.subdomain || '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">{{ t.detail_telephone }}</span><span class="detail-value">{{ entity.telephone || '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">{{ t.detail_cellphone }}</span><span class="detail-value">{{ entity.cellphone || '—' }}</span></div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.detail_website }}</span>
                        <span class="detail-value">
                            <a v-if="entity.website" :href="entity.website" target="_blank" rel="noopener" class="text-primary">{{ entity.website }}</a>
                            <span v-else>—</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Section: Documentos -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-file-text me-1"></i> {{ t.section_docs }}</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">{{ t.detail_national_registration }}</span><span class="detail-value">{{ entity.national_registration || '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">{{ t.detail_state_registration }}</span><span class="detail-value">{{ entity.state_registration || '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">{{ t.detail_municipal_registration }}</span><span class="detail-value">{{ entity.municipal_registration || '—' }}</span></div>
                </div>
            </div>

            <!-- Section: Endereço -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-map-pin me-1"></i> {{ t.section_address }}</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">{{ t.detail_zipcode }}</span><span class="detail-value">{{ entity.zipcode || '—' }}</span></div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.detail_address }}</span>
                        <span class="detail-value">
                            {{ entity.address || '—' }}
                            <template v-if="entity.number">, {{ entity.number }}</template>
                            <template v-if="entity.complement"> — {{ entity.complement }}</template>
                        </span>
                    </div>
                    <div class="detail-row"><span class="detail-label">{{ t.detail_district }}</span><span class="detail-value">{{ entity.district || '—' }}</span></div>
                    <div class="detail-row">
                        <span class="detail-label">{{ t.detail_city_state }}</span>
                        <span class="detail-value">{{ entity.city || '—' }}{{ entity.state ? ` / ${entity.state}` : '' }}</span>
                    </div>
                    <div class="detail-row"><span class="detail-label">{{ t.detail_country }}</span><span class="detail-value">{{ entity.country || '—' }}</span></div>
                </div>
            </div>

            <!-- Section: Configurações -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-settings me-1"></i> {{ t.section_config }}</div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">{{ t.detail_schedule_interval }}</span>
                        <span class="detail-value">{{ tx('detail_interval_minutes', { value: entity.schedule_interval }) }}</span>
                    </div>
                    <div class="detail-row"><span class="detail-label">{{ t.detail_registered_at }}</span><span class="detail-value">{{ entity.created_at }}</span></div>
                </div>
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.detail-section { margin-bottom: 1.5rem; }
.detail-section__title {
    font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    color: var(--bs-secondary-color); margin-bottom: .5rem; padding-bottom: .25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.detail-table { display: grid; gap: .375rem; }
.detail-row { display: grid; grid-template-columns: 150px 1fr; gap: .5rem; font-size: .875rem; align-items: baseline; }
.detail-label { font-weight: 600; color: var(--bs-body-color); }
.detail-value { color: var(--bs-secondary-color); word-break: break-word; }
</style>
