@extends('layouts.guest')

@section('content')
<div class="card-body text-center">

    {{-- Header --}}
    <div class="mb-4">
        <img src="{{ asset('system/images/logo-icon.png') }}" alt="{{ config('app.name') }}" width="48" class="mb-2">
        <h4 class="font-medium mb-0">{{ __('auth.verify_email.title') }}</h4>
    </div>

    <p class="text-muted mb-4" style="font-size:.85rem">
        {{ __('auth.verify_email.description') }}
    </p>

    {{-- Confirmação de reenvio --}}
    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success mb-4" style="font-size:.85rem">
            {{ __('auth.verify_email.link_sent') }}
        </div>
    @endif

    {{-- Botão reenviar --}}
    <form method="POST" action="{{ route('verification.send') }}" class="mb-3">
        @csrf
        <div class="d-grid">
            <button type="submit" class="btn btn-info waves-effect waves-light">
                {{ __('auth.verify_email.resend') }}
            </button>
        </div>
    </form>

    {{-- Sair --}}
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-link text-muted" style="font-size:.85rem">
            {{ __('auth.log_out') }}
        </button>
    </form>

</div>
@endsection
