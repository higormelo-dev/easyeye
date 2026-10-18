<script setup>
import { computed, ref, watch } from 'vue';
const props = defineProps({
    municipality: { type: Object, default: null },
    enabled: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
defineEmits(['save', 'refresh', 'search']);
const ibgeCode = ref(props.municipality?.codigo_municipio ?? '');
const query = ref('');
const uf = ref('');
const capabilities = computed(() => {
    if (!props.municipality) return [];
    return [
        'nfse_habilitada',
        'requer_certificado_nfse',
        'possui_ambiente_homologacao_nfse',
        'possui_cancelamento_nfse',
        'endereco_obrigatorio_nfse',
        'cpf_cnpj_obrigatorio_nfse',
        'codigo_cnae_obrigatorio_nfse',
        'item_lista_servico_obrigatorio_nfse',
        'codigo_tributario_municipio_obrigatorio_nfse',
    ].map((key) => ({ key, value: Boolean(props.municipality[key]) }));
});
watch(
    () => props.municipality,
    (value) => {
        ibgeCode.value = value?.codigo_municipio ?? '';
    },
);
</script>

<template>
    <section class="card h-100">
        <div class="card-header bg-transparent">
            <h2 class="h6 mb-0">
                <i class="ti ti-building-community me-2" aria-hidden="true"></i>{{ t.page?.municipality }}
            </h2>
        </div>
        <div class="card-body">
            <div v-if="!enabled" class="alert alert-warning small mb-3">{{ t.municipality?.master_required }}</div>
            <p v-else-if="municipality" class="small mb-3">
                <span class="badge badge-soft-success me-1">{{ t.municipality?.configured }}</span
                >{{ municipality.nome_municipio }} — {{ municipality.sigla_uf }}
            </p>
            <dl v-if="municipality" class="row small mb-3">
                <dt class="col-5">{{ t.municipality?.provider }}</dt>
                <dd class="col-7">{{ municipality.provedor_nfse || '—' }}</dd>
                <dt class="col-5">{{ t.municipality?.status }}</dt>
                <dd class="col-7">{{ municipality.status_nfse || '—' }}</dd>
                <template v-for="item in capabilities" :key="item.key">
                    <dt class="col-8 fw-normal">{{ t.municipality?.[item.key] }}</dt>
                    <dd class="col-4">
                        <span class="badge" :class="item.value ? 'badge-soft-success' : 'badge-soft-secondary'">{{
                            item.value ? t.municipality?.yes : t.municipality?.no
                        }}</span>
                    </dd>
                </template>
            </dl>
            <p v-else class="small text-muted mb-3">{{ t.municipality?.not_configured }}</p>
            <div class="row g-2">
                <div class="col-sm-7">
                    <label for="nfse-municipality-code" class="form-label">{{ t.municipality?.ibge_code }}</label
                    ><input
                        id="nfse-municipality-code"
                        v-model="ibgeCode"
                        class="form-control"
                        inputmode="numeric"
                        maxlength="7"
                        :disabled="!enabled"
                    />
                </div>
                <div class="col-sm-5 d-flex align-items-end">
                    <button
                        type="button"
                        class="btn btn-outline-primary w-100"
                        :disabled="!enabled || saving"
                        @click="$emit('save', ibgeCode)"
                    >
                        {{ t.municipality?.save }}
                    </button>
                </div>
            </div>
            <div class="border-top mt-3 pt-3">
                <label for="nfse-municipality-search" class="form-label">{{ t.municipality?.search }}</label>
                <div class="input-group">
                    <input
                        id="nfse-municipality-search"
                        v-model="query"
                        class="form-control"
                        :disabled="!enabled"
                    /><input
                        v-model="uf"
                        class="form-control"
                        style="max-width: 4rem"
                        maxlength="2"
                        :aria-label="t.municipality?.uf"
                        :disabled="!enabled"
                    /><button
                        type="button"
                        class="btn btn-outline-secondary"
                        :disabled="!enabled"
                        @click="$emit('search', { q: query, uf })"
                    >
                        <i class="ti ti-search" aria-hidden="true"></i
                        ><span class="visually-hidden">{{ t.municipality?.search }}</span>
                    </button>
                </div>
                <div class="form-text">{{ t.municipality?.search_hint }}</div>
            </div>
            <button
                v-if="municipality"
                type="button"
                class="btn btn-link btn-sm px-0 mt-2"
                :disabled="saving"
                @click="$emit('refresh')"
            >
                {{ t.municipality?.refresh }}
            </button>
            <div v-if="error" class="alert alert-danger small mt-3 mb-0">{{ error }}</div>
        </div>
    </section>
</template>
