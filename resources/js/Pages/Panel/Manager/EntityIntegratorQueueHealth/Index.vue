<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * "O que tá acontecendo agora" na fila local do integrador — dono do SaaS
 * ou suporte conferem pendentes/falhas/bloqueados/enviados de uma clínica
 * ANTES de precisar acessar a máquina remotamente. Read-only; não é ao
 * vivo — é o último retrato que o integrador sincronizou (ver aviso de
 * "sincronizado há Xmin" abaixo).
 */
defineOptions({ name: 'EntityIntegratorQueueHealthIndex' });

const props = defineProps({
    entity: { type: Object, required: true },
    userIntegrator: { type: Object, required: true },
    integrator: { type: Object, required: true },
    health: { type: Object, default: null },
    equipmentNames: { type: Object, default: () => ({}) },
    // Últimos pontos do log de tendência (integrator_queue_health_history,
    // retido 7 dias no banco — aqui só os mais recentes, ver
    // HISTORY_LIMIT no controller). Mais recente primeiro.
    history: { type: Array, default: () => [] },
});

const breadcrumbs = [
    { label: 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: 'Empresas', url: route('manager.entities.index'), active: false },
    { label: props.entity.name, url: '#', active: false },
    {
        label: 'Usuários Integradores',
        url: route('manager.entities.user-integrators.index', props.entity.id),
        active: false,
    },
    { label: props.userIntegrator.name, url: '#', active: false },
    {
        label: 'Integradores',
        url: route('manager.entities.user-integrators.integrators.index', [props.entity.id, props.userIntegrator.id]),
        active: false,
    },
    { label: props.integrator.name, url: '#', active: false },
    { label: 'Fila', url: '#', active: true },
];

const equipmentsUrl = route('manager.entities.user-integrators.integrators.equipments.index', [
    props.entity.id,
    props.userIntegrator.id,
    props.integrator.id,
]);

// Ciclo de sync do integrador é ~5min (ver docs/QUEUE_HEALTH.md do
// integrator) — acima de 3x isso sem sincronizar é sinal de que a máquina
// está desligada, sem internet, ou o processo não está rodando; não
// necessariamente um problema na FILA em si, mas vale avisar que o
// retrato pode estar desatualizado.
const STALE_AFTER_MINUTES = 15;

const minutesSinceSync = computed(() => {
    if (!props.health) return null;
    const diffMs = Date.now() - new Date(props.health.synced_at).getTime();
    return Math.round(diffMs / 60000);
});

const isStale = computed(() => minutesSinceSync.value !== null && minutesSinceSync.value > STALE_AFTER_MINUTES);
const commandsUrl = computed(() =>
    route('manager.entities.user-integrators.integrators.commands.index', [
        props.entity.id,
        props.userIntegrator.id,
        props.integrator.id,
    ]),
);
const operational = computed(() => props.health?.operational ?? null);
const captureNeedsAttention = computed(
    () =>
        operational.value &&
        (operational.value.ingest_pending > 0 ||
            operational.value.quarantined > 0 ||
            operational.value.acquisition_rejected > 0 ||
            operational.value.unconfirmed_sent > 0 ||
            operational.value.originals_pending_remote_archive > 0),
);

function issueLabel(issue) {
    return (
        {
            dicom_invalid_or_unavailable: 'Verificar arquivo DICOM e disponibilidade do aparelho.',
            source_or_spool_unavailable: 'Verificar pasta de origem, espaço e permissões de gravação.',
            upload_size_exceeded: 'Original excede o limite de envio; revisão necessária.',
            unsupported_file_type: 'Formato do arquivo requer revisão.',
        }[issue] ?? 'Nenhuma recusa registrada.'
    );
}

function observedLabel(value) {
    if (!value) return 'Sem observação registrada';
    const timestamp = value.replace(' ', 'T');
    return new Date(/[zZ]|[+-]\d{2}:\d{2}$/.test(timestamp) ? timestamp : `${timestamp}Z`).toLocaleString();
}

