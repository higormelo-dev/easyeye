@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div x-data="managerViewToggle(@js(route('panel.manager.entities.cards')), 'manager_entities_view', 'entities_datatable')"
         x-init="init()">

        {{-- ══ Page Header ══════════════════════════════════════════════════════ --}}
        <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-1 border-bottom">
            <div class="flex-grow-1">
                <h4 class="fw-bold mb-0">
                    {{ $meta['title'] }}
                    <span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13 ms-1">
                        {{ __('actions.total') }}: {{ $meta['total'] }}
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
                {{-- Nova Empresa --}}
                <div class="btn-group" role="group">
                    <a href="javascript:void(0);" class="btn btn-primary fs-13 btn-md"
                       @click="$dispatch('open-create-entity')">
                        <i class="ti ti-plus me-1"></i>{{ __('actions.new') }}
                    </a>
                </div>
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
                <div class="row" x-show="items.length > 0">
                    <template x-for="item in items" :key="item.id">
                        <div class="col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6 mb-3">
                            <div class="card card-body h-100">
                                <div class="d-flex align-items-start">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center">
                                            <i class="fa fa-building text-primary"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1" x-text="item.name"></h5>
                                        <div class="mb-2">
                                            <template x-if="item.deleted">
                                                <span class="badge bg-danger">{{ __('actions.delete') }}</span>
                                            </template>
                                            <template x-if="!item.deleted">
                                                <span :class="item.active ? 'badge bg-success' : 'badge bg-secondary'"
                                                      x-text="item.active ? '{{ __("actions.active") }}' : '{{ __("actions.inactive") }}'">
                                                </span>
                                            </template>
                                        </div>
                                        <address class="small text-muted mb-2">
                                            <strong>{{ __('actions.code') }}:</strong>
                                            <span x-text="item.code"></span><br>
                                            <template x-if="item.city || item.state">
                                                <span>
                                                    <strong>{{ __('forms.city') }}:</strong>
                                                    <span x-text="(item.city || '-') + ' / ' + (item.state || '-')"></span>
                                                </span>
                                            </template>
                                        </address>
                                        <hr class="my-2">
                                        <div class="d-flex align-items-center float-end gap-1">
                                            <template x-if="!item.deleted">
                                                <div class="d-flex align-items-center gap-1">
                                                    <a href="javascript:void(0);"
                                                       class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                                       :data-id="item.id"
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
                                                            <a class="dropdown-item btn-edit"
                                                               href="javascript:void(0);"
                                                               :data-id="item.id">
                                                                <i class="ti ti-edit me-1"></i>{{ __('actions.edit') }}
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item btn-active"
                                                               href="javascript:void(0);"
                                                               :data-id="item.id"
                                                               :data-situation="item.active ? 0 : 1">
                                                                <i class="ti me-1" :class="item.active ? 'ti-lock-open' : 'ti-lock'"></i>
                                                                <span x-text="item.active ? '{{ __('actions.disable') }}' : '{{ __('actions.enable') }}'"></span>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item btn-trash text-danger"
                                                               href="javascript:void(0);"
                                                               :data-id="item.id">
                                                                <i class="ti ti-trash me-1"></i>{{ __('actions.delete') }}
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </template>
                                            <template x-if="item.deleted">
                                                <a href="javascript:void(0);"
                                                   class="btn-restore shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                                   :data-id="item.id"
                                                   data-bs-toggle="tooltip"
                                                   title="{{ __('actions.restore') }}">
                                                    <i class="ti ti-recycle"></i>
                                                </a>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Empty state --}}
                <div x-show="items.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="fa fa-building fa-3x mb-3"></i>
                    <p>{{ __('actions.no_records') }}</p>
                </div>

                {{-- Pagination --}}
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
        {{-- ══ /Cards View ══════════════════════════════════════════════════════ --}}

    </div>

    {{-- ══ crudForm (escopo separado para o modal) ══════════════════════════ --}}
    <div x-data="crudForm({
            storeUrl:  '{{ $storeUrl }}',
            updateUrl: null,
            fields: {
                name: '', subdomain: '', email: '', telephone: '', cellphone: '',
                national_registration: '', state_registration: '', municipal_registration: '',
                website: '', schedule_interval: 15, active: true,
                zipcode: '', address: '', number: '', complement: '',
                district: '', city: '', state: '', country: ''
            },
            onSuccess: () => window.dispatchEvent(new CustomEvent('entity-saved'))
        })"
         x-init="$nextTick(() => document.getElementById('entityModal').addEventListener('hidden.bs.modal', () => reset()))"
         @open-create-entity.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('entityModal')).show()"
         @edit-entity.window="loadAndEdit(
             '{{ url('panel/manager/entities') }}' + '/' + $event.detail.id + '/edit-data',
             '{{ url('panel/manager/entities') }}' + '/' + $event.detail.id,
             'entityModal'
         )">
        @include('system.manager.entities._form-modal')
    </div>
@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    <script>
    $(function () {
        const baseUrl = '{{ url('panel/manager/entities') }}';

        function reloadAll() {
            if (window.LaravelDataTables && window.LaravelDataTables['entities_datatable']) {
                window.LaravelDataTables['entities_datatable'].ajax.reload(null, false);
            }
        }

        $(document).on('click', '.btn-edit', function () {
            window.dispatchEvent(new CustomEvent('edit-entity', { detail: { id: $(this).data('id') } }));
        });

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => __("actions.entity")]) }}');
            $('#btn-modal-default').hide();
            $('#erro-default').hide();
            $.ajax({
                url: baseUrl + '/' + id,
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function (data) {
                    $('#retorno-default').html(data);
                    $('#modal_default').modal('show');
                },
                error: function (res) {
                    if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                }
            });
        });

        $(document).on('click', '.btn-active', function () {
            const id        = $(this).data('id');
            const situation = $(this).data('situation');
            $.ajax({
                method: 'PUT',
                url: baseUrl + '/' + id,
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: { active: situation },
                success: function (res) {
                    if (window.showSuccessToast) showSuccessToast(res.message);
                    reloadAll();
                },
                error: function (res) {
                    if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                }
            });
        });

        $(document).on('click', '.btn-trash', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Deletar empresa?',
                text: 'Esta ação não poderá ser desfeita.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(result => {
                if (!result.isConfirmed) return;
                $.ajax({
                    method: 'DELETE',
                    url: baseUrl + '/' + id,
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        reloadAll();
                    },
                    error: function (res) {
                        if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                    }
                });
            });
        });

        $(document).on('click', '.btn-restore', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Restaurar empresa?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(result => {
                if (!result.isConfirmed) return;
                $.ajax({
                    method: 'PUT',
                    url: baseUrl + '/' + id + '/restore',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        reloadAll();
                    },
                    error: function (res) {
                        if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                    }
                });
            });
        });

        window.addEventListener('entity-saved', () => reloadAll());
    });
    </script>
@endsection
