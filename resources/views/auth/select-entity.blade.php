@extends('layouts.guest')

@section('auth_layout', 'register-illustration')
@section('auth_page_title', __('auth.select_entity'))
@section('auth_page_subtitle', __('Selecione a clínica para continuar'))
@section('auth_hero_title', __('Acesso Multiempresa'))
@section('auth_hero_text', __('Escolha a empresa correta para manter os dados segregados e seguros.'))
@section('auth_hero_image', asset('system/images/auth/twostep-verification-illustration-img.png'))
@section('auth_cover_image', asset('system/images/auth/cover-imgs-2.png'))

@section('content')
    <form method="POST" action="{{ route('selectentity.store') }}" class="form-horizontal form-material">
        @csrf

        @error('entity_user_id')
            <div class="alert alert-danger mb-4">{{ $message }}</div>
        @enderror

        <div class="form-group mb-4">
            <label class="form-label">{{ __('auth.select_entity') }} <span class="text-danger">*</span></label>
            <select name="entity_user_id" class="form-select @error('entity_user_id') is-invalid @enderror">
                <option value="">{{ __('actions.select') }}</option>
                @foreach ($entities as $id => $name)
                    <option value="{{ $id }}" {{ old('entity_user_id') == $id ? 'selected' : '' }}>
                        {{ $name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-info waves-effect waves-light fw-semibold">
                {{ __('actions.select') }}
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
