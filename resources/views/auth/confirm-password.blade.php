@extends('layouts.guest')

@section('auth_layout', 'register-illustration')
@section('auth_page_title', __('Área Segura'))
@section('auth_page_subtitle', __('Confirme sua identidade'))
@section('auth_hero_title', __('Confirmação de Segurança'))
@section('auth_hero_text', __('Por segurança, confirme sua senha antes de continuar.'))
@section('auth_hero_image', asset('system/images/auth/twostep-verification-illustration-img.png'))
@section('auth_cover_image', asset('system/images/auth/cover-imgs-2.png'))

@section('content')
    <form method="POST" action="{{ route('password.confirm') }}" class="form-horizontal form-material">
        @csrf

        <p class="text-muted mb-3" style="font-size:.9rem;">
            {{ __('Por favor, confirme sua senha antes de continuar.') }}
        </p>

        @error('password')
            <div class="alert alert-danger mb-3">{{ $message }}</div>
        @enderror

        <div class="form-group mb-4">
            <label class="form-label">{{ __('Senha') }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                    <i class="ti ti-lock text-muted"></i>
                </span>
                <input type="password"
                       name="password"
                       id="confirm-password"
                       class="form-control border-start-0 border-end-0 @error('password') is-invalid @enderror"
                       required
                       autocomplete="current-password">
                <button type="button" class="btn btn-outline-secondary border-start-0" data-toggle-password="#confirm-password" tabindex="-1">
                    <i class="ti ti-eye-off"></i>
                </button>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-info waves-effect waves-light fw-semibold">
                {{ __('Confirmar') }}
            </button>
        </div>
    </form>
@endsection

@section('javascript')
    @vite(['resources/js/auth/confirm-password.js'])
@endsection
