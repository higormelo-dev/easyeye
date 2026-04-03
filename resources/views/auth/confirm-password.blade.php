@extends('layouts.guest')

@section('content')
<div class="card-body">
    <form method="POST" action="{{ route('password.confirm') }}" class="form-horizontal form-material">
        @csrf

        {{-- Header --}}
        <div class="text-center mb-4">
            <img src="{{ asset('system/images/preclinic/logo.svg') }}" alt="{{ config('app.name') }}" width="48" class="mb-2">
            <h4 class="font-medium mb-0">{{ __('Área Segura') }}</h4>
            <p class="text-muted mb-0" style="font-size:.85rem">{{ __('Por favor, confirme sua senha antes de continuar.') }}</p>
        </div>

        {{-- Erro --}}
        @error('password')
            <div class="alert alert-danger mb-3" style="font-size:.85rem">{{ $message }}</div>
        @enderror

        {{-- Senha --}}
        <div class="form-group mb-4">
            <label class="form-label">{{ __('Senha') }} <span class="text-danger">*</span></label>
            <input type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="current-password">
        </div>

        {{-- Botão confirmar --}}
        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-info btn-block waves-effect waves-light">
                {{ __('Confirmar') }}
            </button>
        </div>

    </form>
</div>
@endsection
