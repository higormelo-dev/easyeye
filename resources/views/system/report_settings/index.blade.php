@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div x-data="settingViewToggle(@js(route('panel.setting.report-settings.cards')), 'report_settings_view')"
     x-init="init()">

    {{-- ══ Page Header ══════════════════════════════════════════════════════════ --}}
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
            {{-- Novo Modelo --}}
            <a href="{{ route('panel.setting.report-settings.create') }}"
               class="btn btn-primary fs-13 btn-md">
                <i class="ti ti-plus me-1"></i>{{ __('actions.report_settings.new') }}
            </a>
        </div>
    </div>
    {{-- ══ /Page Header ══════════════════════════════════════════════════════════ --}}

    @if(session('message'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- ══ Filter Bar ════════════════════════════════════════════════════════════ --}}
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
    {{-- ══ /Filter Bar ════════════════════════════════════════════════════════════ --}}

    {{-- ══ DataTable View ════════════════════════════════════════════════════════ --}}
    <div x-show="view === 'table'">
        <div class="table-responsive">
            {{ $dataTable->table(['class' => 'table table-nowrap']) }}
        </div>
    </div>
    {{-- ══ /DataTable View ════════════════════════════════════════════════════════ --}}

    {{-- ══ Cards View ════════════════════════════════════════════════════════════ --}}
    <div x-show="view === 'cards'" x-cloak>

        <div x-show="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">{{ __('actions.loading') }}...</span>
            </div>
        </div>

        <div x-show="!loading">
            <div class="row g-3" x-show="settings.length > 0">
                <template x-for="s in settings" :key="s.id">
                    <div class="col-12 col-sm-6 col-xl-4">
                        <div class="card h-100 shadow-sm border-0">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <h6 class="card-title mb-0 flex-grow-1" x-text="s.title"></h6>
                                    <span :class="s.active
                                            ? 'badge badge-soft-success rounded text-success border border-success fs-13 fw-medium'
                                            : 'badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium'"
                                          x-text="s.active ? '{{ __('actions.active') }}' : '{{ __('actions.inactive') }}'">
                                    </span>
                                </div>
                                <div class="text-muted small mb-3">
                                    <i class="ti ti-file me-1"></i>
                                    <span x-text="s.paper_size"></span>
                                </div>
                                <div class="d-flex flex-wrap gap-1 mb-0">
                                    <span :class="s.show_header
                                            ? 'badge badge-soft-primary text-primary border border-primary'
                                            : 'badge bg-light text-muted'">
                                        <i class="ti ti-heading me-1"></i>{{ __('actions.report_settings.header') }}
                                    </span>
                                    <span :class="s.show_signature
                                            ? 'badge badge-soft-primary text-primary border border-primary'
                                            : 'badge bg-light text-muted'">
                                        <i class="ti ti-signature me-1"></i>{{ __('actions.report_settings.signature') }}
                                    </span>
                                    <span :class="s.show_footer
                                            ? 'badge badge-soft-primary text-primary border border-primary'
                                            : 'badge bg-light text-muted'">
                                        <i class="ti ti-layout-bottombar me-1"></i>{{ __('actions.report_settings.footer') }}
                                    </span>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-top-0 d-flex gap-2 justify-content-end">
                                <a :href="s.edit_url" class="btn btn-outline-secondary btn-sm">
                                    <i class="ti ti-edit me-1"></i>{{ __('actions.edit') }}
                                </a>
                                <form :action="s.delete_url" method="POST"
                                      onsubmit="return confirm('{{ __('actions.confirm_delete') }}')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                        <i class="ti ti-trash me-1"></i>{{ __('actions.delete') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Empty state --}}
            <div x-show="settings.length === 0 && !loading" class="text-center py-5 text-muted">
                <i class="ti ti-file-off fs-1 mb-3 d-block"></i>
                <p>{{ __('actions.report_settings.empty') }}</p>
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
    {{-- ══ /Cards View ════════════════════════════════════════════════════════════ --}}

</div>{{-- /settingViewToggle --}}

@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
@endsection
