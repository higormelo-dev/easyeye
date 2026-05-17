@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- crudForm: gerencia o modal de criação/edição de médico --}}
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

    {{-- doctorViewToggle --}}
    <div x-data="doctorViewToggle(@js(route('panel.doctors.cards')), @js(Vite::asset('resources/img/system/team.png')))"
         x-init="init()">

        {{-- ══ Page Header ══════════════════════════════════════════════════════ --}}
        <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-1 border-bottom">
            <div class="flex-grow-1">
                <h4 class="fw-bold mb-0">
                    {{ $meta['title'] }}
                    <span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13 ms-1">
                        {{ __('actions.total') }}: {{ $meta['total_doctors'] }}
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
                {{-- Novo Médico --}}
                <button type="button"
                        class="btn btn-primary fs-13 btn-md"
                        @click="$dispatch('open-create-doctor')">
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
            {{ $dataTable->table(['class' => 'table table-nowrap']) }}
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
                <div class="row" x-show="doctors.length > 0">
                    <template x-for="doctor in doctors" :key="doctor.id">
                        <div class="col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4 mb-3">
                            <div class="card card-body h-100">
                                <div class="row align-items-center">
                                    <div class="col-md-4 col-lg-3 text-center">
                                        <img :src="doctor.photo_url"
                                             :alt="doctor.full_name"
                                             class="img-fluid rounded-circle"
                                             x-on:error="$el.src = fallbackPhoto">
                                    </div>
                                    <div class="col-md-8 col-lg-9">
                                        <h5 class="mb-1" x-text="doctor.full_name"></h5>
                                        <div class="mb-2">
                                            <span :class="doctor.active
                                                    ? 'badge badge-soft-success rounded text-success border border-success fs-13 fw-medium'
                                                    : 'badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium'"
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
                                        <div class="d-flex align-items-center float-end gap-1">
                                            <template x-if="doctor.mode === 'restore'">
                                                <a href="javascript:void(0);"
                                                   class="btn-restore shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                                   :data-id="doctor.id"
                                                   data-bs-toggle="tooltip"
                                                   title="{{ __('actions.restore') }}">
                                                    <i class="ti ti-recycle"></i>
                                                </a>
                                            </template>

                                            <template x-if="doctor.mode === 'full'">
                                                <div class="d-flex align-items-center gap-1">
                                                    <a href="javascript:void(0);"
                                                       class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                                       :data-id="doctor.id"
                                                       data-bs-toggle="tooltip"
                                                       title="{{ __('actions.view') }}">
                                                        <i class="ti ti-eye"></i>
                                                    </a>
                                                    <a href="javascript:void(0);"
                                                       class="btn-work-schedule shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                                       :data-id="doctor.id"
                                                       data-bs-toggle="tooltip"
                                                       title="{{ __('actions.work_schedule') }}">
                                                        <i class="ti ti-calendar"></i>
                                                    </a>
                                                    <a href="javascript:void(0);"
                                                       class="shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                                       data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="ti ti-dots-vertical"></i>
                                                    </a>
                                                    <ul class="dropdown-menu p-2">
                                                        <li>
                                                            <a class="dropdown-item" href="javascript:void(0);"
                                                               @click="$dispatch('edit-doctor', { id: doctor.id })">
                                                                <i class="ti ti-edit me-1"></i>{{ __('actions.edit') }}
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item btn-active"
                                                               href="javascript:void(0);"
                                                               :data-id="doctor.id"
                                                               :data-situation="doctor.active ? 0 : 1">
                                                                <i class="ti me-1"
                                                                   :class="doctor.active ? 'ti-lock-open' : 'ti-lock'"></i>
                                                                <span x-text="doctor.active ? '{{ __('actions.disable') }}' : '{{ __('actions.enable') }}'"></span>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item btn-trash text-danger"
                                                               href="javascript:void(0);"
                                                               :data-id="doctor.id">
                                                                <i class="ti ti-trash me-1"></i>{{ __('actions.delete') }}
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Empty state --}}
                <div x-show="doctors.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="ti ti-stethoscope fs-1 mb-3 d-block"></i>
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

    </div>{{-- /doctorViewToggle --}}

