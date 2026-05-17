<script setup>
const props = defineProps({
    partnersSummary: { type: Object, required: true },
    t:               { type: Object, required: true },
});

const LEAD_STATUS_BADGE = {
    new:       'badge-soft-info rounded text-info border border-info fs-13 fw-medium',
    contacted: 'badge-soft-primary rounded text-primary border border-primary fs-13 fw-medium',
    trial:     'badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium',
    converted: 'badge-soft-success rounded text-success border border-success fs-13 fw-medium',
    lost:      'badge-soft-secondary rounded fs-13 fw-medium',
};

const LEAD_STATUS_LABEL = {
    new:       'Novo',
    contacted: 'Contatado',
    trial:     'Trial',
    converted: 'Convertido',
    lost:      'Perdido',
};

const LEAD_STATUS_ORDER = ['new', 'contacted', 'trial', 'converted', 'lost'];

function brl(value) {
    return Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}
</script>

<template>
    <div class="card mgr-chart-card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="ti ti-users-group me-2"></i>{{ t.partners_summary }}</span>
            <a :href="route('manager.partners.index')" class="btn btn-sm btn-outline-primary">
                {{ t.view_all }}
            </a>
        </div>
        <div class="card-body">

            <!-- Mini KPIs -->
            <div class="row g-2 mb-3">
                <div class="col-4">
                    <div class="mgr-mini-kpi">
                        <div class="mini-icon mini-icon--partners">
                            <i class="ti ti-users"></i>
                        </div>
                        <div>
                            <div class="mini-value">{{ partnersSummary.totalPartners }}</div>
                            <div class="mini-label">{{ t.partners }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="mgr-mini-kpi">
                        <div class="mini-icon mini-icon--leads">
                            <i class="ti ti-target-arrow"></i>
                        </div>
                        <div>
                            <div class="mini-value">{{ partnersSummary.leadsActive }}</div>
                            <div class="mini-label">{{ t.leads_active }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="mgr-mini-kpi">
                        <div class="mini-icon mini-icon--commissions">
                            <i class="ti ti-cash"></i>
                        </div>
                        <div>
                            <div class="mini-value" style="font-size:1rem;">
                                R$ {{ brl(partnersSummary.pendingCommissions) }}
                            </div>
                            <div class="mini-label">{{ t.commissions_pending }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Funil de Leads -->
            <h6 class="text-muted fw-semibold mb-2" style="font-size:.8125rem;text-transform:uppercase;letter-spacing:.04em;">
                {{ t.leads_funnel }}
            </h6>
            <div
                v-for="status in LEAD_STATUS_ORDER"
                :key="status"
                class="d-flex align-items-center justify-content-between py-1"
            >
                <span :class="['badge', LEAD_STATUS_BADGE[status]]">{{ LEAD_STATUS_LABEL[status] }}</span>
                <span class="fw-bold" style="font-size:.875rem;">
                    {{ partnersSummary.leadsByStatus?.[status] ?? 0 }}
                </span>
            </div>

            <!-- Referrals -->
            <hr class="my-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <i class="ti ti-share me-1 text-primary"></i>
                    <span class="fw-semibold" style="font-size:.875rem;">{{ t.referral_codes }}</span>
                </div>
                <span class="fw-bold">{{ partnersSummary.activeReferralCodes }}</span>
            </div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <div>
                    <i class="ti ti-arrow-right me-1 text-success"></i>
                    <span class="fw-semibold" style="font-size:.875rem;">{{ t.referral_events }}</span>
                </div>
                <span class="fw-bold">{{ partnersSummary.totalReferralEvents }}</span>
            </div>
        </div>
    </div>
</template>
