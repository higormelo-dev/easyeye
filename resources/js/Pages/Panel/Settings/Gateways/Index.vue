<script setup>
import { ref } from 'vue';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import GatewayCredentialsModal from './GatewayCredentialsModal.vue';

/**
 * Gateways de pagamento — área da clínica (tenant).
 *
 * Lista APENAS gateways que o SaaS owner habilitou pra essa clínica
 * (entity_gateway_access.enabled = true). Cada gateway pode ter suas
 * credenciais próprias (scope=tenant), separadas das globais (scope=global).
 *
 * Reusa a estrutura de modais do Manager — diferença é o endpoint
 * (tenant-scope em vez de global-scope).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    gateways:    { type: Array, default: () => [] },
});

const credentialsOpen    = ref(false);
const selectedGateway    = ref(null);

function openCredentials(gateway) {
    selectedGateway.value = gateway;
    credentialsOpen.value = true;
}
</script>

<template>
    <AppLayout title="Gateways de pagamento" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Gateways de pagamento" subtitle="Configure as credenciais da clínica para cada gateway disponibilizado pelo SaaS." />

            <div v-if="gateways.length === 0" class="alert alert-info">
                <i class="ti ti-info-circle me-1"></i>
                Nenhum gateway de pagamento habilitado para sua clínica. Entre em contato com o suporte para liberar.
            </div>

            <div v-else class="row g-3">
                <div v-for="gw in gateways" :key="gw.id" class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-start justify-content-between mb-3">
                                <div>
                                    <h6 class="fw-semibold mb-1">{{ gw.name }}</h6>
                                    <code class="small text-muted">{{ gw.code }}</code>
                                </div>
                                <span v-if="gw.has_active_credential"
                                      class="badge badge-soft-success rounded text-success border border-success fs-11">
                                    <i class="ti ti-shield-check me-1"></i>Configurado
                                </span>
                                <span v-else class="badge badge-soft-warning rounded fs-11">
                                    <i class="ti ti-shield-off me-1"></i>Pendente
                                </span>
                            </div>

                            <div class="small text-muted mb-3 flex-grow-1">
                                <div class="mb-1">
                                    <i class="ti ti-key me-1"></i>
                                    {{ gw.credentials_count }} credencial(is) registrada(s)
                                </div>
                            </div>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                @click="openCredentials(gw)"
                            >
                                <i class="ti ti-settings me-1"></i>Gerenciar credenciais
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <GatewayCredentialsModal
                :open="credentialsOpen"
                :gateway="selectedGateway"
                @close="credentialsOpen = false"
            />
        </div>
    </AppLayout>
</template>
