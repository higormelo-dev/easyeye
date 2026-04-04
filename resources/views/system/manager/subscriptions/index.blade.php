@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div x-data="managerViewToggle(@js(route('panel.manager.subscriptions.cards')), 'manager_subscriptions_view', 'subscriptions_datatable')"
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

        {{-- ══ Configurações de Trial e Graça ═══════════════════════════════════ --}}
        <div class="card mb-3">
            <div class="card-header fw-semibold">Configurações de Trial e Graça</div>
            <div class="card-body">
                <form id="settings-form" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Dias de Trial</label>
                        <input type="number" class="form-control" name="trial_days" value="{{ $trialDays }}" min="1" max="365">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Dias de Graça (após expiração)</label>
                        <input type="number" class="form-control" name="grace_period_days" value="{{ $graceDays }}" min="0" max="30">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
        {{-- ══ /Configurações ═══════════════════════════════════════════════════ --}}

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
                                        <div class="avatar-sm rounded-circle d-flex align-items-center justify-content-center"
                                             :class="item.entity_active ? 'bg-success-subtle' : 'bg-danger-subtle'">
                                            <i class="fas fa-file-contract"
                                               :class="item.entity_active ? 'text-success' : 'text-danger'"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1" x-text="item.entity_name"></h5>
                                        <div class="mb-2">
                                            <span class="badge" :class="item.status_badge"
                                                  x-text="item.status_label"></span>
                                        </div>
                                        <address class="small text-muted mb-2">
                                            <strong>{{ __('actions.plan') }}:</strong>
                                            <span x-text="item.plan_name"></span><br>
                                            <strong>{{ __('actions.starts_at') }}:</strong>
                                            <span x-text="item.starts_at || '-'"></span><br>
                                            <strong>{{ __('actions.ends_at') }}:</strong>
                                            <span x-text="item.ends_at || 'Vitalício'"></span>
                                            <template x-if="item.trial_ends_at">
                                                <span><br><strong>Trial até:</strong> <span x-text="item.trial_ends_at"></span></span>
                                            </template>
                                        </address>
                                        <hr class="my-2">
                                        <div class="d-flex align-items-center float-end gap-1">
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
                                                <template x-if="item.is_accessible">
                                                    <li>
                                                        <a class="dropdown-item btn-cancel text-warning"
                                                           href="javascript:void(0);"
                                                           :data-id="item.id"
                                                           :data-entity-id="item.entity_id">
                                                            <i class="ti ti-ban me-1"></i>Cancelar assinatura
                                                        </a>
                                                    </li>
                                                </template>
                                                <li>
                                                    <a class="dropdown-item btn-block"
                                                       href="javascript:void(0);"
                                                       :data-entity-id="item.entity_id"
                                                       :data-active="item.entity_active ? 0 : 1">
                                                        <i class="ti me-1" :class="item.entity_active ? 'ti-lock' : 'ti-lock-open'"></i>
                                                        <span x-text="item.entity_active ? 'Bloquear acesso' : 'Desbloquear acesso'"></span>
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
                <div x-show="items.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="fas fa-file-contract fa-3x mb-3"></i>
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
            storeUrl:  null,
            updateUrl: null,
            fields: {
                plan_id: '', status: '', starts_at: '', ends_at: '', trial_ends_at: ''
            },
            onSuccess: () => window.dispatchEvent(new CustomEvent('subscription-saved'))
        })"
         x-init="$nextTick(() => document.getElementById('subscriptionModal').addEventListener('hidden.bs.modal', () => reset()))"
         @edit-subscription.window="loadAndEdit(
             '{{ $baseUrl }}' + '/' + $event.detail.id,
             '{{ $baseUrl }}' + '/' + $event.detail.id,
             'subscriptionModal'
         )">

        {{-- Modal de edição --}}
        <x-crud-modal id="subscriptionModal" title="Assinatura">
            <x-slot:body>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Plano <span class="text-danger">*</span></label>
                        <select class="form-select" x-model="form.plan_id" :class="{'is-invalid': hasError('plan_id')}">
                            <option value="">-- Selecione --</option>
                            @foreach($plans as $plan)
                                <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" x-text="firstError('plan_id')"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select class="form-select" x-model="form.status" :class="{'is-invalid': hasError('status')}">
                            @foreach($statuses as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" x-text="firstError('status')"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Início <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" x-model="form.starts_at"
                               :class="{'is-invalid': hasError('starts_at')}">
                        <div class="invalid-feedback" x-text="firstError('starts_at')"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Vencimento</label>
                        <input type="date" class="form-control" x-model="form.ends_at">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Trial até</label>
                        <input type="date" class="form-control" x-model="form.trial_ends_at">
                    </div>
                </div>
            </x-slot:body>
        </x-crud-modal>
    </div>
@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    <script>
    $(function () {
        const baseUrl = '{{ $baseUrl }}';

        function reloadAll() {
            if (window.LaravelDataTables && window.LaravelDataTables['subscriptions_datatable']) {
                window.LaravelDataTables['subscriptions_datatable'].ajax.reload(null, false);
            }
        }

        $(document).on('click', '.btn-edit', function () {
            window.dispatchEvent(new CustomEvent('edit-subscription', { detail: { id: $(this).data('id') } }));
        });

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => __("actions.subscription")]) }}');
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

        $(document).on('click', '.btn-cancel', function () {
            const entityId = $(this).data('entity-id');
            Swal.fire({
                title: 'Cancelar assinatura?',
                text: 'O acesso será mantido durante o período de graça.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sim, cancelar',
                cancelButtonText: 'Não'
            }).then(r => {
                if (!r.isConfirmed) return;
                $.ajax({
                    method: 'POST',
                    url: baseUrl + '/cancel',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    data: { entity_id: entityId },
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

        $(document).on('click', '.btn-block', function () {
            const entityId  = $(this).data('entity-id');
            const active    = $(this).data('active');
            const isBlocking = active == 0;

            Swal.fire({
                title: isBlocking ? 'Bloquear acesso?' : 'Desbloquear acesso?',
                text: isBlocking
                    ? 'A empresa e todos os seus usuários perderão o acesso ao sistema.'
                    : 'A empresa e todos os seus usuários terão o acesso restaurado.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: isBlocking ? '#dc3545' : '#198754',
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(r => {
                if (!r.isConfirmed) return;
                $.ajax({
                    method: 'PATCH',
                    url: baseUrl + '/block-access',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    data: { entity_id: entityId, active: active },
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

        $('#settings-form').on('submit', function (e) {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(this));
            $.ajax({
                method: 'POST',
                url: baseUrl + '/settings',
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: data,
                success: function (res) {
                    if (window.showSuccessToast) showSuccessToast(res.message);
                },
                error: function (res) {
                    if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                }
            });
        });

        window.addEventListener('subscription-saved', () => reloadAll());
    });
    </script>
@endsection
