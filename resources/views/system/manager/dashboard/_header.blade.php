{{-- ── Banner de Boas-Vindas ────────────────────────────────────────────── --}}
<div class="welcome-banner mb-4 mt-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h4 class="mb-1">{{ $stats['greeting'] }}, {{ explode(' ', auth()->user()->name)[0] }}! 👋</h4>
            <p>{{ __('manager_dashboard.subtitle') }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap" style="position:relative;z-index:1">
            <a href="{{ route('panel.manager.entities.index') }}" class="btn btn-sm btn-banner">
                <i class="ti ti-building me-1"></i> {{ __('manager_dashboard.btn_entities') }}
            </a>
            <a href="{{ route('panel.manager.plans.index') }}" class="btn btn-sm btn-banner">
                <i class="ti ti-list-check me-1"></i> {{ __('manager_dashboard.btn_plans') }}
            </a>
            <a href="{{ route('panel.manager.partners.index') }}" class="btn btn-sm btn-banner btn-banner-solid">
                <i class="ti ti-users-group me-1"></i> {{ __('manager_dashboard.btn_partners') }}
            </a>
        </div>
    </div>
</div>
