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

        {{-- ══ Page Header ══════════════════════════════════════════════════════ --}}
        <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-1 border-bottom">
            <div class="flex-grow-1">
                <h4 class="fw-bold mb-0">
                    {{ $meta['title'] }}
                    <span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13 ms-1">
                        {{ __('actions.total') }}: {{ $meta['total_patients'] }}
                    </span>
                </h4>
            </div>
            <div class="d-flex align-items-center gap-2">
                {{-- Toggle tabela / cards --}}
                <div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center">
                    <button type="button"
                            class="rounded p-1 d-flex align-items-center justify-content-center border-0"
                            :class="view === 'table' ? 'bg-light' : 'bg-white'"
                            x-on:click="setView('table')"
                            title="{{ __('actions.table_view') }}">
                        <i class="ti ti-list fs-14 text-body"></i>
                    </button>
                    <button type="button"
                            class="rounded p-1 d-flex align-items-center justify-content-center border-0"
                            :class="view === 'cards' ? 'bg-light' : 'bg-white'"
                            x-on:click="setView('cards')"
                            title="{{ __('actions.card_view') }}">
                        <i class="ti ti-layout-grid fs-14 text-body"></i>
                    </button>
                </div>
                {{-- Novo Paciente --}}
                <button type="button"
                        class="btn btn-primary fs-13 btn-md"
                        @click="$dispatch('open-create-patient')">
                    <i class="ti ti-plus me-1"></i>{{ __('actions.new') }}
                </button>
            </div>
        </div>
        {{-- ══ /Page Header ═════════════════════════════════════════════════════ --}}

        {{-- ══ Filter Bar ═══════════════════════════════════════════════════════ --}}
        <div class="d-flex align-items-center justify-content-between flex-wrap mb-3">
            <div class="search-set">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <div class="table-search d-flex align-items-center mb-0">
                        <div class="search-input">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white">
                                    <i class="ti ti-search fs-12"></i>
                                </span>
                                <input type="text"
                                       class="form-control border-start-0"
                                       placeholder="{{ __('actions.search') }}..."
                                       x-model="search"
                                       x-on:input.debounce.400ms="performSearch()">
                                <button class="btn btn-outline-secondary border-start-0" type="button"
                                        x-show="search"
                                        x-on:click="clearSearch()">
                                    <i class="ti ti-x fs-12"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex table-dropdown right-content align-items-center flex-wrap row-gap-3">
                <div class="dropdown">
                    <a href="javascript:void(0);"
                       class="dropdown-toggle btn bg-white btn-md d-inline-flex align-items-center fw-normal rounded border text-dark px-2 py-1 fs-14"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="me-1">{{ __('actions.sort_by') }}:</span> {{ __('actions.recent') }}
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end p-2">
                        <li><a href="javascript:void(0);" class="dropdown-item rounded-1">{{ __('actions.recent') }}</a></li>
                        <li><a href="javascript:void(0);" class="dropdown-item rounded-1">{{ __('actions.oldest') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
        {{-- ══ /Filter Bar ══════════════════════════════════════════════════════ --}}

        {{-- ══ DataTable View ═══════════════════════════════════════════════════ --}}
        <div x-show="view === 'table'">
            <div class="table-responsive">
                {{ $dataTable->table(['class' => 'table table-nowrap']) }}
            </div>
        </div>
        {{-- ══ /DataTable View ══════════════════════════════════════════════════ --}}

        {{-- ══ Cards View ═══════════════════════════════════════════════════════ --}}
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
                        <div class="col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4 mb-3">
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
                                            <span :class="patient.active
                                                    ? 'badge badge-soft-success rounded text-success border border-success fs-13 fw-medium'
                                                    : 'badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium'"
                                                  x-text="patient.active ? '{{ __('actions.active') }}' : '{{ __('actions.inactive') }}'">
                                            </span>
                                        </div>
                                        <address class="small text-muted mb-2">
                                            <strong>{{ __('actions.code') }}:</strong>
                                            <span x-text="patient.code"></span><br>
                                            <strong>{{ __('actions.skin') }}:</strong>
                                            <span x-text="patient.skin ?? '{{ __('actions.not_informed') }}'"></span><br>
                                            <strong>{{ __('actions.iris') }}:</strong>
                                            <span x-text="patient.iris ?? '{{ __('actions.not_informed') }}'"></span><br>
                                            <strong>{{ __('actions.covenant') }}:</strong>
                                            <span x-text="patient.covenant ?? '{{ __('actions.not_informed') }}'"></span>
                                        </address>
                                        <hr class="my-2">
                                        <div class="d-flex align-items-center gap-1">
                                            <a href="javascript:void(0);"
                                               class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                               :data-id="patient.id"
                                               data-bs-toggle="tooltip"
                                               title="{{ __('actions.view') }}">
                                                <i class="ti ti-eye"></i>
                                            </a>
                                            <a href="javascript:void(0);"
                                               class="shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                               data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="ti ti-dots-vertical"></i>
                                            </a>
                                            <ul class="dropdown-menu p-2">
                                                <li>
                                                    <a class="dropdown-item" href="javascript:void(0);"
                                                       @click="$dispatch('edit-patient', { id: patient.id })">
                                                        <i class="ti ti-edit me-1"></i>{{ __('actions.edit') }}
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item btn-trash text-danger"
                                                       href="javascript:void(0);"
                                                       :data-id="patient.id">
                                                        <i class="ti ti-trash me-1"></i>{{ __('actions.delete') }}
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Empty state --}}
                <div x-show="patients.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="ti ti-user-off fs-1 mb-3 d-block"></i>
                    <p>{{ __('actions.no_records') }}</p>
                </div>

                {{-- Pagination --}}
                <nav x-show="meta.last_page > 1" class="d-flex justify-content-center mt-3">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item" :class="{ 'disabled': meta.current_page === 1 }">
                            <button class="page-link" @click="fetchCards(meta.current_page - 1)">
                                <i class="ti ti-arrow-left text-body"></i>
                            </button>
                        </li>
                        <template x-for="page in meta.last_page" :key="page">
                            <li class="page-item" :class="{ 'active': page === meta.current_page }">
                                <button class="page-link" x-text="page" @click="fetchCards(page)"></button>
                            </li>
                        </template>
                        <li class="page-item" :class="{ 'disabled': meta.current_page === meta.last_page }">
                            <button class="page-link" @click="fetchCards(meta.current_page + 1)">
                                <i class="ti ti-arrow-right"></i>
                            </button>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
        {{-- ══ /Cards View ══════════════════════════════════════════════════════ --}}

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
