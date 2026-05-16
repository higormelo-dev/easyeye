@extends('layouts.guest')

@section('auth_layout', 'login-illustration')
@section('auth_hide_locale', '1')
@section('auth_hero_title', __('auth.sign_in'))
@section('auth_hero_image', asset('system/images/icons/log-illustration-img-01.png'))

@push('styles')
<link rel="stylesheet" href="{{ asset('css/guest.css') }}">
@endpush

{{-- ═══ Painel esquerdo — branding + features + social proof ═══ --}}
@section('auth_left_panel')
<div class="ee-login-panel-blob"></div>
<div class="ee-login-panel">

    <img src="{{ asset('system/images/logo-white.svg') }}"
         alt="{{ config('app.name') }}"
         class="ee-login-panel-logo">

    <img src="{{ asset('system/images/icons/log-illustration-img-01.png') }}"
         alt="{{ config('app.name') }}"
         class="ee-login-panel-img">

    <div class="ee-login-features">
        <div class="ee-login-feature">
            <div class="ee-login-feature-ico"><i class="ti ti-calendar"></i></div>
            <span>{{ __('auth.panel.feature_schedule') }}</span>
        </div>
        <div class="ee-login-feature">
            <div class="ee-login-feature-ico"><i class="ti ti-file-medical"></i></div>
            <span>{{ __('auth.panel.feature_record') }}</span>
        </div>
        <div class="ee-login-feature">
            <div class="ee-login-feature-ico"><i class="ti ti-receipt"></i></div>
            <span>{{ __('auth.panel.feature_tiss') }}</span>
        </div>
        <div class="ee-login-feature">
            <div class="ee-login-feature-ico"><i class="ti ti-shield-check"></i></div>
            <span>{{ __('auth.panel.feature_compliance') }}</span>
        </div>
    </div>

    <div class="ee-login-quote">
        <p>{{ __('auth.panel.quote_text') }}</p>
        <cite>{{ __('auth.panel.quote_author') }}</cite>
    </div>

</div>
@endsection

{{-- ═══ Formulário de login ═══ --}}
@section('content')
    <div class="text-center mb-4">
        <a href="{{ route('login') }}" class="d-inline-block">
            <img src="{{ asset('system/images/icons/logo.svg') }}"
                 class="img-fluid ee-login-brand"
                 alt="{{ config('app.name') }}">
        </a>
    </div>

    <div class="card ee-login-card">
        <div class="card-body ee-login-card-body ee-login-form">
            <div class="text-center mb-4">
                <h2 class="ee-login-title">{{ __('auth.sign_in') }}</h2>
                <p class="ee-login-subtitle">{{ __('auth.login_subtitle') }}</p>
            </div>

            <form method="POST" action="{{ route('login') }}" class="ee-login-form">
                @csrf

                @error('email')
                    <div class="alert alert-danger mb-3 py-2">
                        <i class="ti ti-alert-circle me-1"></i> {{ $message }}
                    </div>
                @enderror

                <div class="mb-3">
                    <label class="form-label">{{ __('actions.email') }}</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="ti ti-mail"></i>
                        </span>
                        <input type="email"
                               name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}"
                               required
                               autofocus
                               autocomplete="username"
                               placeholder="{{ __('actions.email') }}">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ __('actions.password') }}</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="ti ti-lock"></i>
                        </span>
                        <input type="password"
                               name="password"
                               id="password"
                               class="form-control border-end-0 @error('password') is-invalid @enderror"
                               required
                               autocomplete="current-password"
                               placeholder="••••••••••••">
                        <button type="button"
                                class="btn btn-light"
                                data-toggle-password="#password"
                                tabindex="-1"
                                aria-label="Toggle password visibility">
                            <i class="ti ti-eye-off"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="form-check">
                        <input type="checkbox"
                               name="remember"
                               id="remember"
                               class="form-check-input"
                               {{ old('remember') ? 'checked' : '' }}>
                        <label class="form-check-label text-dark" for="remember">
                            {{ __('auth.remember_me') }}
                        </label>
                    </div>
                    <a href="{{ route('password.request') }}" class="ee-login-forgot">
                        {{ __('auth.forget_password') }}
                    </a>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary ee-login-submit waves-effect waves-light">
                        {{ __('auth.sign_in') }}
                    </button>
                </div>

                <p class="ee-login-register text-center mb-0">
                    {{ __('auth.no_account_yet') }}
                    <a href="{{ route('register') }}">{{ __('auth.sign_up') }}</a>
                </p>

                <div class="ee-login-trust">
                    <div class="ee-login-trust-item">
                        <i class="ti ti-lock"></i>
                        <span>SSL</span>
                    </div>
                    <div class="ee-login-trust-item">
                        <i class="ti ti-shield-check"></i>
                        <span>LGPD</span>
                    </div>
                    <div class="ee-login-trust-item">
                        <i class="ti ti-certificate"></i>
                        <span>CFM</span>
                    </div>
                    <div class="ee-login-trust-item">
                        <i class="ti ti-star"></i>
                        <span>97% NPS</span>
                    </div>
                </div>
            </form>

            @php $currentLocale = \App\Http\Middleware\SetLocale::getSupportedLocales()[app()->getLocale()] ?? null; @endphp
            <div class="border-top pt-3 mt-4 d-flex align-items-center justify-content-center gap-1">
                <div class="dropdown">
                    <button type="button"
                            class="btn btn-link btn-sm text-muted text-decoration-none dropdown-toggle"
                            data-bs-toggle="dropdown" aria-expanded="false"
                            style="font-size:.8rem;">
                        @if($currentLocale)
                            {{ $currentLocale['flag'] }} {{ $currentLocale['native'] }}
                        @else
                            {{ app()->getLocale() }}
                        @endif
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="min-width:0">
                        @foreach(\App\Http\Middleware\SetLocale::getSupportedLocales() as $code => $locale)
                            <li>
                                <a href="{{ route('locale.switch', $code) }}"
                                   class="dropdown-item {{ app()->getLocale() === $code ? 'active fw-semibold' : '' }}"
                                   style="font-size:.85rem;">
                                    {{ $locale['flag'] }} {{ $locale['native'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <button type="button"
                        class="btn btn-link btn-sm text-muted p-1 ee-guest-dark-toggle"
                        title="Trocar"
                        style="font-size:1rem; line-height:1;">
                    <i class="ti ti-moon ee-dark-icon-moon"></i>
                    <i class="ti ti-sun ee-dark-icon-sun"></i>
                </button>
            </div>
        </div>
    </div>

    <p class="ee-login-copy text-center">
        Copyright &copy; {{ \Carbon\Carbon::now()->year }} - {{ config('app.name') }}.
    </p>
@endsection

@section('javascript')
    @vite(['resources/js/auth/login.js'])
@endsection
