<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import ClinicalAlertBanner from '@/Components/Panel/ClinicalSafety/ClinicalAlertBanner.vue';
import { clinicalTexts, tc, requestJson } from '@/Support/clinicalSafety.js';
import { latestRequest, isAbortError } from '@/Support/latestRequest.js';

// Rótulo compartilhado (lang/<locale>/ui.php → patient_form).
const page = usePage();
const ui = computed(() => page?.props?.t_ui?.patient_form ?? {});

const props = defineProps({
    open: { type: Boolean, required: true },
    patientId: { type: [String, Number], default: null },
});

defineEmits(['close']);
const loading = ref(false);
const patient = ref(null);

// Alergias, medicações em uso e alertas de risco ocular (Fase 2) — leitura
// registrada no servidor (dado sensível).
const clinicalT = clinicalTexts();
const ctt = (path, fallback) => tc(clinicalT, path, fallback);
const safety = ref(null);
const activeMedications = computed(() => (safety.value?.medications ?? []).filter((m) => m.status === 'active'));

// Resposta atrasada de OUTRO paciente (abriu A, fechou, abriu B) não pode
// sobrescrever a gaveta do B: cada carga invalida a anterior; fechar invalida.
const detailReq = latestRequest();
const safetyReq = latestRequest();

async function loadSafety(id) {
    safety.value = null;
    const req = safetyReq.begin();
    try {
        const res = await requestJson(route('panel.patients.clinical-safety.show', id), { signal: req.signal });
        if (req.current()) safety.value = res.ok ? res.data.data : null;
    } catch {
        if (req.current()) safety.value = null;
    }
}

async function loadDetail(id) {
    const req = detailReq.begin();
    loading.value = true;
    patient.value = null;
    loadSafety(id);
    try {
        const res = await fetch(route('panel.patients.show', id), {
            headers: { Accept: 'application/json' },
            signal: req.signal,
        });
        const json = await res.json();
        if (req.current()) patient.value = json.data;
    } catch (error) {
        if (!isAbortError(error) && req.current()) throw error;
    } finally {
        if (req.current()) loading.value = false;
    }
}

watch(
    () => [props.open, props.patientId],
    ([open, id]) => {
        if (open && id) loadDetail(id);
        if (!open) {
            detailReq.cancel();
            safetyReq.cancel();
            loading.value = false;
            patient.value = null;
            safety.value = null;
        }
    },
);

// ── Portal do Paciente: convite (Fase 1 — fundação de conta/login) ───────────
const inviting = ref(false);
const inviteResult = ref(null); // { type: 'success'|'error', message }

