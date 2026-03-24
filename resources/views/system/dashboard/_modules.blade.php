<div class="row g-3 mb-4">

    {{-- Agenda --}}
    <div class="col-6 col-sm-4 col-md-2">
        <a href="{{ route('panel.schedules.index') }}" class="module-shortcut w-100">
            <span class="ms-icon" style="background:#e3f2fd;color:#1976d2;">
                <i class="fa fa-calendar"></i>
            </span>
            <span>{{ __('dashboard.module_schedule') }}</span>
        </a>
    </div>

    {{-- Eye Images (em breve) --}}
    <div class="col-6 col-sm-4 col-md-2">
        <div class="module-shortcut disabled w-100">
            <span class="badge-soon">{{ __('dashboard.coming_soon') }}</span>
            <span class="ms-icon" style="background:#f1f5f9;color:#94a3b8;">
                <i class="fa fa-eye"></i>
            </span>
            <span>{{ __('dashboard.module_eye_images') }}</span>
        </div>
    </div>

    @if($rule !== 'doctor')
    {{-- Guias TISS (em breve) --}}
    <div class="col-6 col-sm-4 col-md-2">
        <div class="module-shortcut disabled w-100">
            <span class="badge-soon">{{ __('dashboard.coming_soon') }}</span>
            <span class="ms-icon" style="background:#f1f5f9;color:#94a3b8;">
                <i class="fa fa-file-text-o"></i>
            </span>
            <span>{{ __('dashboard.module_tiss') }}</span>
        </div>
    </div>

    {{-- Financeiro (em breve) --}}
    <div class="col-6 col-sm-4 col-md-2">
        <div class="module-shortcut disabled w-100">
            <span class="badge-soon">{{ __('dashboard.coming_soon') }}</span>
            <span class="ms-icon" style="background:#f1f5f9;color:#94a3b8;">
                <i class="fa fa-dollar"></i>
            </span>
            <span>{{ __('dashboard.module_financial') }}</span>
        </div>
    </div>
    @endif

    {{-- Centro Cirúrgico (em breve) --}}
    <div class="col-6 col-sm-4 col-md-2">
        <div class="module-shortcut disabled w-100">
            <span class="badge-soon">{{ __('dashboard.coming_soon') }}</span>
            <span class="ms-icon" style="background:#f1f5f9;color:#94a3b8;">
                <i class="fa fa-medkit"></i>
            </span>
            <span>{{ __('dashboard.module_surgery') }}</span>
        </div>
    </div>

</div>
