@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div x-data="managerViewToggle(@js(route('panel.manager.plans.cards')), 'manager_plans_view', 'plans_datatable')"
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
                {{-- Novo Plano --}}
                <div class="btn-group" role="group">
                    <a href="javascript:void(0);" class="btn btn-primary fs-13 btn-md"
                       @click="$dispatch('open-create-plan')">
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
                <div class="row" x-show="items.length > 0">
                    <template x-for="item in items" :key="item.id">
                        <div class="col-xs-12 col-sm-6 col-md-6 col-lg-4 col-xl-4 mb-3">
                            <div class="card card-body h-100">
                                <div class="d-flex align-items-start">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm rounded-circle bg-info-subtle d-flex align-items-center justify-content-center">
                                            <i class="fas fa-box-open text-info"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1" x-text="item.name"></h5>
                                        <div class="mb-2">
                                            <span :class="item.active ? 'badge bg-success' : 'badge bg-secondary'"
                                                  x-text="item.active ? '{{ __("actions.active") }}' : '{{ __("actions.inactive") }}'">
                                            </span>
                                        </div>
                                        <address class="small text-muted mb-2">
                                            <strong>{{ __('actions.price') }}:</strong>
                                            <span x-text="item.price"></span><br>
                                            <strong>Ciclo:</strong>
                                            <span x-text="item.billing_cycle"></span>
                                            <template x-if="item.description">
                                                <span><br><span x-text="item.description"></span></span>
                                            </template>
                                        </address>
                                        <hr class="my-2">
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-light btn-show"
                                                    :data-id="item.id"
                                                    data-bs-toggle="tooltip"
                                                    title="{{ __('actions.view') }}">
                                                <i class="fa fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-light btn-edit"
                                                    :data-id="item.id"
                                                    data-bs-toggle="tooltip"
                                                    title="{{ __('actions.edit') }}">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-light btn-active"
                                                    :data-id="item.id"
                                                    :data-situation="item.active ? 0 : 1"
                                                    data-bs-toggle="tooltip"
                                                    :title="item.active ? '{{ __("actions.disable") }}' : '{{ __("actions.enable") }}'">
                                                <i :class="item.active ? 'fas fa-lock-open' : 'fas fa-unlock'"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger btn-trash"
                                                    :data-id="item.id"
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
                <div x-show="items.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="fas fa-box-open fa-3x mb-3"></i>
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
            storeUrl: '{{ $storeUrl }}',
            updateUrl: null,
            fields: {
                name: '', description: '', price: '', billing_cycle: 'monthly',
                active: true, sort_order: 0,
                features: {
                    @foreach($features as $f)
                    '{{ $f->value }}': '',
                    @endforeach
                }
            },
            onSuccess: () => window.dispatchEvent(new CustomEvent('plan-saved'))
        })"
         x-init="$nextTick(() => document.getElementById('planModal').addEventListener('hidden.bs.modal', () => reset()))"
         @open-create-plan.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('planModal')).show()"
         @edit-plan.window="loadAndEdit(
             '{{ $baseUrl }}' + '/' + $event.detail.id,
             '{{ $baseUrl }}' + '/' + $event.detail.id,
             'planModal'
         )">

        {{-- Modal --}}
        <x-crud-modal id="planModal" title="Plano">
            <x-slot:body>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" :class="{'is-invalid': hasError('name')}"
                               x-model="form.name" maxlength="100" autocomplete="off">
                        <div class="invalid-feedback" x-text="firstError('name')"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Ordem</label>
                        <input type="number" class="form-control" x-model="form.sort_order" min="0">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control" x-model="form.description" rows="2" maxlength="500"></textarea>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Preço (R$)</label>
                        <input type="number" class="form-control" x-model="form.price" step="0.01" min="0">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Ciclo de Cobrança <span class="text-danger">*</span></label>
                        <select class="form-select" x-model="form.billing_cycle">
                            @foreach($billingCycles as $cycle)
                                <option value="{{ $cycle->value }}">{{ $cycle->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4" x-show="editing">
                        <label class="form-label">Status</label>
                        <select class="form-select" x-model="form.active">
                            <option :value="true">Ativo</option>
                            <option :value="false">Inativo</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <hr><h6 class="text-muted">Limites e Features</h6>
                        <div class="alert alert-info alert-sm py-2 mb-2" role="alert">
                            <i class="fas fa-info-circle me-1"></i>
                            Para limites numéricos, <strong>0 significa ilimitado</strong>. Features booleanas indicam se o recurso está disponível no plano.
                        </div>
                    </div>

                    @foreach($features as $feature)
                    <div class="col-md-4">
                        <label class="form-label">{{ $feature->label() }}</label>
                        @if($feature->isBoolean())
                        <select class="form-select" x-model="form.features['{{ $feature->value }}']">
                            <option value="0">Não</option>
                            <option value="1">Sim</option>
                        </select>
                        @else
                        <input type="number" class="form-control"
                               x-model="form.features['{{ $feature->value }}']"
                               min="0">
                        @endif
                    </div>
                    @endforeach
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
            if (window.LaravelDataTables && window.LaravelDataTables['plans_datatable']) {
                window.LaravelDataTables['plans_datatable'].ajax.reload(null, false);
            }
        }

        $(document).on('click', '.btn-edit', function () {
            window.dispatchEvent(new CustomEvent('edit-plan', { detail: { id: $(this).data('id') } }));
        });

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => __("actions.plan")]) }}');
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
                method: 'PATCH',
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
                title: 'Remover plano?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(r => {
                if (!r.isConfirmed) return;
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

        window.addEventListener('plan-saved', () => reloadAll());
    });
    </script>
@endsection