function runtimeStateLabel(state) {
    return (
        { active: 'Ativo conforme última observação', inactive: 'Inativo conforme última observação' }[state] ??
        'Desconhecido'
    );
}

function deviceLabel(device) {
    const remote = props.equipmentNames[device.remote_equipment_id];
    return remote
        ? `${remote.name} (${remote.code}) — aparelho local #${device.equipment_id}`
        : `Aparelho local #${device.equipment_id}`;
}

const capabilityLabels = {
    folder_capture: 'Captura de pastas',
    dicom_storage: 'Recepção DICOM',
    dicom_mwl: 'Worklist DICOM',
    ocr: 'OCR',
    rpa: 'Automação RPA',
};

function syncedLabel() {
    if (minutesSinceSync.value === null) return '';
    if (minutesSinceSync.value < 1) return 'agora mesmo';
    if (minutesSinceSync.value === 1) return 'há 1 minuto';
    if (minutesSinceSync.value < 60) return `há ${minutesSinceSync.value} minutos`;
    const hours = Math.round(minutesSinceSync.value / 60);
    return hours === 1 ? 'há 1 hora' : `há ${hours} horas`;
}

function statusBadgeClass(status) {
    return status === 'blocked'
        ? 'badge-soft-danger text-danger border border-danger'
        : 'badge-soft-warning text-warning border border-warning';
}

function technicalStateLabel(state) {
    return (
        {
            fresh: 'Agenda atual',
            offline_snapshot: 'Snapshot offline válido',
            blocked: 'Bloqueado',
            unavailable: 'Indisponível',
            refresh_failed: 'Falha ao atualizar agenda',
            not_configured: 'Sem atualização configurada',
            prepared: 'Atualização preparada',
            downloaded: 'Download concluído',
            installing: 'Instalação em andamento',
            awaiting_restart: 'Aguardando reinício',
            reboot_required: 'Reinício necessário',
            failed: 'Falhou',
            installed: 'Versão instalada confirmada',
        }[state] ?? 'Estado desconhecido'
    );
}
function statusLabel(status) {
    return status === 'blocked' ? 'Bloqueado' : 'Falha';
}
</script>