function invitePortal() {
    if (!patient.value || inviting.value) return;

    inviting.value = true;
    inviteResult.value = null;

    router.post(
        route('panel.patients.portal-invitation.store', patient.value.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const flash = page.props?.flash ?? {};
                inviteResult.value = flash.success
                    ? { type: 'success', message: flash.success }
                    : { type: 'error', message: flash.error ?? 'Não foi possível enviar o convite.' };
            },
            onError: () => {
                inviteResult.value = { type: 'error', message: 'Não foi possível enviar o convite.' };
            },
            onFinish: () => {
                inviting.value = false;
            },
        },
    );
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="640" :loading="loading" loading-label="Carregando..." @close="$emit('close')">
        <!-- ── Header ─────────────────────────────────────────────────────── -->
        <template #header>
            <div class="d-flex align-items-center gap-3 flex-grow-1 min-width-0">
                <img
                    v-if="patient"
                    :src="patient.photo_url"
                    :alt="patient.full_name"
                    class="rounded-circle flex-shrink-0"
                    style="width: 72px; height: 72px; object-fit: cover; border: 2px solid #dee2e6"
                />
                <div class="min-width-0 flex-grow-1">
                    <h5 class="mb-0 fw-semibold text-truncate">
                        {{ patient?.full_name ?? 'Carregando...' }}
                    </h5>
                    <div v-if="patient" class="d-flex align-items-center gap-2 flex-wrap mt-1">
                        <code class="text-muted small">{{ patient.code }}</code>
                        <span
                            class="badge rounded-pill"
                            :class="
                                patient.active
                                    ? 'bg-success-subtle text-success border border-success'
                                    : 'bg-danger-subtle text-danger border border-danger'
                            "
                            style="font-size: 0.7rem"
                            >{{ patient.active ? 'Ativo' : 'Inativo' }}</span
                        >
                        <span
                            v-if="patient.deleted_at"
                            class="badge bg-secondary rounded-pill"
                            style="font-size: 0.7rem"
                            >Excluído</span
                        >
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Body ───────────────────────────────────────────────────────── -->
        <template v-if="patient">
            <!-- Segurança clínica (Fase 2): alergias + alertas das medicações em uso -->
            <div v-if="safety" class="detail-section" data-testid="patient-drawer-safety">
                <ClinicalAlertBanner :summary="safety" class="mb-2" />
                <div class="detail-section__title">
                    <i class="ti ti-pill me-1" aria-hidden="true"></i>
                    {{ ctt('medications.title', 'Medicações em uso') }}
                </div>
                <ul v-if="activeMedications.length" class="list-unstyled small mb-0">
                    <li v-for="m in activeMedications" :key="m.id">
                        <strong>{{ m.name }}</strong>
                        <span v-if="m.posology" class="text-muted"> — {{ m.posology }}</span>
                        <span v-if="!m.confirmed" class="badge bg-warning-subtle text-warning-emphasis ms-1">{{
                            ctt('snapshot.unconfirmed', 'informada')
                        }}</span>
                    </li>
                </ul>
                <p v-else class="small text-muted mb-0">
                    {{ ctt('medications.empty', 'Nenhuma medicação na lista.') }}
                </p>
            </div>

            <!-- Identificação -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-id-badge me-1"></i> Identificação</div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">Código</span
                        ><span class="detail-value"
                            ><code>{{ patient.code }}</code></span
                        >
                    </div>
                    <div v-if="patient.import_code" class="detail-row">
                        <span class="detail-label">Código de importação</span
                        ><span class="detail-value"
                            ><code>{{ patient.import_code }}</code></span
                        >
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Convênio</span
                        ><span class="detail-value">{{ patient.covenant ?? '—' }}</span>
                    </div>
                    <div v-if="patient.covenant_plan" class="detail-row">
                        <span class="detail-label">{{ ui.plan }}</span
                        ><span class="detail-value">
                            {{ patient.covenant_plan.label }}
                            <small v-if="patient.covenant_plan.sub_label" class="d-block text-muted">{{
                                patient.covenant_plan.sub_label
                            }}</small>
                            <small v-if="patient.covenant_plan.unavailable" class="d-block text-warning-emphasis">
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ ui.plan_unavailable }}
                            </small>
                        </span>
                    </div>
                    <div v-if="patient.card_number" class="detail-row">
                        <span class="detail-label">Nº Cartão</span
                        ><span class="detail-value">{{ patient.card_number }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Tipo de Pele</span
                        ><span class="detail-value">{{ patient.skin_type ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Tipo de Íris</span
                        ><span class="detail-value">{{ patient.iris_type ?? '—' }}</span>
                    </div>
                </div>
            </div>

            <!-- Dados Pessoais -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-user me-1"></i> Dados Pessoais</div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">Nome Completo</span
                        ><span class="detail-value">{{ patient.full_name }}</span>
                    </div>
                    <div v-if="patient.nickname" class="detail-row">
                        <span class="detail-label">Apelido</span
                        ><span class="detail-value">{{ patient.nickname }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">CPF</span><span class="detail-value">{{ patient.cpf ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Nascimento</span>
                        <span class="detail-value">
                            {{ patient.birth_date ?? '—' }}
                            <span v-if="patient.age" class="text-muted small">({{ patient.age }})</span>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Gênero</span
                        ><span class="detail-value">{{ patient.gender || '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Estado Civil</span
                        ><span class="detail-value">{{ patient.marital_status || '—' }}</span>
                    </div>
                    <div v-if="patient.mother_name" class="detail-row">
                        <span class="detail-label">Nome da Mãe</span
                        ><span class="detail-value">{{ patient.mother_name }}</span>
                    </div>
                    <div v-if="patient.occupation" class="detail-row">
                        <span class="detail-label">{{ ui.occupation ?? 'Profissão' }}</span
                        ><span class="detail-value">{{ patient.occupation }}</span>
                    </div>
                    <div v-if="patient.father_name" class="detail-row">
                        <span class="detail-label">Nome do Pai</span
                        ><span class="detail-value">{{ patient.father_name }}</span>
                    </div>
                </div>
            </div>

            <!-- Documentos -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-file-description me-1"></i> Documentos</div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">RG</span><span class="detail-value">{{ patient.rg ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Órgão Expedidor</span
                        ><span class="detail-value">{{ patient.rg_agency ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">UF</span
                        ><span class="detail-value">{{ patient.rg_state ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Data de Emissão</span
                        ><span class="detail-value">{{ patient.rg_date ?? '—' }}</span>
                    </div>
                </div>
            </div>

            <!-- Contato -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-phone me-1"></i> Contato</div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">E-mail</span
                        ><span class="detail-value">{{ patient.email ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Telefone</span
                        ><span class="detail-value">{{ patient.telephone ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Celular</span>
                        <span class="detail-value">
                            {{ patient.cellphone ?? '—' }}
                            <span
                                v-if="patient.whatsapp"
                                class="badge bg-success-subtle text-success border border-success ms-1 rounded-pill"
                                style="font-size: 0.65rem"
                                >WhatsApp</span
                            >
                            <span
                                v-if="patient.whatsapp_opted_out"
                                class="badge bg-warning-subtle text-warning-emphasis border border-warning ms-1 rounded-pill"
                                style="font-size: 0.65rem"
                                :title="ui.wa_opted_out_hint"
                                data-test="whatsapp-opted-out"
                                ><i class="ti ti-bell-off"></i> {{ ui.wa_opted_out }}</span
                            >
                        </span>
                    </div>
                </div>
            </div>

            <!-- Portal do Paciente -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-heart-handshake me-1"></i> Portal do Paciente</div>
                <p class="text-muted small mb-2">
                    Envia um convite por e-mail para o paciente criar login único de acesso ao Portal do Paciente (visão
                    apenas das clínicas onde já foi atendido).
                </p>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    :disabled="!patient.email || inviting"
                    @click="invitePortal"
                >
                    <i v-if="inviting" class="ti ti-loader-2 ee-spin me-1"></i>
                    <i v-else class="ti ti-mail-forward me-1"></i>
                    Convidar para o portal
                </button>
                <div v-if="!patient.email" class="form-text text-warning mt-1">
                    <i class="ti ti-alert-triangle me-1"></i>Cadastre um e-mail para habilitar o convite.
                </div>
                <div
                    v-if="inviteResult"
                    class="small mt-2"
                    :class="inviteResult.type === 'success' ? 'text-success' : 'text-danger'"
                >
                    <i
                        :class="inviteResult.type === 'success' ? 'ti ti-circle-check' : 'ti ti-alert-circle'"
                        class="me-1"
                    ></i>
                    {{ inviteResult.message }}
                </div>
            </div>

            <!-- Endereço -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-map-pin me-1"></i> Endereço</div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">CEP</span
                        ><span class="detail-value">{{ patient.zipcode ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Endereço</span>
                        <span class="detail-value">
                            {{ patient.address ?? '—' }}
                            <template v-if="patient.number">, {{ patient.number }}</template>
                            <template v-if="patient.complement"> — {{ patient.complement }}</template>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Bairro</span
                        ><span class="detail-value">{{ patient.district ?? '—' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Cidade / UF</span>
                        <span class="detail-value"
                            >{{ patient.city ?? '—' }}{{ patient.state ? ` / ${patient.state}` : '' }}</span
                        >
                    </div>
                </div>
            </div>

            <!-- Sistema -->
            <div class="detail-section">
                <div class="detail-section__title"><i class="ti ti-info-circle me-1"></i> Sistema</div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span class="detail-label">Parceiro</span
                        ><span class="detail-value">{{ patient.partner ? 'Sim' : 'Não' }}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Criado em</span
                        ><span class="detail-value">{{ patient.created_at ?? '—' }}</span>
                    </div>
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
.min-width-0 {
    min-width: 0;
}
.detail-section {
    margin-bottom: 1.5rem;
}
.detail-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.detail-table {
    display: grid;
    gap: 0.375rem;
}
.detail-row {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.detail-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.detail-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
</style>
