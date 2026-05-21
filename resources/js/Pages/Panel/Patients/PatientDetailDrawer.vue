<script setup>
import { ref, watch } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:      { type: Boolean, required: true },
    patientId: { type: [String, Number], default: null },
});

defineEmits(['close']);
const loading = ref(false);
const patient = ref(null);

async function loadDetail(id) {
    loading.value = true;
    patient.value = null;
    try {
        const res  = await fetch(route('panel.patients.show', id), {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();
        patient.value = json.data;
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val && props.patientId) loadDetail(props.patientId);
    if (!val) patient.value = null;
});
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="640"
        :loading="loading"
        loading-label="Carregando..."
        @close="$emit('close')"
    >
        <!-- ── Header ─────────────────────────────────────────────────────── -->
        <template #header>
            <div class="d-flex align-items-center gap-3 flex-grow-1 min-width-0">
                <img
                    v-if="patient"
                    :src="patient.photo_url"
                    :alt="patient.full_name"
                    class="rounded-circle flex-shrink-0"
                    style="width:72px;height:72px;object-fit:cover;border:2px solid #dee2e6;"
                >
                <div class="min-width-0 flex-grow-1">
                    <h5 class="mb-0 fw-semibold text-truncate">
                        {{ patient?.full_name ?? 'Carregando...' }}
                    </h5>
                    <div v-if="patient" class="d-flex align-items-center gap-2 flex-wrap mt-1">
                        <code class="text-muted small">{{ patient.code }}</code>
                        <span
                            class="badge rounded-pill"
                            :class="patient.active ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger'"
                            style="font-size:.7rem;"
                        >{{ patient.active ? 'Ativo' : 'Inativo' }}</span>
                        <span v-if="patient.deleted_at" class="badge bg-secondary rounded-pill" style="font-size:.7rem;">Excluído</span>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Body ───────────────────────────────────────────────────────── -->
        <template v-if="patient">

            <!-- Identificação -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-id-badge me-1"></i> Identificação</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">Código</span><span class="detail-value"><code>{{ patient.code }}</code></span></div>
                    <div class="detail-row"><span class="detail-label">Convênio</span><span class="detail-value">{{ patient.covenant ?? '—' }}</span></div>
                    <div v-if="patient.card_number" class="detail-row"><span class="detail-label">Nº Cartão</span><span class="detail-value">{{ patient.card_number }}</span></div>
                    <div class="detail-row"><span class="detail-label">Tipo de Pele</span><span class="detail-value">{{ patient.skin_type ?? '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">Tipo de Íris</span><span class="detail-value">{{ patient.iris_type ?? '—' }}</span></div>
                </div>
            </div>

            <!-- Dados Pessoais -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-user me-1"></i> Dados Pessoais</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">Nome Completo</span><span class="detail-value">{{ patient.full_name }}</span></div>
                    <div v-if="patient.nickname" class="detail-row"><span class="detail-label">Apelido</span><span class="detail-value">{{ patient.nickname }}</span></div>
                    <div class="detail-row"><span class="detail-label">CPF</span><span class="detail-value">{{ patient.cpf ?? '—' }}</span></div>
                    <div class="detail-row">
                        <span class="detail-label">Nascimento</span>
                        <span class="detail-value">
                            {{ patient.birth_date ?? '—' }}
                            <span v-if="patient.age" class="text-muted small">({{ patient.age }})</span>
                        </span>
                    </div>
                    <div class="detail-row"><span class="detail-label">Gênero</span><span class="detail-value">{{ patient.gender || '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">Estado Civil</span><span class="detail-value">{{ patient.marital_status || '—' }}</span></div>
                    <div v-if="patient.mother_name" class="detail-row"><span class="detail-label">Nome da Mãe</span><span class="detail-value">{{ patient.mother_name }}</span></div>
                    <div v-if="patient.father_name" class="detail-row"><span class="detail-label">Nome do Pai</span><span class="detail-value">{{ patient.father_name }}</span></div>
                </div>
            </div>

            <!-- Documentos -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-file-description me-1"></i> Documentos</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">RG</span><span class="detail-value">{{ patient.rg ?? '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">Órgão Expedidor</span><span class="detail-value">{{ patient.rg_agency ?? '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">UF</span><span class="detail-value">{{ patient.rg_state ?? '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">Data de Emissão</span><span class="detail-value">{{ patient.rg_date ?? '—' }}</span></div>
                </div>
            </div>

            <!-- Contato -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-phone me-1"></i> Contato</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">E-mail</span><span class="detail-value">{{ patient.email ?? '—' }}</span></div>
                    <div class="detail-row"><span class="detail-label">Telefone</span><span class="detail-value">{{ patient.telephone ?? '—' }}</span></div>
                    <div class="detail-row">
                        <span class="detail-label">Celular</span>
                        <span class="detail-value">
                            {{ patient.cellphone ?? '—' }}
                            <span v-if="patient.whatsapp" class="badge bg-success-subtle text-success border border-success ms-1 rounded-pill" style="font-size:.65rem;">WhatsApp</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Endereço -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-map-pin me-1"></i> Endereço</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">CEP</span><span class="detail-value">{{ patient.zipcode ?? '—' }}</span></div>
                    <div class="detail-row">
                        <span class="detail-label">Endereço</span>
                        <span class="detail-value">
                            {{ patient.address ?? '—' }}
                            <template v-if="patient.number">, {{ patient.number }}</template>
                            <template v-if="patient.complement"> — {{ patient.complement }}</template>
                        </span>
                    </div>
                    <div class="detail-row"><span class="detail-label">Bairro</span><span class="detail-value">{{ patient.district ?? '—' }}</span></div>
                    <div class="detail-row">
                        <span class="detail-label">Cidade / UF</span>
                        <span class="detail-value">{{ patient.city ?? '—' }}{{ patient.state ? ` / ${patient.state}` : '' }}</span>
                    </div>
                </div>
            </div>

            <!-- Sistema -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-info-circle me-1"></i> Sistema</div>
                <div class="detail-table">
                    <div class="detail-row"><span class="detail-label">Parceiro</span><span class="detail-value">{{ patient.partner ? 'Sim' : 'Não' }}</span></div>
                    <div class="detail-row"><span class="detail-label">Criado em</span><span class="detail-value">{{ patient.created_at ?? '—' }}</span></div>
                    <div v-if="patient.updated_at && patient.updated_at !== patient.created_at" class="detail-row">
                        <span class="detail-label">Atualizado em</span>
                        <span class="detail-value">{{ patient.updated_at }}</span>
                    </div>
                    <div v-if="patient.deleted_at" class="detail-row">
                        <span class="detail-label text-danger">Excluído em</span>
                        <span class="detail-value text-danger">{{ patient.deleted_at }}</span>
                    </div>
                </div>
            </div>

        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.min-width-0 { min-width: 0; }
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
