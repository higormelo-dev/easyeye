@extends('layouts.guest')

@section('content')
<div class="card-body">
    <form method="POST" action="{{ route('password.email') }}" class="form-horizontal form-material">
        @csrf

        {{-- Header --}}
        <div class="text-center mb-4">
            <a href="{{ route('login') }}">
                <img src="{{ asset('system/images/logo-icon.png') }}" alt="{{ config('app.name') }}" width="48" class="mb-2">
            </a>
            <h4 class="font-medium mb-0">{{ __('auth.forgot_password.title') }}</h4>
        </div>

        <div class="alert alert-info mb-3" style="font-size:.85rem">
            {{ __('auth.forgot_password.description') }}
        </div>

        {{-- Status de sucesso --}}
        @if (session('status'))
            <div class="alert alert-success mb-3" style="font-size:.85rem">{{ session('status') }}</div>
        @endif

        {{-- Erro --}}
        @error('email')
            <div class="alert alert-danger mb-3" style="font-size:.85rem">{{ $message }}</div>
        @enderror

        {{-- E-mail --}}
        <div class="form-group mb-4">
            <label class="form-label">{{ __('actions.email') }} <span class="text-danger">*</span></label>
            <input type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}"
                   required autofocus autocomplete="username">
        </div>

        {{-- Botão enviar --}}
        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-info btn-block waves-effect waves-light">
                {{ __('auth.forgot_password.send_link') }}
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
