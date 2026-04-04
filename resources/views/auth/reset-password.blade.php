@extends('layouts.guest')

@section('auth_layout', 'register-illustration')
@section('auth_page_title', __('auth.reset_password.title'))
@section('auth_page_subtitle', __('auth.back_to_login'))
@section('auth_hero_title', __('auth.reset_password.title'))
@section('auth_hero_text', __('Defina sua nova senha para continuar com segurança.'))
@section('auth_hero_image', asset('system/images/auth/reset-illustration-img.png'))
@section('auth_cover_image', asset('system/images/auth/cover-imgs-2.png'))

@section('content')
    <form method="POST" action="{{ route('password.store') }}" class="form-horizontal form-material">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        @if ($errors->any())
            <div class="alert alert-danger mb-3">{{ $errors->first() }}</div>
        @endif

        <div class="form-group mb-3">
            <label class="form-label">{{ __('auth.reset_password.email') }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                    <i class="ti ti-mail text-muted"></i>
                </span>
                <input type="email"
                       name="email"
                       class="form-control border-start-0 @error('email') is-invalid @enderror"
                       value="{{ old('email', $request->email) }}"
                       required
                       autofocus
                       autocomplete="username"
                       placeholder="{{ __('auth.reset_password.email') }}">
            </div>
        </div>

        <div class="form-group mb-3">
            <label class="form-label">{{ __('auth.reset_password.password') }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="password"
                       name="password"
                       id="reset-password"
                       class="form-control @error('password') is-invalid @enderror"
                       required
                       autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" data-toggle-password="#reset-password" tabindex="-1">
                    <i class="ti ti-eye-off"></i>
                </button>
            </div>
        </div>

        <div class="form-group mb-4">
            <label class="form-label">{{ __('auth.reset_password.confirm_password') }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="password"
                       name="password_confirmation"
                       id="reset-password-confirmation"
                       class="form-control @error('password_confirmation') is-invalid @enderror"
                       required
                       autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" data-toggle-password="#reset-password-confirmation" tabindex="-1">
                    <i class="ti ti-eye-off"></i>
                </button>
            </div>
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-info waves-effect waves-light fw-semibold">
                {{ __('auth.reset_password.submit') }}
            </button>
        </div>

        <p class="text-center mb-0">
            <a href="{{ route('login') }}" class="hover-a">{{ __('auth.back_to_login') }}</a>
        </p>
    </form>
@endsection

@section('javascript')
    @vite(['resources/js/auth/reset-password.js'])
@endsection
