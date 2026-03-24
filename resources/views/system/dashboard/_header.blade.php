<div class="welcome-banner mb-4 mt-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h4 class="mb-1">{{ $greeting }}, {{ explode(' ', auth()->user()->name)[0] }}! 👋</h4>
            <p>{{ __('dashboard.operational_panel', ['app' => $stats['entity_name']]) }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap" style="position:relative;z-index:1">
            <a href="{{ route('panel.patients.index') }}" class="btn btn-sm btn-banner">
                <i class="fa fa-users me-1"></i> {{ __('dashboard.btn_patients') }}
            </a>
            <a href="{{ route('panel.patients.index') }}" class="btn btn-sm btn-banner btn-banner-solid">
                <i class="fa fa-user-plus me-1"></i> {{ __('dashboard.btn_new_patient') }}
            </a>
        </div>
    </div>
</div>