</div>{{-- /crudForm --}}

@endsection

@section('modals')
    @include('components.modal_default')
    @include('system.doctors._work-schedule-modal')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    @vite(['resources/js/system/doctors.js'])
    <script>
    window.doctorsLang = {
        workScheduleLoadError: @js(__('actions.work_schedule_load_error')),
        workScheduleSaveError: @js(__('actions.work_schedule_save_error')),
    };
    </script>
    <script>
    function workScheduleModalData() {
        return {
            loading:       false,
            saving:        false,
            saveError:     '',
            saveSuccess:   false,
            doctorName:    '',
            doctorRecord:  '',
            doctorColor:   '#6c757d',
            doctorPhoto:   '',
            days:          [],
            interval:      null,
            entityInterval: null,
            syncUrl:       '',
            storeBlockUrl: '',
            destroyBlockBase: '',
            blocks:        [],
            showBlockForm: false,
            storingBlock:  false,
            blockForm:     { type: 'absence', starts_at: '', ends_at: '', reason: '' },
            blockErrors:   {},

            init() {
                this.$el.addEventListener('show.bs.modal', () => {});
                window.addEventListener('open-work-schedule', (e) => {
                    this.openFor(e.detail.doctorId);
                });
            },

            openFor(doctorId) {
                this.loading     = true;
                this.saveError   = '';
                this.saveSuccess = false;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('workScheduleModal')).show();

                fetch(`/panel/doctors/${doctorId}/work-schedule/data`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    this.doctorName      = data.doctor.name;
                    this.doctorRecord    = data.doctor.record;
                    this.doctorColor     = data.doctor.color;
                    this.doctorPhoto     = data.doctor.photo_url;
                    this.days            = data.days;
                    this.interval        = data.interval;
                    this.entityInterval  = data.entity_interval;
                    this.syncUrl         = data.sync_url;
                    this.storeBlockUrl   = data.store_block_url;
                    this.destroyBlockBase = data.destroy_block_base;
                    this.blocks          = data.blocks;
                    this.showBlockForm   = false;
                    this.loading         = false;
                })
                .catch(() => {
                    this.loading   = false;
                    this.saveError = window.doctorsLang.workScheduleLoadError;
                });
            },

            addRange(dayIndex) {
                if (! this.days[dayIndex].active) {
                    this.days[dayIndex].active = true;
                }
                this.days[dayIndex].ranges.push({ starts_at: '08:00', ends_at: '12:00' });
            },

            removeRange(dayIndex, rangeIndex) {
                this.days[dayIndex].ranges.splice(rangeIndex, 1);
            },

            save() {
                this.saving      = true;
                this.saveError   = '';
                this.saveSuccess = false;

                fetch(this.syncUrl, {
                    method:  'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ schedule_interval: this.interval, days: this.days }),
                })
                .then(async r => {
                    const json = await r.json();
                    if (! r.ok) throw json;
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('workScheduleModal')).hide();
                })
                .catch(err => {
                    this.saveError = err?.message ?? window.doctorsLang.workScheduleSaveError;
                })
                .finally(() => { this.saving = false; });
            },

            storeBlock() {
                this.storingBlock = true;
                this.blockErrors  = {};

                fetch(this.storeBlockUrl, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(this.blockForm),
                })
                .then(async r => {
                    const json = await r.json();
                    if (! r.ok) {
                        this.blockErrors = json.errors ?? {};
                        throw json;
                    }
                    this.blocks.push(json.data);
                    this.blockForm     = { type: 'absence', starts_at: '', ends_at: '', reason: '' };
                    this.showBlockForm = false;
                })
                .catch(() => {})
                .finally(() => { this.storingBlock = false; });
            },

            destroyBlock(blockId) {
                fetch(`${this.destroyBlockBase}/${blockId}`, {
                    method:  'DELETE',
                    headers: {
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                })
                .then(r => {
                    if (r.ok) {
                        this.blocks = this.blocks.filter(b => b.id !== blockId);
                    }
                });
            },
        };
    }
    </script>
@endsection
