{{-- ── Resumo de Parceiros ───────────────────────────────────────────────── --}}
<div class="card mgr-chart-card h-100">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="ti ti-users-group me-2"></i>{{ __('manager_dashboard.partners_summary') }}</span>
        <a href="{{ route('panel.manager.partners.index') }}" class="btn btn-sm btn-outline-primary">
            {{ __('manager_dashboard.view_all') }}
        </a>
    </div>
    <div class="card-body">

        {{-- Mini KPIs --}}
        <div class="row g-2 mb-3">
            <div class="col-4">
                <div class="mgr-mini-kpi">
                    <div class="mini-icon mini-icon--partners">
                        <i class="ti ti-users"></i>
                    </div>
                    <div>
                        <div class="mini-value">{{ $stats['totalPartners'] }}</div>
                        <div class="mini-label">{{ __('manager_dashboard.partners') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="mgr-mini-kpi">
                    <div class="mini-icon mini-icon--leads">
                        <i class="ti ti-target-arrow"></i>
                    </div>
                    <div>
                        <div class="mini-value">{{ $stats['leadsActive'] }}</div>
                        <div class="mini-label">{{ __('manager_dashboard.leads_active') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="mgr-mini-kpi">
                    <div class="mini-icon mini-icon--commissions">
                        <i class="ti ti-cash"></i>
                    </div>
                    <div>
                        <div class="mini-value" style="font-size:1rem;">R$ {{ number_format($stats['pendingCommissions'], 0, ',', '.') }}</div>
                        <div class="mini-label">{{ __('manager_dashboard.commissions_pending') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Funil de Leads --}}
        <h6 class="text-muted fw-semibold mb-2" style="font-size:.8125rem;text-transform:uppercase;letter-spacing:.04em;">
            {{ __('manager_dashboard.leads_funnel') }}
        </h6>
        @foreach (\App\Enums\PartnerLeadStatus::cases() as $status)
            @php
                $count = $stats['leadsByStatus'][$status->value] ?? 0;
            @endphp
            <div class="d-flex align-items-center justify-content-between py-1">
                <span class="badge {{ $status->badgeClass() }}">{{ $status->label() }}</span>
                <span class="fw-bold" style="font-size:.875rem;">{{ $count }}</span>
            </div>
        @endforeach

        {{-- Referrals --}}
        <hr class="my-3">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <i class="ti ti-share me-1 text-primary"></i>
                <span class="fw-semibold" style="font-size:.875rem;">{{ __('manager_dashboard.referral_codes') }}</span>
            </div>
            <span class="fw-bold">{{ $stats['activeReferralCodes'] }}</span>
        </div>
        <div class="d-flex align-items-center justify-content-between mt-1">
            <div>
                <i class="ti ti-arrow-right me-1 text-success"></i>
                <span class="fw-semibold" style="font-size:.875rem;">{{ __('manager_dashboard.referral_events') }}</span>
            </div>
            <span class="fw-bold">{{ $stats['totalReferralEvents'] }}</span>
        </div>
    </div>
</div>
