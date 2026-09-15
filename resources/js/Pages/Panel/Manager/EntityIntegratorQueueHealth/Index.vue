<script setup>
import { computed } from 'vue';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * "O que tá acontecendo agora" na fila local do integrador — dono do SaaS
 * ou suporte conferem pendentes/falhas/bloqueados/enviados de uma clínica
 * ANTES de precisar acessar a máquina remotamente. Read-only; não é ao
 * vivo — é o último retrato que o integrador sincronizou (ver aviso de
 * "sincronizado há Xmin" abaixo).
 */
const props = defineProps({
    entity:         { type: Object, required: true },
    userIntegrator: { type: Object, required: true },
    integrator:     { type: Object, required: true },
    health:         { type: Object, default: null },
});

const breadcrumbs = [
    { label: 'Dashboard',            url: route('panel.dashboard'),                                                                            active: false },
    { label: 'Empresas',             url: route('manager.entities.index'),                                                                     active: false },
    { label: props.entity.name,                                        url: '#',                                                                active: false },
    { label: 'Usuários Integradores', url: route('manager.entities.user-integrators.index', props.entity.id),                                  active: false },
    { label: props.userIntegrator.name,                                url: '#',                                                                active: false },
    { label: 'Integradores',         url: route('manager.entities.user-integrators.integrators.index', [props.entity.id, props.userIntegrator.id]), active: false },
    { label: props.integrator.name,                                    url: '#',                                                                active: false },
    { label: 'Fila',                 url: '#',                                                                                                  active: true  },
];

const equipmentsUrl = route(
    'manager.entities.user-integrators.integrators.equipments.index',
    [props.entity.id, props.userIntegrator.id, props.integrator.id],
);

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

            <div class="mb-3 text-muted small">
                {{ integrator.name }} <code class="ms-1">{{ integrator.code }}</code>
            </div>

            <!-- Sem retrato ainda: integrador nunca sincronizou (versão antiga,
                 ou nunca chegou a rodar de verdade). -->
            <div v-if="!health" class="alert alert-secondary d-flex align-items-center">
                <i class="ti ti-info-circle me-2 fs-5"></i>
                <span>
                    Este integrador ainda não sincronizou o estado da fila. Ou é uma
                    versão do integrador anterior a este recurso, ou ele nunca chegou
                    a se conectar de verdade — confirme com a clínica.
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
                            — mais de {{ STALE_AFTER_MINUTES }} min sem notícia deste
                            integrador. Pode estar desligado, sem internet, ou o
                            processo parado na clínica.
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

                <div v-if="health.blocked_count === 0 && health.failed_count === 0" class="alert alert-success d-flex align-items-center">
                    <i class="ti ti-circle-check me-2 fs-5"></i>
                    <span>Nada bloqueado ou com falha no último retrato — fila saudável.</span>
                </div>

                <div v-else class="card">
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
                                    <td><code class="small">{{ item.file_name }}</code></td>
                                    <td>
                                        <span class="badge rounded fs-13 fw-medium" :class="statusBadgeClass(item.status)">
                                            {{ statusLabel(item.status) }}
                                        </span>
                                    </td>
                                    <td class="text-muted small">{{ item.attempts }}</td>
                                    <td>
                                        <code v-if="item.schedule_identifier" class="small">{{ item.schedule_identifier }}</code>
                                        <code v-else-if="item.patient_identifier" class="small">{{ item.patient_identifier }}</code>
                                        <span v-else class="text-muted small">—</span>
                                    </td>
                                    <td class="small">
                                        {{ item.last_error ?? '—' }}
                                        <span v-if="item.api_status" class="text-muted">(HTTP {{ item.api_status }})</span>
                                    </td>
                                    <td class="text-muted small">{{ new Date(item.updated_at).toLocaleString() }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