<template>
    <AppLayout title="Fila do Integrador" :breadcrumbs="breadcrumbs">
        <div>
            <PageHeader title="Fila do Integrador">
                <template #actions>
                    <a :href="equipmentsUrl" class="btn btn-sm btn-outline-secondary">
                        <i class="ti ti-device-laptop me-1"></i>Ver equipamentos
                    </a>
                </template>
            </PageHeader>
            <a :href="commandsUrl" class="btn btn-outline-primary mb-3">Solicitar diagnóstico ou sincronização</a>
            <div
                v-if="operational?.configuration_sync || operational?.worklist || operational?.update"
                class="card mb-3"
            >
                <div class="card-body">
                    <h2 class="h5">Configuração, agenda e atualização</h2>
                    <p v-if="operational.configuration_sync">
                        Configurações: {{ operational.configuration_sync.pending }} pendentes ·
                        {{ operational.configuration_sync.needs_review }} para revisão.
                    </p>
                    <ul v-if="operational.worklist?.length">
                        <li v-for="worklist in operational.worklist" :key="worklist.equipment_id">
                            Aparelho {{ worklist.equipment_id }}: {{ technicalStateLabel(worklist.state) }} · validade
                            {{ worklist.expires_at ?? 'indisponível' }}
                        </li>
                    </ul>
                    <p v-if="operational.update">
                        Atualização {{ technicalStateLabel(operational.update.state) }} · versão instalada
                        {{ operational.update.installed_version ?? operational.version }} · canal
                        {{ operational.update.channel }}
                    </p>
                </div>
            </div>

            <div class="mb-3 text-muted small">
                {{ integrator.name }} <code class="ms-1">{{ integrator.code }}</code>
            </div>

            <!-- Sem retrato ainda: integrador nunca sincronizou (versão antiga,
                 ou nunca chegou a rodar de verdade). -->
            <div v-if="!health" class="alert alert-secondary d-flex align-items-center">
                <i class="ti ti-info-circle me-2 fs-5"></i>
                <span>
                    Este integrador ainda não sincronizou o estado da fila. Ou é uma versão do integrador anterior a
                    este recurso, ou ele nunca chegou a se conectar de verdade — confirme com a clínica.
                </span>
            </div>

            <template v-else>
                <div
                    class="alert d-flex align-items-center mb-3"
                    :class="isStale ? 'alert-warning' : 'alert-light border'"
                >
                    <i class="ti me-2 fs-5" :class="isStale ? 'ti-alert-triangle' : 'ti-clock'"></i>
                    <span>
                        Sincronizado {{ syncedLabel() }}
                        <span v-if="isStale">
                            — mais de {{ STALE_AFTER_MINUTES }} min sem notícia deste integrador. Pode estar desligado,
                            sem internet, ou o processo parado na clínica.
                        </span>
                        <span v-else>— não é uma consulta ao vivo na máquina da clínica.</span>
                    </span>
                </div>

                <!-- Cards de contagem — mesmos 4 números que a aba "Fila" do
                     próprio integrador mostra (Pendentes/Falhas/Bloqueados/
                     Enviados), pra dar continuidade visual com o que o
                     operador da clínica já vê localmente. -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body text-center">
                                <div class="fs-3 fw-bold">{{ health.pending_count }}</div>
                                <div class="text-muted small">Pendentes</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body text-center">
                                <div class="fs-3 fw-bold" :class="{ 'text-warning': health.failed_count > 0 }">
                                    {{ health.failed_count }}
                                </div>
                                <div class="text-muted small">Falhas (retentando)</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body text-center">
                                <div class="fs-3 fw-bold" :class="{ 'text-danger': health.blocked_count > 0 }">
                                    {{ health.blocked_count }}
                                </div>
                                <div class="text-muted small">Bloqueados</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body text-center">
                                <div class="fs-3 fw-bold text-success">{{ health.sent_last_24h_count }}</div>
                                <div class="text-muted small">Enviados (24h)</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="operational" class="card mb-3" data-testid="capture-health">
                    <div class="card-header fw-medium">Captura e confirmação dos exames</div>
                    <div class="card-body">
                        <p class="small mb-2">
                            Versão: {{ operational.version ?? 'não informada' }}. Último contato informado:
                            {{ observedLabel(operational.heartbeat_at) }}.
                        </p>
                        <p class="small mb-2">
                            Monitor de pastas: {{ runtimeStateLabel(operational.watcher_state) }}. Worklist:
                            {{ runtimeStateLabel(operational.mwl_state) }}.
                        </p>
                        <p v-if="operational.capabilities" class="small mb-2">
                            Recursos disponíveis nesta versão:
                            <span v-for="(label, key) in capabilityLabels" :key="key" class="me-2">
                                {{ label }}:
                                {{ operational.capabilities[key] === true ? 'disponível' : 'indisponível' }}.
                            </span>
                            A disponibilidade e o contato não comprovam que os processos estão ativos.
                        </p>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                Aguardando gravação: <strong>{{ operational.ingest_pending }}</strong>
                            </div>
                            <div class="col-md-3">
                                Em quarentena: <strong>{{ operational.quarantined }}</strong>
                            </div>
                            <div class="col-md-3">
                                Recusas antes da fila: <strong>{{ operational.acquisition_rejected }}</strong>
                            </div>
                            <div class="col-md-3">
                                Sem recibo remoto: <strong>{{ operational.unconfirmed_sent ?? 0 }}</strong>
                            </div>
                            <div class="col-md-3">
                                Originais locais sem arquivamento remoto:
                                <strong>{{ operational.originals_pending_remote_archive ?? 0 }}</strong>
                            </div>
                        </div>
                        <p v-if="captureNeedsAttention" class="alert alert-warning mb-2">
                            Próxima ação: {{ operational.next_action }}.
                        </p>
                        <p class="text-muted small mb-2">
                            Mais antigo pendente: {{ observedLabel(operational.oldest_pending_at) }}. Espaço livre:
                            {{
                                operational.disk_free_bytes === null
                                    ? 'não informado'
                                    : `${Math.floor(operational.disk_free_bytes / 1048576)} MiB`
                            }}.
                        </p>
                        <div
                            v-for="device in operational.devices"
                            :key="device.equipment_id"
                            class="small border-top py-2"
                        >
                            {{ deviceLabel(device) }} — observado {{ observedLabel(device.last_observed_at) }}; aceito
                            {{ observedLabel(device.last_accepted_at) }}; recusas {{ device.rejected_count }}.
                            <span v-if="device.issue" class="text-warning">{{ issueLabel(device.issue) }}</span>
                        </div>
                        <p class="text-muted small mb-0">
                            Ausência de eventos não comprova que o aparelho deixou de produzir exames. Confira a última
                            captura esperada.
                        </p>
                    </div>
                </div>

                <div
                    v-if="health.blocked_count === 0 && health.failed_count === 0 && !captureNeedsAttention"
                    class="alert alert-success d-flex align-items-center"
                >
                    <i class="ti ti-circle-check me-2 fs-5"></i>
                    <span>Fila de envios sem bloqueios ou falhas no último retrato.</span>
                </div>

                <div v-if="health.blocked_count > 0 || health.failed_count > 0" class="card">
                    <div class="card-header fw-medium">
                        Itens com problema
                        <span class="text-muted fw-normal small">
                            (últimos {{ health.problems.length }} — lista truncada no próprio integrador)
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-nowrap table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Arquivo</th>
                                    <th>Status</th>
                                    <th>Tent.</th>
                                    <th>Vínculo</th>
                                    <th>Detalhes</th>
                                    <th>Atualizado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in health.problems" :key="item.id">
                                    <td class="text-muted small">#{{ item.id }}</td>
                                    <td>
                                        <code class="small">{{ item.file_name }}</code>
                                    </td>
                                    <td>
                                        <span
                                            class="badge rounded fs-13 fw-medium"
                                            :class="statusBadgeClass(item.status)"
                                        >
                                            {{ statusLabel(item.status) }}
                                        </span>
                                    </td>
                                    <td class="text-muted small">{{ item.attempts }}</td>
                                    <td>
                                        <code v-if="item.schedule_identifier" class="small">{{
                                            item.schedule_identifier
                                        }}</code>
                                        <code v-else-if="item.patient_identifier" class="small">{{
                                            item.patient_identifier
                                        }}</code>
                                        <span v-else class="text-muted small">—</span>
                                    </td>
                                    <td class="small">
                                        {{ item.last_error ?? '—' }}
                                        <span v-if="item.api_status" class="text-muted"
                                            >(HTTP {{ item.api_status }})</span
                                        >
                                    </td>
                                    <td class="text-muted small">{{ new Date(item.updated_at).toLocaleString() }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Log de tendência (pedido original: "como log a fila do
                     integrador") — os contadores ao longo do tempo, não só
                     "agora". Retido 7 dias no banco; aqui só os últimos
                     pontos (ver HISTORY_LIMIT no controller). -->
                <div v-if="history.length > 0" class="card mt-3">
                    <div class="card-header fw-medium">
                        Tendência recente
                        <span class="text-muted fw-normal small">
                            (últimos {{ history.length }} pontos — histórico completo retido 7 dias)
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-nowrap table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Sincronizado</th>
                                    <th class="text-end">Pendentes</th>
                                    <th class="text-end">Falhas</th>
                                    <th class="text-end">Bloqueados</th>
                                    <th class="text-end">Enviados (24h)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(point, index) in history" :key="index">
                                    <td class="text-muted small">{{ new Date(point.synced_at).toLocaleString() }}</td>
                                    <td class="text-end">{{ point.pending_count }}</td>
                                    <td class="text-end" :class="{ 'text-warning fw-medium': point.failed_count > 0 }">
                                        {{ point.failed_count }}
                                    </td>
                                    <td class="text-end" :class="{ 'text-danger fw-medium': point.blocked_count > 0 }">
                                        {{ point.blocked_count }}
                                    </td>
                                    <td class="text-end text-success">{{ point.sent_last_24h_count }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
