@extends('layouts.guest')

@section('auth_layout', 'register-illustration')
@section('auth_page_title', __('auth.verify_email.title'))
@section('auth_page_subtitle', __('Confirmação necessária'))
@section('auth_hero_title', __('auth.verify_email.title'))
@section('auth_hero_text', __('Valide seu e-mail para liberar todos os recursos da clínica.'))
@section('auth_hero_image', Vite::asset('resources/img/system/auth/email-verification-illustration-img.png'))
@section('auth_cover_image', Vite::asset('resources/img/system/auth/cover-imgs-1.png'))

@section('content')
    <p class="text-muted mb-4" style="font-size:.9rem;">
        {{ __('auth.verify_email.description') }}
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success mb-4">
            {{ __('auth.verify_email.link_sent') }}
        </div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="mb-3">
        @csrf
        <div class="d-grid">
            <button type="submit" class="btn btn-info waves-effect waves-light fw-semibold">
                {{ __('auth.verify_email.resend') }}
            </button>
        </div>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <button type="submit" class="btn btn-link text-muted">
            {{ __('auth.log_out') }}
        </button>
    </form>
@endsection
