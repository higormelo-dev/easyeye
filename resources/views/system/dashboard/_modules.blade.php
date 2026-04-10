<div class="row g-3 mb-4">

    {{-- Agenda --}}
    <div class="col-6 col-sm-4 col-md-2">
        <a href="{{ route('panel.schedules.index') }}" class="module-shortcut w-100">
            <span class="ms-icon module-icon--schedule">
                <i class="fa fa-calendar"></i>
            </span>
            <span>{{ __('dashboard.module_schedule') }}</span>
        </a>
    </div>

    {{-- Eye Images --}}
    <div class="col-6 col-sm-4 col-md-2">
        <a href="{{ route('panel.eye-images.index') }}" class="module-shortcut w-100">
            <span class="ms-icon module-icon">
                <i class="fa fa-eye"></i>
            </span>
            <span>{{ __('dashboard.module_eye_images') }}</span>
        </a>
    </div>

    @if(in_array($rule, ['admin', 'financial'], true))
    {{-- Guias TISS --}}
    <div class="col-6 col-sm-4 col-md-2">
        <a href="{{ route('panel.financial.billing.index') }}" class="module-shortcut w-100">
            <span class="ms-icon module-icon--tiss">
                <i class="fa fa-file-text-o"></i>
            </span>
            <span>{{ __('dashboard.module_tiss') }}</span>
        </a>
    </div>

    {{-- Financeiro --}}
    <div class="col-6 col-sm-4 col-md-2">
        <a href="{{ route('panel.financial.cash-flow.index') }}" class="module-shortcut w-100">
            <span class="ms-icon module-icon--financial">
                <i class="fa fa-dollar"></i>
            </span>
            <span>{{ __('dashboard.module_financial') }}</span>
        </a>
    </div>
    @endif

    {{-- Centro Cirúrgico (em breve) --}}
    <div class="col-6 col-sm-4 col-md-2">
        <div class="module-shortcut disabled w-100">
            <span class="badge-soon">{{ __('dashboard.coming_soon') }}</span>
            <span class="ms-icon module-icon--soon">
                <i class="fa fa-medkit"></i>
            </span>
            <span>{{ __('dashboard.module_surgery') }}</span>
        </div>
    </div>

</div>
