@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- crudForm: gerencia o modal de criação/edição de paciente --}}
<div x-data="crudForm({
        storeUrl:  @js(route('panel.patients.store')),
        updateUrl: @js(route('panel.patients.index')),
        fields: {
            covenant_id: '', card_number: '', skin_id: '', iris_id: '', active: true,
            name: '', nickname: '', national_registry: '', birth_date: '',
            gender: '', marital_status: '', email: '',
            mother_name: '', father_name: '',
            state_registry: '', state_registry_agency: '',
            state_registry_initial: '', state_registry_date: '',
            telephone: '', cellphone: '', whatsapp: false,
            zipcode: '', address: '', number: '', complement: '',
            district: '', city: '', state: '', country: ''
        },
        onSuccess: () => window.dispatchEvent(new CustomEvent('patient-saved'))
    })"
     x-init="$nextTick(() => document.getElementById('patientModal').addEventListener('hidden.bs.modal', () => reset()))"
     @open-create-patient.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('patientModal')).show()"
     @edit-patient.window="loadAndEdit(
         @js(url('panel/patients')) + '/' + $event.detail.id + '/edit-data',
         @js(url('panel/patients')) + '/' + $event.detail.id,
         'patientModal'
     )">

    @include('system.patients._form-modal')

    {{-- patientViewToggle --}}
    <div x-data="patientViewToggle(@js(route('panel.patients.cards')), @js(asset('system/images/team.png')))"
         x-init="init()"
         @patient-saved.window="reloadCurrentView()">

        {{-- Toolbar --}}
        <div class="row mb-3 align-items-center">
            <div class="col-12 col-md-auto">
                <button type="button"
                        class="btn btn-info btn-sm"
                        @click="$dispatch('open-create-patient')">
                    <i class="fa fa-plus"></i> {{ __('actions.new') }}
                </button>
            </div>
            <div class="col-12 col-md d-flex align-items-center gap-2 mt-2 mt-md-0 justify-content-md-end">
                {{-- Busca unificada --}}
                <div class="input-group input-group-sm flex-grow-1" style="max-width: 320px;">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="text"
                        class="form-control"
                        placeholder="{{ __('actions.search') }}..."
                        x-model="search"
                        x-on:input.debounce.400ms="performSearch()">
                    <button class="btn btn-outline-secondary" type="button"
                        x-show="search"
                        x-on:click="clearSearch()">
                        <i class="fa fa-times"></i>
                    </button>
                </div>

                {{-- Toggle tabela / cards --}}
                <div class="btn-group btn-group-sm flex-shrink-0" role="group" aria-label="{{ __('actions.view_mode') }}">
                    <button type="button" class="btn"
                        :class="view === 'table' ? 'btn-primary' : 'btn-outline-secondary'"
                        x-on:click="setView('table')"
                        title="{{ __('actions.table_view') }}">
                        <i class="fa fa-list"></i>
                    </button>
                    <button type="button" class="btn"
                        :class="view === 'cards' ? 'btn-primary' : 'btn-outline-secondary'"
                        x-on:click="setView('cards')"
                        title="{{ __('actions.card_view') }}">
                        <i class="fa fa-th-large"></i>
                    </button>
                </div>
            </div>
        </div>

        {{-- DataTable View --}}
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

        {{-- Cards View --}}
        <div x-show="view === 'cards'" x-cloak>

            {{-- Loading --}}
            <div x-show="loading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">{{ __('actions.loading') }}...</span>
                </div>
            </div>

            {{-- Cards Grid --}}
            <div x-show="!loading">
                <div class="row" x-show="patients.length > 0">
                    <template x-for="patient in patients" :key="patient.id">
                        <div class="col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6 mb-3">
                            <div class="card card-body h-100">
                                <div class="row align-items-center">
                                    <div class="col-md-4 col-lg-3 text-center">
                                        <img :src="patient.photo_url"
                                             :alt="patient.full_name"
                                             class="img-fluid rounded-circle"
                                             x-on:error="$el.src = fallbackPhoto">
                                    </div>
                                    <div class="col-md-8 col-lg-9">
                                        <h5 class="mb-1" x-text="patient.full_name"></h5>
                                        <div class="mb-2">
                                            <span :class="patient.active ? 'badge bg-success' : 'badge bg-secondary'"
                                                  x-text="patient.age ?? (patient.active ? '{{ __("actions.active") }}' : '{{ __("actions.inactive") }}')">
                                            </span>
                                        </div>
                                        <address class="small text-muted mb-2">
                                            <strong>{{ __('actions.code') }}:</strong>
                                            <span x-text="patient.code"></span><br>
                                            <strong>{{ __('actions.skin') }}:</strong>
                                            <span x-text="patient.skin ?? '{{ __("actions.not_informed") }}'"></span><br>
                                            <strong>{{ __('actions.iris') }}:</strong>
                                            <span x-text="patient.iris ?? '{{ __("actions.not_informed") }}'"></span><br>
                                            <strong>{{ __('actions.covenant') }}:</strong>
                                            <span x-text="patient.covenant ?? '{{ __("actions.not_informed") }}'"></span>
                                        </address>
                                        <hr class="my-2">
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-info btn-show"
                                                    :data-id="patient.id"
                                                    data-bs-toggle="tooltip"
                                                    title="{{ __('actions.view') }}">
                                                <i class="fa fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-warning"
                                                    @click="$dispatch('edit-patient', { id: patient.id })"
                                                    data-bs-toggle="tooltip"
                                                    title="{{ __('actions.edit') }}">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger btn-trash"
                                                    :data-id="patient.id"
                                                    data-bs-toggle="tooltip"
                                                    title="{{ __('actions.delete') }}">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Empty state --}}
                <div x-show="patients.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="fa fa-user-slash fa-3x mb-3"></i>
                    <p>{{ __('actions.no_records') }}</p>
                </div>

                {{-- Pagination --}}
                <nav x-show="meta.last_page > 1" class="d-flex justify-content-center mt-3">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item" :class="{ 'disabled': meta.current_page === 1 }">
                            <button class="page-link" @click="fetchCards(meta.current_page - 1)">
                                <i class="fa fa-chevron-left"></i>
                            </button>
                        </li>
                        <template x-for="page in meta.last_page" :key="page">
                            <li class="page-item" :class="{ 'active': page === meta.current_page }">
                                <button class="page-link" x-text="page" @click="fetchCards(page)"></button>
                            </li>
                        </template>
                        <li class="page-item" :class="{ 'disabled': meta.current_page === meta.last_page }">
                            <button class="page-link" @click="fetchCards(meta.current_page + 1)">
                                <i class="fa fa-chevron-right"></i>
                            </button>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>

    </div>{{-- /patientViewToggle --}}

</div>{{-- /crudForm --}}

@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    @vite(['resources/js/system/patients.js'])
@endsection
