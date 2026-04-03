@extends('layouts.guest')

@section('content')
<div class="card-body">

    <form method="POST" action="{{ route('selectentity.store') }}" class="form-horizontal form-material">
        @csrf

        {{-- Header --}}
        <div class="text-center mb-4">
            <img src="{{ asset('system/images/preclinic/logo.svg') }}" alt="{{ config('app.name') }}" width="48" class="mb-2">
            <h4 class="font-medium mb-0">{{ config('app.name') }}</h4>
            <p class="text-muted mb-0" style="font-size:.85rem">{{ __('auth.select_entity') }}</p>
        </div>

        {{-- Erro --}}
        @error('entity_user_id')
            <div class="alert alert-danger mb-4" style="font-size:.85rem">{{ $message }}</div>
        @enderror

        {{-- Lista de empresas --}}
        <div class="form-group mb-4">
            <select name="entity_user_id" class="form-select">
                <option value="">{{ __('actions.select') }}</option>
                @foreach ($entities as $id => $name)
                    <option value="{{ $id }}" {{ old('entity_user_id') == $id ? 'selected' : '' }}>
                        {{ $name }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Botão --}}
        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-info btn-block waves-effect waves-light">
                {{ __('actions.select') }}
            </button>
        </div>

    </form>

    {{-- Sair --}}
    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <button type="submit" class="btn btn-link text-muted" style="font-size:.85rem">
            {{ __('auth.log_out') }}
        </button>
    </form>

</div>
@endsection
