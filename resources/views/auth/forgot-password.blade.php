@extends('layouts.guest')

@section('auth_layout', 'register-illustration')
@section('auth_hide_header', '1')
@section('auth_hero_title', __('auth.forgot_password.title'))
@section('auth_hero_image', asset('system/images/auth/forgot-illustration-img.png'))

@push('styles')
<link rel="stylesheet" href="{{ asset('css/forgot-password.css') }}">
@endpush

{{-- ═══ Painel esquerdo — branding + recovery steps ═══ --}}
@section('auth_left_panel')
<div class="ee-fp-panel-blob"></div>
<div class="ee-fp-panel">

    <img src="{{ asset('system/images/logo-white.svg') }}"
         alt="{{ config('app.name') }}"
         class="ee-fp-panel-logo">

    <div class="ee-fp-panel-icon">
        <i class="ti ti-lock-open"></i>
    </div>

    <p class="ee-fp-panel-title">{{ __('auth.panel_fp.title') }}</p>
    <p class="ee-fp-panel-sub">{{ __('auth.panel_fp.subtitle') }}</p>

    <div class="ee-fp-steps">
        <div class="ee-fp-step">
            <div class="ee-fp-step-num">1</div>
            <span>{{ __('auth.panel_fp.step_1') }}</span>
        </div>
        <div class="ee-fp-step">
            <div class="ee-fp-step-num">2</div>
            <span>{{ __('auth.panel_fp.step_2') }}</span>
        </div>
        <div class="ee-fp-step">
            <div class="ee-fp-step-num">3</div>
            <span>{{ __('auth.panel_fp.step_3') }}</span>
        </div>
    </div>

    <div class="ee-fp-security-badge">
        <i class="ti ti-shield-check"></i>
        {{ __('auth.panel_fp.security_note') }}
    </div>

</div>
@endsection

{{-- ═══ Formulário de recuperação ═══ --}}
@section('content')

    {{-- Cabeçalho com ícone --}}
    <div class="ee-fp-form-header">
        <div class="ee-fp-form-icon">
            <i class="ti ti-lock-open"></i>
        </div>
        <h2 class="ee-fp-form-title">{{ __('auth.forgot_password.title') }}</h2>
        <p class="ee-fp-form-subtitle">{{ __('auth.forgot_password.description') }}</p>
    </div>

    {{-- Status: link enviado com sucesso --}}
    @if (session('status'))
        <div class="ee-fp-success">
            <i class="ti ti-circle-check ee-fp-success-icon"></i>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    {{-- Erro de validação --}}
    @error('email')
        <div class="ee-fp-error">
            <i class="ti ti-alert-circle ee-fp-error-icon"></i>
            <p>{{ $message }}</p>
        </div>
    @enderror

    <form method="POST" action="{{ route('password.email') }}" class="ee-auth-form">
        @csrf

        <div class="mb-4">
            <label class="form-label">{{ __('actions.email') }} <span style="color:#ef4444;">*</span></label>
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

        <div class="mb-4">
            <button type="submit" class="ee-fp-submit waves-effect waves-light">
                <i class="ti ti-send"></i>
                {{ __('auth.forgot_password.send_link') }}
            </button>
        </div>

        <div class="text-center">
            <a href="{{ route('login') }}" class="ee-fp-back">
                <i class="ti ti-arrow-left"></i>
                {{ __('auth.back_to_login') }}
            </a>
        </div>

        <div class="ee-fp-trust">
            <div class="ee-fp-trust-item">
                <i class="ti ti-lock"></i>
                <span>SSL</span>
            </div>
            <div class="ee-fp-trust-item">
                <i class="ti ti-shield-check"></i>
                <span>LGPD</span>
            </div>
            <div class="ee-fp-trust-item">
                <i class="ti ti-clock"></i>
                <span>{{ __('auth.panel_fp.link_expiry') }}</span>
            </div>
        </div>
    </form>

@endsection
