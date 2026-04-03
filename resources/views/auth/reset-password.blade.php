@extends('layouts.guest')

@section('content')
<div class="card-body">
    <form method="POST" action="{{ route('password.store') }}" class="form-horizontal form-material">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        {{-- Header --}}
        <div class="text-center mb-4">
            <a href="{{ route('login') }}">
                <img src="{{ asset('system/images/preclinic/logo.svg') }}" alt="{{ config('app.name') }}" width="48" class="mb-2">
            </a>
            <h4 class="font-medium mb-0">{{ __('auth.reset_password.title') }}</h4>
        </div>

        {{-- Erros --}}
        @if ($errors->any())
            <div class="alert alert-danger mb-3" style="font-size:.85rem">{{ $errors->first() }}</div>
        @endif

        {{-- E-mail --}}
        <div class="form-group mb-3">
            <label class="form-label">{{ __('auth.reset_password.email') }} <span class="text-danger">*</span></label>
            <input type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email', $request->email) }}"
                   required autofocus autocomplete="username">
        </div>

        {{-- Nova senha --}}
        <div class="form-group mb-3">
            <label class="form-label">{{ __('auth.reset_password.password') }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="password" name="password" id="reset-password"
                       class="form-control @error('password') is-invalid @enderror"
                       required autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" data-toggle-password="#reset-password" tabindex="-1">
                    <i class="mdi mdi-eye-off"></i>
                </button>
            </div>
        </div>

        {{-- Confirmar nova senha --}}
        <div class="form-group mb-4">
            <label class="form-label">{{ __('auth.reset_password.confirm_password') }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="password" name="password_confirmation" id="reset-password-confirmation"
                       class="form-control @error('password_confirmation') is-invalid @enderror"
                       required autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" data-toggle-password="#reset-password-confirmation" tabindex="-1">
                    <i class="mdi mdi-eye-off"></i>
                </button>
            </div>
        </div>

        {{-- Botão --}}
        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-info btn-block waves-effect waves-light">
                {{ __('auth.reset_password.submit') }}
            </button>
        </div>

        <p class="text-center">
            <a href="{{ route('login') }}" class="text-muted" style="font-size:.85rem">
                &larr; {{ __('auth.back_to_login') }}
            </a>
        </p>

    </form>
</div>
@endsection

@section('javascript')
    @vite(['resources/js/auth/reset-password.js'])
@endsection

