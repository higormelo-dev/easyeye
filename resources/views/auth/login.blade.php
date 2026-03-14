@extends('layouts.guest')

@section('content')
<div class="card-body">
    <form method="POST" action="{{ route('login') }}" class="form-horizontal form-material">
        @csrf

        {{-- Header --}}
        <div class="text-center mb-4">
            <a href="{{ route('register') }}">
                <img src="{{ asset('system/images/logo-icon.png') }}" alt="{{ config('app.name') }}" width="48" class="mb-2">
            </a>
            <h4 class="font-medium mb-0">{{ config('app.name') }}</h4>
            <p class="text-muted mb-0" style="font-size:.85rem">{{ __('auth.sign_in') }}</p>
        </div>

        {{-- Erro de autenticação --}}
        @error('email')
            <div class="alert alert-danger mb-3" style="font-size:.85rem">{{ $message }}</div>
        @enderror

        {{-- E-mail --}}
        <div class="form-group mb-3">
            <label class="form-label">{{ __('actions.email') }} <span class="text-danger">*</span></label>
            <input type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}"
                   required autofocus autocomplete="username">
        </div>

        {{-- Senha --}}
        <div class="form-group mb-3">
            <label class="form-label">{{ __('actions.password') }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="password" name="password" id="password"
                       class="form-control @error('password') is-invalid @enderror"
                       required autocomplete="current-password">
                <button type="button" class="btn btn-outline-secondary" data-toggle-password="#password" tabindex="-1">
                    <i class="mdi mdi-eye-off"></i>
                </button>
            </div>
        </div>

        {{-- Lembrar-me + Esqueceu senha --}}
        <div class="form-group mb-4 d-flex align-items-center justify-content-between">
            <div class="form-check">
                <input type="checkbox" name="remember" id="remember"
                       class="form-check-input" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label" for="remember" style="font-size:.85rem">
                    {{ __('auth.remember_me') }}
                </label>
            </div>
            <a href="{{ route('password.request') }}" class="text-muted" style="font-size:.85rem">
                {{ __('auth.forget_password') }}
            </a>
        </div>

        {{-- Botão entrar --}}
        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-info btn-block waves-effect waves-light">
                {{ __('auth.sign_in') }}
            </button>
        </div>

        {{-- Link para cadastro --}}
        <p class="text-center text-muted" style="font-size:.85rem">
            {{ __('actions.dont_have_account') }}
            <a href="{{ route('register') }}">{{ __('actions.new_account') }}</a>
        </p>

    </form>
</div>

@endsection

@section('javascript')
    @vite(['resources/js/auth/login.js'])
@endsection





{{--<x-guest-layout>--}}
{{--    <!-- Session Status -->--}}
{{--    <x-auth-session-status class="mb-4" :status="session('status')" />--}}

{{--    <form method="POST" action="{{ route('login') }}">--}}
{{--        @csrf--}}

{{--        <!-- Email Address -->--}}
{{--        <div>--}}
{{--            <x-input-label for="email" :value="__('Email')" />--}}
{{--            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />--}}
{{--            <x-input-error :messages="$errors->get('email')" class="mt-2" />--}}
{{--        </div>--}}

{{--        <!-- Password -->--}}
{{--        <div class="mt-4">--}}
{{--            <x-input-label for="password" :value="__('Password')" />--}}

{{--            <x-text-input id="password" class="block mt-1 w-full"--}}
{{--                            type="password"--}}
{{--                            name="password"--}}
{{--                            required autocomplete="current-password" />--}}

{{--            <x-input-error :messages="$errors->get('password')" class="mt-2" />--}}
{{--        </div>--}}

{{--        <!-- Remember Me -->--}}
{{--        <div class="block mt-4">--}}
{{--            <label for="remember_me" class="inline-flex items-center">--}}
{{--                <input id="remember_me" type="checkbox" class="rounded dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800" name="remember">--}}
{{--                <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>--}}
{{--            </label>--}}
{{--        </div>--}}

{{--        <div class="flex items-center justify-end mt-4">--}}
{{--            @if (Route::has('password.request'))--}}
{{--                <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">--}}
{{--                    {{ __('Forgot your password?') }}--}}
{{--                </a>--}}
{{--            @endif--}}

{{--            <x-primary-button class="ms-3">--}}
{{--                {{ __('Log in') }}--}}
{{--            </x-primary-button>--}}
{{--        </div>--}}
{{--    </form>--}}
{{--</x-guest-layout>--}}
