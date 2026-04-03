@extends('layouts.guest')

@section('content')
<div class="card-body" style="padding: 2rem 2.25rem;">

    {{-- Logo + Header --}}
    <div class="text-center mb-4">
        <a href="{{ route('register') }}">
            <img src="{{ asset('system/images/preclinic/logo.svg') }}" alt="{{ config('app.name') }}" width="52" class="mb-3">
        </a>
        <h4 style="font-weight:700;font-size:1.3rem;color:#1e293b;margin-bottom:.25rem;">
            {{ config('app.name') }}
        </h4>
        <p style="color:#64748b;font-size:.875rem;margin:0;">
            {{ __('auth.sign_in') }}
        </p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="form-horizontal form-material">
        @csrf

        {{-- Erro de autenticação --}}
        @error('email')
            <div class="alert alert-danger mb-3 py-2" style="font-size:.85rem;border-radius:.6rem;">
                <i class="fa fa-exclamation-circle me-1"></i> {{ $message }}
            </div>
        @enderror

        {{-- E-mail --}}
        <div class="mb-3">
            <label class="form-label" style="font-weight:500;font-size:.875rem;color:#374151;">
                {{ __('actions.email') }} <span class="text-danger">*</span>
            </label>
            <div class="input-group">
                <span class="input-group-text" style="border-right:none;">
                    <i class="fa fa-envelope-o" style="color:#64748b;"></i>
                </span>
                <input type="email" name="email"
                       class="form-control @error('email') is-invalid @enderror"
                       style="border-left:none;padding:.6rem .875rem;"
                       value="{{ old('email') }}"
                       required autofocus autocomplete="username"
                       placeholder="{{ __('actions.email') }}">
            </div>
        </div>

        {{-- Senha --}}
        <div class="mb-3">
            <label class="form-label" style="font-weight:500;font-size:.875rem;color:#374151;">
                {{ __('actions.password') }} <span class="text-danger">*</span>
            </label>
            <div class="input-group">
                <span class="input-group-text" style="border-right:none;">
                    <i class="fa fa-lock" style="color:#64748b;"></i>
                </span>
                <input type="password" name="password" id="password"
                       class="form-control @error('password') is-invalid @enderror"
                       style="border-left:none;border-right:none;padding:.6rem .875rem;"
                       required autocomplete="current-password"
                       placeholder="{{ __('actions.password') }}">
                <button type="button"
                        class="btn btn-outline-secondary"
                        style="border-left:none;border-color:#e2e8f0;"
                        data-toggle-password="#password"
                        tabindex="-1">
                    <i class="mdi mdi-eye-off"></i>
                </button>
            </div>
        </div>

        {{-- Lembrar-me + Esqueceu senha --}}
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="form-check">
                <input type="checkbox" name="remember" id="remember"
                       class="form-check-input" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label" for="remember"
                       style="font-size:.85rem;color:#475569;cursor:pointer;">
                    {{ __('auth.remember_me') }}
                </label>
            </div>
            <a href="{{ route('password.request') }}"
               style="font-size:.85rem;color:#1976d2;text-decoration:none;font-weight:500;">
                {{ __('auth.forget_password') }}
            </a>
        </div>

        {{-- Botão entrar --}}
        <div class="d-grid mb-3">
            <button type="submit"
                    class="btn btn-info btn-block waves-effect waves-light"
                    style="padding:.7rem;font-size:.9375rem;font-weight:600;border-radius:.625rem;letter-spacing:.01em;">
                <i class="fa fa-sign-in me-1"></i> {{ __('auth.sign_in') }}
            </button>
        </div>

        {{-- Link para cadastro --}}
        <p class="text-center mb-0" style="font-size:.85rem;color:#64748b;">
            {{ __('actions.dont_have_account') }}
            <a href="{{ route('register') }}"
               style="color:#1976d2;font-weight:600;text-decoration:none;">
                {{ __('actions.new_account') }}
            </a>
        </p>

    </form>
</div>
@endsection

@section('javascript')
    @vite(['resources/js/auth/login.js'])
@endsection
