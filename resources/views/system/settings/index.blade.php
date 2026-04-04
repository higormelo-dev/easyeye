@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div x-data="crudForm({
        storeUrl:  @js($storeUrl),
        updateUrl: null,
        fields:    @js($crudFields),
        onSuccess: () => {
            window.dispatchEvent(new CustomEvent('setting-saved'));
            bootstrap.Modal.getOrCreateInstance(document.getElementById('settingModal')).hide();
        }
    })"
     x-init="$nextTick(() => document.getElementById('settingModal').addEventListener('hidden.bs.modal', () => reset()))"
     @open-create-setting.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('settingModal')).show()"
     @edit-setting.window="loadAndEdit(
         @js($baseUrl) + '/' + $event.detail.id,
         @js($baseUrl) + '/' + $event.detail.id,
         'settingModal'
     )">

    @include('system.settings._form-modal')

    {{-- settingViewToggle --}}
    <div x-data="settingViewToggle(@js($meta['cardsUrl']), @js($storageKey))"
         x-init="init()"
         @setting-saved.window="reloadCurrentView()">

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
                {{-- Novo --}}
                <button type="button"
                        class="btn btn-primary fs-13 btn-md"
                        @click="$dispatch('open-create-setting')">
                    <i class="ti ti-plus me-1"></i>{{ __('actions.new') }}
                </button>
            </div>
        </div>
        {{-- ══ /Page Header ═════════════════════════════════════════════════════ --}}

        {{-- ══ Filter Bar ═══════════════════════════════════════════════════════ --}}
        <div class="d-flex align-items-center justify-content-between flex-wrap mb-3">
            <div class="search-set">
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
                <div class="row" x-show="settings.length > 0">
                    <template x-for="setting in settings" :key="setting.id">
                        <div class="col-xs-12 col-sm-6 col-md-4 col-lg-3 mb-3">
                            <div class="card card-body h-100">
                                <h5 class="mb-2" x-text="setting.name"></h5>
                                <div class="mb-3">
                                    <span :class="setting.active
                                            ? 'badge badge-soft-success rounded text-success border border-success fs-13 fw-medium'
                                            : 'badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium'"
                                          x-text="setting.active ? '{{ __('actions.active') }}' : '{{ __('actions.inactive') }}'">
                                    </span>
                                </div>
                                <hr class="my-2">
                                <div class="d-flex align-items-center float-end gap-1">
                                    <a href="javascript:void(0);"
                                       class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                       :data-id="setting.id"
                                       data-bs-toggle="tooltip"
                                       title="{{ __('actions.view') }}">
                                        <i class="ti ti-eye"></i>
                                    </a>
                                    <template x-if="setting.is_owned">
                                        <div class="d-inline-flex align-items-center gap-1">
                                            <a href="javascript:void(0);"
                                               class="shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                                               data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="ti ti-dots-vertical"></i>
                                            </a>
                                            <ul class="dropdown-menu p-2">
                                                <li>
                                                    <a class="dropdown-item btn-edit"
                                                       href="javascript:void(0);"
                                                       :data-id="setting.id">
                                                        <i class="ti ti-edit me-1"></i>{{ __('actions.edit') }}
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item btn-trash text-danger"
                                                       href="javascript:void(0);"
                                                       :data-id="setting.id">
                                                        <i class="ti ti-trash me-1"></i>{{ __('actions.delete') }}
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Empty state --}}
                <div x-show="settings.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="ti ti-box-off fs-1 mb-3 d-block"></i>
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

    </div>{{-- /settingViewToggle --}}

</div>{{-- /crudForm --}}

@endsection

@section('modals')
    @include('components.modal_default')
    @includeIf('system.settings._partials.' . $viewSlot . '.modals')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    @vite([$jsFile])
@endsection
