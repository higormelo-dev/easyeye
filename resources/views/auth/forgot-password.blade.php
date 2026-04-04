@extends('layouts.guest')

@section('auth_layout', 'register-illustration')
@section('auth_page_title', __('auth.forgot_password.title'))
@section('auth_page_subtitle', __('auth.back_to_login'))
@section('auth_hero_title', __('auth.forgot_password.title'))
@section('auth_hero_text', __('Recupere o acesso da sua conta em poucos passos.'))
@section('auth_hero_image', asset('system/images/auth/forgot-illustration-img.png'))
@section('auth_cover_image', asset('system/images/auth/cover-imgs-1.png'))

@section('content')
    <form method="POST" action="{{ route('password.email') }}" class="form-horizontal form-material">
        @csrf

        <div class="alert alert-info mb-3">
            {{ __('auth.forgot_password.description') }}
        </div>

        @if (session('status'))
            <div class="alert alert-success mb-3">{{ session('status') }}</div>
        @endif

        @error('email')
            <div class="alert alert-danger mb-3">{{ $message }}</div>
        @enderror

        <div class="form-group mb-4">
            <label class="form-label">{{ __('actions.email') }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                    <i class="ti ti-mail text-muted"></i>
                </span>
                <input type="email"
                       name="email"
                       class="form-control border-start-0 @error('email') is-invalid @enderror"
                       value="{{ old('email') }}"
                       required
                       autofocus
                       autocomplete="username"
                       placeholder="{{ __('actions.email') }}">
            </div>
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-info waves-effect waves-light fw-semibold">
                {{ __('auth.forgot_password.send_link') }}
            </button>
        </div>

        <p class="text-center mb-0">
            <a href="{{ route('login') }}" class="hover-a">{{ __('auth.back_to_login') }}</a>
        </p>
    </form>
@endsection
