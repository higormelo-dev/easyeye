<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout         from '@/Layouts/AppLayout.vue';
import PrimaryKpis       from './ManagerDashboard/PrimaryKpis.vue';
import FinancialKpis     from './ManagerDashboard/FinancialKpis.vue';
import MrrTrendChart     from './ManagerDashboard/MrrTrendChart.vue';
import GrowthChart       from './ManagerDashboard/GrowthChart.vue';
import ConversionFunnel  from './ManagerDashboard/ConversionFunnel.vue';
import SubscriptionFunnel from './ManagerDashboard/SubscriptionFunnel.vue';
import TrialsExpiring    from './ManagerDashboard/TrialsExpiring.vue';
import RecentEntities    from './ManagerDashboard/RecentEntities.vue';
import TopEntities       from './ManagerDashboard/TopEntities.vue';
import PartnersSummary   from './ManagerDashboard/PartnersSummary.vue';

const props = defineProps({
    greeting:         { type: String,  default: '' },
    primaryKpis:      { type: Object,  required: true },
    subscriptionKpis: { type: Object,  required: true },
    financialKpis:    { type: Object,  required: true },
    mrrTrend:         { type: Object,  required: true },
    conversionFunnel: { type: Object,  required: true },
    growthTrend:      { type: Object,  required: true },
    trialsExpiring:   { type: Array,   default: () => [] },
    recentEntities:   { type: Array,   default: () => [] },
    topEntities:      { type: Array,   default: () => [] },
    partnersSummary:  { type: Object,  required: true },
    t:                { type: Object,  required: true },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});

const breadcrumbs = [];
</script>

<template>
    <AppLayout title="Dashboard" :breadcrumbs="breadcrumbs">
        <div class="page-dashboard">

            <!-- ── Welcome Banner ── -->
            <div class="welcome-banner mb-4 mt-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <h4 class="mb-1">{{ greeting }}, {{ user.name?.split(' ')[0] }}! 👋</h4>
                        <p>{{ t.subtitle }}</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap" style="position:relative;z-index:1">
                        <a :href="route('manager.entities.index')" class="btn btn-sm btn-banner">
                            <i class="ti ti-building me-1"></i>{{ t.btn_entities }}
                        </a>
                        <a :href="route('manager.plans.index')" class="btn btn-sm btn-banner">
                            <i class="ti ti-list-check me-1"></i>{{ t.btn_plans }}
                        </a>
                        <a :href="route('manager.partners.index')" class="btn btn-sm btn-banner btn-banner-solid">
                            <i class="ti ti-users-group me-1"></i>{{ t.btn_partners }}
                        </a>
                        <a :href="route('manager.ai-providers.index')" class="btn btn-sm btn-banner">
                            <i class="ti ti-robot me-1"></i>{{ t.btn_ai_providers ?? 'Provedores de IA' }}
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── KPIs Primários ── -->
            <PrimaryKpis
                :primary-kpis="primaryKpis"
                :subscription-kpis="subscriptionKpis"
                :t="t"
            />

            <!-- ── KPIs Financeiros SaaS ── -->
            <FinancialKpis
                :financial-kpis="financialKpis"
                :t="t"
            />

            <!-- ── MRR Trend + Funil de Conversão ── -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-7">
                    <MrrTrendChart
                        :mrr-trend="mrrTrend"
                        :financial-kpis="financialKpis"
                        :t="t"
                    />
                </div>
                <div class="col-12 col-lg-5">
                    <ConversionFunnel
                        :conversion-funnel="conversionFunnel"
                        :t="t"
                    />
                </div>
            </div>

            <!-- ── Funil de Assinaturas + Crescimento Mensal ── -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-6">
                    <SubscriptionFunnel
                        :subscription-kpis="subscriptionKpis"
                        :t="t"
                    />
                </div>
                <div class="col-12 col-lg-6">
                    <GrowthChart
                        :growth-trend="growthTrend"
                        :t="t"
                    />
                </div>
            </div>

            <!-- ── Trials Expirando + Parceiros ── -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-7">
                    <TrialsExpiring
                        :trials-expiring="trialsExpiring"
                        :t="t"
                    />
                </div>
                <div class="col-12 col-lg-5">
                    <PartnersSummary
                        :partners-summary="partnersSummary"
                        :t="t"
                    />
                </div>
            </div>

            <!-- ── Últimas Clínicas ── -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <RecentEntities
                        :recent-entities="recentEntities"
                        :t="t"
                    />
                </div>
            </div>

            <!-- ── Top 5 Clínicas ── -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <TopEntities
                        :top-entities="topEntities"
                        :t="t"
                    />
                </div>
            </div>

        </div>
    </AppLayout>
</template>

<style>
@import '../../../css/dashboard.css';
@import '../../../css/manager-dashboard.css';
</style>
