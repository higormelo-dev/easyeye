<script setup>
import { ref } from 'vue';
import AppLayout                from '@/Layouts/AppLayout.vue';
import GatewayCard              from './GatewayCard.vue';
import GatewayCredentialsModal  from './GatewayCredentialsModal.vue';
import GatewayEntityAccessModal from './GatewayEntityAccessModal.vue';
import GatewayPriorityModal     from './GatewayPriorityModal.vue';
import GatewayChangeDefaultModal from './GatewayChangeDefaultModal.vue';

const props = defineProps({
    gateways:       { type: Array,  default: () => [] },
    defaultGateway: { type: Object, default: null },
    t:              { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: props.t.breadcrumb ?? 'Gateways de Pagamento' },
];

// ── Credentials modal ─────────────────────────────────────────────────────────
const credOpen    = ref(false);
const credGateway = ref(null);

function openCredentials(g) { credGateway.value = g; credOpen.value = true; }
function closeCredentials() { credOpen.value = false; credGateway.value = null; }

// ── Entity access modal ───────────────────────────────────────────────────────
const eaOpen    = ref(false);
const eaGateway = ref(null);

function openEntityAccess(g) { eaGateway.value = g; eaOpen.value = true; }
function closeEntityAccess() { eaOpen.value = false; eaGateway.value = null; }

// ── Priority modal ────────────────────────────────────────────────────────────
const prioOpen    = ref(false);
const prioGateway = ref(null);

function openPriority(g) { prioGateway.value = g; prioOpen.value = true; }
function closePriority() { prioOpen.value = false; prioGateway.value = null; }

// ── Change default modal ──────────────────────────────────────────────────────
const defaultOpen = ref(false);
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <!-- Page subtitle -->
            <p class="text-muted small mb-4" v-html="t.subtitle"></p>

            <!-- ── Default gateway banner ──────────────────────────────────────── -->
            <div
                v-if="defaultGateway"
                class="alert d-flex align-items-center gap-3 py-3 mb-4"
                style="background:linear-gradient(135deg,#fff8e1 0%,#fffde7 100%);border:1.5px solid #fdd835;border-radius:10px;"
            >
                <div
                    class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle"
                    style="width:42px;height:42px;background:#fdd835;"
                >
                    <i class="ti ti-star text-dark fs-20"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-bold mb-0" style="color:#5d4037;">{{ t.default_banner_title }}</div>
                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                        <span class="fw-semibold" style="color:#333;">{{ defaultGateway.name }}</span>
                        <span class="badge text-uppercase" style="background:#fdd835;color:#5d4037;font-size:.7rem;">{{ defaultGateway.code }}</span>
                        <span class="text-muted small">{{ t.default_banner_subtitle }}</span>
                    </div>
                </div>
                <div class="flex-shrink-0">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        @click="defaultOpen = true"
                    >
                        <i class="ti ti-switch-horizontal me-1"></i>{{ t.default_banner_change }}
                    </button>
                </div>
            </div>

            <div
                v-else
                class="alert alert-danger d-flex align-items-center gap-3 py-3 mb-4"
                style="border-radius:10px;"
            >
                <i class="ti ti-alert-octagon fs-22 flex-shrink-0"></i>
                <div class="flex-grow-1">
                    <strong>{{ t.no_default_title }}</strong>
                    <span class="ms-1 small">{{ t.no_default_subtitle }}</span>
                </div>
                <button
                    type="button"
                    class="btn btn-sm btn-danger flex-shrink-0"
                    @click="defaultOpen = true"
                >
                    <i class="ti ti-star me-1"></i>{{ t.no_default_action }}
                </button>
            </div>

            <!-- ── Context cards ───────────────────────────────────────────────── -->
            <div class="row g-3 mb-4">
                <!-- SaaS Billing -->
                <div class="col-md-6">
                    <div class="card border-primary border-opacity-50 h-100">
                        <div class="card-body py-3">
                            <div class="d-flex gap-3 align-items-start">
                                <div
                                    class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 bg-primary bg-opacity-10"
                                    style="width:38px;height:38px;"
                                >
                                    <i class="ti ti-building-store text-primary fs-18"></i>
                                </div>
                                <div>
                                    <p class="fw-semibold mb-1 small">{{ t.ctx_saas_title }}</p>
                                    <p class="text-muted mb-1" style="font-size:.8rem;" v-html="t.ctx_saas_desc"></p>
                                    <span class="badge badge-soft-primary" style="font-size:.72rem;">{{ t.ctx_saas_badge }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tenant Payment -->
                <div class="col-md-6">
                    <div class="card border-success border-opacity-50 h-100">
                        <div class="card-body py-3">
                            <div class="d-flex gap-3 align-items-start">
                                <div
                                    class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 bg-success bg-opacity-10"
                                    style="width:38px;height:38px;"
                                >
                                    <i class="ti ti-building-hospital text-success fs-18"></i>
                                </div>
                                <div>
                                    <p class="fw-semibold mb-1 small">{{ t.ctx_tenant_title }}</p>
                                    <p class="text-muted mb-1" style="font-size:.8rem;" v-html="t.ctx_tenant_desc"></p>
                                    <span class="badge badge-soft-success" style="font-size:.72rem;">{{ t.ctx_tenant_badge }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Gateway cards grid ──────────────────────────────────────────── -->
            <div class="row g-3">
                <div
                    v-for="gateway in gateways"
                    :key="gateway.id"
                    class="col-md-6 col-xl-4"
                >
                    <GatewayCard
                        :gateway="gateway"
                        :t="t"
                        @open-credentials="openCredentials"
                        @open-entity-access="openEntityAccess"
                        @open-priority="openPriority"
                        @open-set-default="defaultOpen = true"
                    />
                </div>

                <!-- Empty state -->
                <div v-if="gateways.length === 0" class="col-12">
                    <div class="text-center py-5 text-muted">
                        <i class="ti ti-credit-card-off fs-40 d-block mb-2 opacity-40"></i>
                        <p class="small mb-0">Nenhum gateway cadastrado.</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Modals ──────────────────────────────────────────────────────────── -->
        <GatewayChangeDefaultModal
            :open="defaultOpen"
            :gateways="gateways"
            :default-gateway="defaultGateway"
            :t="t"
            @close="defaultOpen = false"
        />

        <GatewayCredentialsModal
            :open="credOpen"
            :gateway="credGateway"
            :t="t"
            @close="closeCredentials"
        />

        <GatewayEntityAccessModal
            :open="eaOpen"
            :gateway="eaGateway"
            :t="t"
            @close="closeEntityAccess"
        />

        <GatewayPriorityModal
            :open="prioOpen"
            :gateway="prioGateway"
            :t="t"
            @close="closePriority"
        />
    </AppLayout>
</template>
