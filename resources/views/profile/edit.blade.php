@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- Flash: perfil salvo --}}
@if(session('status') === 'profile-updated')
<div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
    <i class="ti ti-circle-check me-2"></i>{{ __('actions.profile.saved') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

{{-- Flash: senha atualizada --}}
@if(session('status') === 'password-updated')
<div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
    <i class="ti ti-circle-check me-2"></i>{{ __('actions.profile.password_updated') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<div class="card">
    <div class="card-body">

        {{-- ══════════════════════════════════════════════════════════ --}}
        {{-- SEÇÃO 1 — Foto + Informações Pessoais                     --}}
        {{-- ══════════════════════════════════════════════════════════ --}}
        <form method="POST"
              action="{{ route('panel.profile.update') }}"
              id="form-profile"
              enctype="multipart/form-data">
            @csrf
            @method('PATCH')

            <div class="row border-bottom mb-4 pb-3">

                {{-- Foto do perfil --}}
                <div class="col-12 mb-3"
                     x-data="{
                         preview: @js($userPhotoUrl),
                         handleFile(e) {
                             const f = e.target.files[0];
                             if (f) this.preview = URL.createObjectURL(f);
                         }
                     }">
                    <div class="row align-items-center">
                        <div class="col-lg-2">
                            <label class="form-label mb-0">
                                {{ __('actions.profile.photo') }}
                            </label>
                            <p class="text-muted small mb-0 mt-1">{{ __('actions.profile.photo_hint') }}</p>
                        </div>
                        <div class="col-lg-10">
                            <div class="profile-container">
                                <img :src="preview" alt="{{ $user->name }}">
                                <div class="overlay-btn">
                                    <a href="javascript:void(0);" class="text-white"
                                       @click="$refs.photoInput.click()">
                                        <i class="ti ti-photo fs-16"></i>
                                    </a>
                                </div>
                                <input type="file"
                                       x-ref="photoInput"
                                       name="photo"
                                       accept="image/jpeg,image/png,image/webp"
                                       @change="handleFile"
                                       style="display:none;">
                            </div>
                            @error('photo')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Nome --}}
                <div class="col-lg-6">
                    <div class="row align-items-center mb-3">
                        <div class="col-lg-4">
                            <label for="profile_name" class="form-label mb-0">
                                {{ __('actions.name') }}<span class="text-danger ms-1">*</span>
                            </label>
                        </div>
                        <div class="col-lg-8">
                            <input type="text"
                                   id="profile_name"
                                   name="name"
                                   class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $user->name) }}"
                                   required
                                   autofocus
                                   autocomplete="name">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- E-mail --}}
                <div class="col-lg-6">
                    <div class="row align-items-center mb-3">
                        <div class="col-lg-4">
                            <label for="profile_email" class="form-label mb-0">
                                {{ __('actions.email') }}<span class="text-danger ms-1">*</span>
                            </label>
                        </div>
                        <div class="col-lg-8">
                            <input type="email"
                                   id="profile_email"
                                   name="email"
                                   class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email', $user->email) }}"
                                   required
                                   autocomplete="username">
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror

                            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                                <div class="mt-2">
                                    <span class="badge bg-warning text-dark me-1">
                                        <i class="ti ti-alert-circle me-1"></i>{{ __('actions.profile.unverified_email') }}
                                    </span>
                                    <form id="form-verify"
                                          method="POST"
                                          action="{{ route('verification.send') }}"
                                          class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-link btn-sm p-0 align-baseline">
                                            {{ __('actions.profile.resend_verification') }}
                                        </button>
                                    </form>
                                    @if(session('status') === 'verification-link-sent')
                                        <div class="text-success small mt-1">
                                            <i class="ti ti-check me-1"></i>{{ __('actions.profile.verification_sent') }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </form>
        {{-- /form-profile --}}

        {{-- ══════════════════════════════════════════════════════════ --}}
        {{-- SEÇÃO 2 — Alterar Senha                                   --}}
        {{-- ══════════════════════════════════════════════════════════ --}}
        <form method="POST"
              action="{{ route('password.update') }}"
              id="form-password">
            @csrf
            @method('PUT')

            <div class="row border-bottom mb-4 pb-3">
                <div class="mb-3 col-12">
                    <h5 class="fw-bold mb-0">{{ __('actions.profile.change_password') }}</h5>
                </div>

                {{-- Senha Atual --}}
                <div class="col-lg-4">
                    <div class="row align-items-center mb-3">
                        <div class="col-lg-5">
                            <label class="form-label mb-0">
                                {{ __('actions.profile.current_password') }}
                            </label>
                        </div>
                        <div class="col-lg-7">
                            <div class="input-group" x-data="{ show: false }">
                                <input :type="show ? 'text' : 'password'"
                                       name="current_password"
                                       class="form-control @error('current_password', 'updatePassword') is-invalid @enderror"
                                       autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button" @click="show = !show" tabindex="-1">
                                    <i :class="show ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                </button>
                                @error('current_password', 'updatePassword')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Nova Senha --}}
                <div class="col-lg-4">
                    <div class="row align-items-center mb-3">
                        <div class="col-lg-5">
                            <label class="form-label mb-0">
                                {{ __('actions.profile.new_password') }}
                            </label>
                        </div>
                        <div class="col-lg-7">
                            <div class="input-group" x-data="{ show: false }">
                                <input :type="show ? 'text' : 'password'"
                                       name="password"
                                       class="form-control @error('password', 'updatePassword') is-invalid @enderror"
                                       autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" @click="show = !show" tabindex="-1">
                                    <i :class="show ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                </button>
                                @error('password', 'updatePassword')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Confirmar Senha --}}
                <div class="col-lg-4">
                    <div class="row align-items-center mb-3">
                        <div class="col-lg-5">
                            <label class="form-label mb-0">
                                {{ __('actions.profile.confirm_password') }}
                            </label>
                        </div>
                        <div class="col-lg-7">
                            <div class="input-group" x-data="{ show: false }">
                                <input :type="show ? 'text' : 'password'"
                                       name="password_confirmation"
                                       class="form-control"
                                       autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" @click="show = !show" tabindex="-1">
                                    <i :class="show ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Botão de senha separado --}}
                <div class="col-12">
                    <div class="d-flex justify-content-end">
                        <button type="submit" form="form-password" class="btn btn-outline-primary btn-sm">
                            <i class="ti ti-lock me-1"></i>{{ __('actions.profile.update_password') }}
                        </button>
                    </div>
                </div>

            </div>
        </form>
        {{-- /form-password --}}

        {{-- ══════════════════════════════════════════════════════════ --}}
        {{-- Ações principais (Cancelar / Salvar perfil)               --}}
        {{-- ══════════════════════════════════════════════════════════ --}}
        <div class="d-flex align-items-center justify-content-end gap-2">
            <a href="{{ route('panel.dashboard') }}" class="btn btn-light">
                {{ __('actions.cancel') }}
            </a>
            <button type="submit" form="form-profile" class="btn btn-primary">
                <i class="ti ti-device-floppy me-1"></i>{{ __('actions.save') }}
            </button>
        </div>

    </div>{{-- /card-body --}}
</div>{{-- /card --}}

@endsection
