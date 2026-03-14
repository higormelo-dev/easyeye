@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div x-data="crudForm({
        storeUrl:  @js($storeUrl),
        updateUrl: @js(route('panel.doctors.index')),
        fields: {
            name: '', nickname: '', national_registry: '', birth_date: '', gender: '', marital_status: '',
            email: '', mother_name: '', father_name: '',
            state_registry: '', state_registry_agency: '', state_registry_initial: '', state_registry_date: '',
            telephone: '', cellphone: '', whatsapp: false,
            zipcode: '', address: '', number: '', complement: '', district: '', city: '', state: '',
            record: '', record_specialty: '', color: '#3699ff', observation: '', partner: false,
            active: true,
            password: '', password_confirmation: ''
        },
        onSuccess: () => window.dispatchEvent(new CustomEvent('doctor-saved'))
    })"
     x-init="$nextTick(() => document.getElementById('doctorModal').addEventListener('hidden.bs.modal', () => reset()))"
     @open-create-doctor.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('doctorModal')).show()"
     @edit-doctor.window="loadAndEdit(
         @js($baseUrl) + '/' + $event.detail.id + '/edit-data',
         @js($baseUrl) + '/' + $event.detail.id,
         'doctorModal'
     )">

    @include('system.doctors._form-modal')

    <div x-data="doctorViewToggle(@js(route('panel.doctors.cards')), @js(asset('system/images/team.png')))"
         x-init="init()"
         @doctor-saved.window="reloadCurrentView()">

        <div class="row mb-3 align-items-center">
            <div class="col-12 col-md-auto">
                <button type="button" class="btn btn-info btn-sm" @click="$dispatch('open-create-doctor')">
                    <i class="fa fa-plus"></i> {{ __('actions.new') }}
                </button>
            </div>
            <div class="col-12 col-md d-flex align-items-center gap-2 mt-2 mt-md-0 justify-content-md-end">
                <div class="input-group input-group-sm flex-grow-1" style="max-width: 320px;">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="text" class="form-control" placeholder="{{ __('actions.search') }}..."
                        x-model="search" x-on:input.debounce.400ms="performSearch()">
                    <button class="btn btn-outline-secondary" type="button" x-show="search" x-on:click="clearSearch()">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <div class="btn-group btn-group-sm flex-shrink-0" role="group">
                    <button type="button" class="btn"
                        :class="view === 'table' ? 'btn-primary' : 'btn-outline-secondary'"
                        x-on:click="setView('table')" title="{{ __('actions.table_view') }}">
                        <i class="fa fa-list"></i>
                    </button>
                    <button type="button" class="btn"
                        :class="view === 'cards' ? 'btn-primary' : 'btn-outline-secondary'"
                        x-on:click="setView('cards')" title="{{ __('actions.card_view') }}">
                        <i class="fa fa-th-large"></i>
                    </button>
                </div>
            </div>
        </div>

        <div x-show="view === 'table'">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <h5 class="card-header">{{ $meta['action'] }}</h5>
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    {{ $dataTable->table(['class' => 'table table-striped']) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="view === 'cards'" x-cloak>
            <div x-show="loading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">{{ __('actions.loading') }}...</span>
                </div>
            </div>
            <div x-show="!loading">
                <div class="row" x-show="doctors.length > 0">
                    <template x-for="doctor in doctors" :key="doctor.id">
                        <div class="col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6 mb-3">
                            <div class="card card-body h-100">
                                <div class="row align-items-center">
                                    <div class="col-md-4 col-lg-3 text-center">
                                        <img :src="doctor.photo_url" :alt="doctor.full_name"
                                             class="img-fluid rounded-circle"
                                             x-on:error="$el.src = fallbackPhoto">
                                    </div>
                                    <div class="col-md-8 col-lg-9">
                                        <h5 class="mb-1" x-text="doctor.full_name"></h5>
                                        <div class="mb-2">
                                            <span :class="doctor.active ? 'badge bg-success' : 'badge bg-secondary'"
                                                  x-text="doctor.active ? '{{ __('actions.active') }}' : '{{ __('actions.inactive') }}'">
                                            </span>
                                        </div>
                                        <address class="small text-muted mb-2">
                                            <strong>{{ __('actions.code') }}:</strong>
                                            <span x-text="doctor.code"></span><br>
                                            <strong>{{ __('actions.record_advicen') }}:</strong>
                                            <span x-text="doctor.record ?? '{{ __('actions.not_informed') }}'"></span><br>
                                            <strong>{{ __('actions.email') }}:</strong>
                                            <span x-text="doctor.email ?? '{{ __('actions.not_informed') }}'"></span>
                                        </address>
                                        <hr class="my-2">
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-warning btn-edit" :data-id="doctor.id"
                                                    data-bs-toggle="tooltip" title="{{ __('actions.edit') }}">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-info btn-show" :data-id="doctor.id"
                                                    data-bs-toggle="tooltip" title="{{ __('actions.view') }}">
                                                <i class="fa fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-secondary btn-active"
                                                    :data-id="doctor.id" :data-situation="doctor.active ? 0 : 1"
                                                    data-bs-toggle="tooltip"
                                                    :title="doctor.active ? '{{ __('actions.disable') }}' : '{{ __('actions.enable') }}'">
                                                <i :class="doctor.active ? 'fas fa-lock-open' : 'fas fa-unlock'"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger btn-trash" :data-id="doctor.id"
                                                    data-bs-toggle="tooltip" title="{{ __('actions.delete') }}">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <div x-show="doctors.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="fa fa-user-md fa-3x mb-3"></i>
                    <p>{{ __('actions.no_records') }}</p>
                </div>
                <nav x-show="meta.last_page > 1" class="d-flex justify-content-center mt-3">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item" :class="{ 'disabled': meta.current_page === 1 }">
                            <button class="page-link" x-on:click="fetchCards(meta.current_page - 1)">
                                <i class="fa fa-chevron-left"></i>
                            </button>
                        </li>
                        <template x-for="page in meta.last_page" :key="page">
                            <li class="page-item" :class="{ 'active': page === meta.current_page }">
                                <button class="page-link" x-text="page" x-on:click="fetchCards(page)"></button>
                            </li>
                        </template>
                        <li class="page-item" :class="{ 'disabled': meta.current_page === meta.last_page }">
                            <button class="page-link" x-on:click="fetchCards(meta.current_page + 1)">
                                <i class="fa fa-chevron-right"></i>
                            </button>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>

    </div>

</div>

@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    @vite(['resources/js/system/doctors.js'])
@endsection
