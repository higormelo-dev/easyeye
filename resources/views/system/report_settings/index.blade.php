@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

@php
    $globalTemplatesPayload = isset($globalTemplates)
        ? $globalTemplates->map(function ($gt) {
            return [
                'id'             => $gt->id,
                'title'          => $gt->title,
                'description'    => $gt->description,
                'paper_size'     => $gt->paper_size?->value ?? (string) $gt->paper_size,
                'category'       => $gt->category?->name,
                'category_id'    => $gt->report_category_id,
                'version'        => $gt->version,
                'show_header'    => (bool) $gt->show_header,
                'show_signature' => (bool) $gt->show_signature,
                'show_footer'    => (bool) $gt->show_footer,
                'preview_url'    => route('panel.setting.report-settings.show', $gt),
                'adopt_url'      => route('panel.setting.report-settings.adopt', $gt),
            ];
        })->values()->all()
        : [];
@endphp

<div x-data="{
        ...settingViewToggle(@js($meta['cardsUrl']), 'report_settings_view'),
        categoryFilter: '',
        statusFilter: '',
        globalView: 'cards',
        globalSearch: '',
        globalCategoryFilter: '',
        globalPage: 1,
        globalPerPage: 6,
        globalTemplates: @js($globalTemplatesPayload),
        previewLoading: false,
        previewError: '',
        previewTemplate: null,

        fetchCards(page) {
            this.loading = true;
            const url = new URL(this.$data._cardsUrl ?? @js($meta['cardsUrl']));
            url.searchParams.set('search', this.search);
            url.searchParams.set('page', page);
            if (this.categoryFilter) url.searchParams.set('category_id', this.categoryFilter);
            if (this.statusFilter) url.searchParams.set('status', this.statusFilter);

            fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            })
                .then(r => r.json())
                .then(res => {
                    this.settings = res.data;
                    this.meta     = res.meta;
                    this.loading  = false;
                })
                .catch(() => { this.loading = false; });
        },

        openGlobalTemplatePreview(url) {
            this.previewLoading = true;
            this.previewError = '';
            this.previewTemplate = null;

            const modalEl = document.getElementById('globalTemplatePreviewModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }

            fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('preview_load_failed');
                    }

                    return response.json();
                })
                .then((data) => {
                    this.previewTemplate = {
                        ...data,
                        contents: Array.isArray(data.contents) ? data.contents : [],
                    };
                })
                .catch(() => {
                    this.previewError = @js(__('actions.work_schedule_load_error'));
                })
                .finally(() => {
                    this.previewLoading = false;
                });
        },

        truncate(text, limit = 100) {
            const raw = String(text ?? '');
            return raw.length > limit ? `${raw.slice(0, limit)}...` : raw;
        },

        globalResetPage() {
            this.globalPage = 1;
        },

        get filteredGlobalTemplates() {
            const search = String(this.globalSearch ?? '').trim().toLowerCase();

            return (this.globalTemplates || []).filter((template) => {
                const matchesSearch = !search
                    || String(template.title ?? '').toLowerCase().includes(search)
                    || String(template.description ?? '').toLowerCase().includes(search);

                const matchesCategory = !this.globalCategoryFilter
                    || String(template.category_id ?? '') === String(this.globalCategoryFilter);

                return matchesSearch && matchesCategory;
            });
        },

        get globalLastPage() {
            return Math.max(1, Math.ceil(this.filteredGlobalTemplates.length / this.globalPerPage));
        },

        get pagedGlobalTemplates() {
            const start = (this.globalPage - 1) * this.globalPerPage;
            return this.filteredGlobalTemplates.slice(start, start + this.globalPerPage);
        },

        setGlobalPage(page) {
            if (page < 1 || page > this.globalLastPage) return;
            this.globalPage = page;
        },
     }"
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
            @php
                $createRoute = ($meta['isManager'] ?? false)
                    ? route('panel.manager.report-settings.create')
                    : route('panel.setting.report-settings.create');
            @endphp
            <a href="{{ $createRoute }}"
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

                {{-- Category Filter --}}
                @isset($categories)
                <select class="form-select form-select-sm" style="width:auto"
                        x-model="categoryFilter"
                        x-on:change="fetchCards(1)">
                    <option value="">{{ __('actions.report_settings.all_categories') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
                @endisset

                {{-- Status Filter (manager only) --}}
                @if($meta['isManager'] ?? false)
                <select class="form-select form-select-sm" style="width:auto"
                        x-model="statusFilter"
                        x-on:change="fetchCards(1)">
                    <option value="">{{ __('actions.report_settings.all_statuses') }}</option>
                    @foreach($meta['statuses'] ?? [] as $st)
                        <option value="{{ $st }}">{{ \App\Enums\ReportSettingStatus::from($st)->label() }}</option>
                    @endforeach
                </select>
                @endif
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
                                    {{-- Status badge (manager) --}}
                                    <template x-if="s.status_label">
                                        <span class="badge fs-13 fw-medium"
                                              :class="s.status_badge"
                                              x-text="s.status_label"></span>
                                    </template>
                                    <span :class="s.active
                                            ? 'badge badge-soft-success rounded text-success border border-success fs-13 fw-medium'
                                            : 'badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium'"
                                          x-text="s.active ? '{{ __('actions.active') }}' : '{{ __('actions.inactive') }}'">
                                    </span>
                                </div>
                                <div class="text-muted small mb-2">
                                    <i class="ti ti-file me-1"></i>
                                    <span x-text="s.paper_size"></span>
                                    <template x-if="s.category">
                                        <span class="ms-2">
                                            <i class="ti ti-folder me-1"></i>
                                            <span x-text="s.category"></span>
                                        </span>
                                    </template>
                                    <template x-if="s.version">
                                        <span class="ms-2 text-primary fw-medium">v<span x-text="s.version"></span></span>
                                    </template>
                                </div>
                                {{-- Update available badge (entity view) --}}
                                <template x-if="s.has_update">
                                    <div class="mb-2">
                                        <span class="badge badge-soft-warning text-warning border border-warning fs-12">
                                            <i class="ti ti-refresh-alert me-1"></i>{{ __('actions.report_settings.update_available') }}
                                        </span>
                                    </div>
                                </template>
                                {{-- Adopted badge --}}
                                <template x-if="s.is_adopted">
                                    <span class="badge badge-soft-info text-info border border-info fs-12 mb-2">
                                        <i class="ti ti-copy me-1"></i>{{ __('actions.report_settings.adopted') }}
                                    </span>
                                </template>
                                {{-- Adopted count (manager) --}}
                                <template x-if="s.adopted_count !== undefined">
                                    <span class="badge bg-light text-muted fs-12 mb-2">
                                        <i class="ti ti-users me-1"></i><span x-text="s.adopted_count"></span> {{ __('actions.report_settings.adoptions') }}
                                    </span>
                                </template>
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
                            <div class="card-footer bg-transparent border-top-0 d-flex gap-2 justify-content-end flex-wrap">
                                {{-- Manager: publish/archive --}}
                                <template x-if="s.publish_url && s.status !== 'published'">
                                    <form :action="s.publish_url" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-success btn-sm">
                                            <i class="ti ti-check me-1"></i>{{ __('actions.report_settings.publish') }}
                                        </button>
                                    </form>
                                </template>
                                <template x-if="s.archive_url && s.status === 'published'">
                                    <form :action="s.archive_url" method="POST"
                                          onsubmit="return confirm('{{ __('actions.report_settings.confirm_archive') }}')">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-warning btn-sm">
                                            <i class="ti ti-archive me-1"></i>{{ __('actions.report_settings.archive') }}
                                        </button>
                                    </form>
                                </template>
                                {{-- Entity: reimport --}}
                                <template x-if="s.reimport_url && s.has_update">
                                    <form :action="s.reimport_url" method="POST"
                                          onsubmit="return confirm('{{ __('actions.report_settings.confirm_reimport') }}')">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-info btn-sm">
                                            <i class="ti ti-refresh me-1"></i>{{ __('actions.report_settings.reimport') }}
                                        </button>
                                    </form>
                                </template>
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

    {{-- ══ Global Templates Available (entity view only) ════════════════════════ --}}
    @if(!($meta['isManager'] ?? false) && isset($globalTemplates) && $globalTemplates->isNotEmpty())
    <div class="mt-4">
        <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-1 border-bottom">
            <div class="flex-grow-1">
                <h5 class="fw-semibold mb-0">
                    <i class="ti ti-world me-2 text-primary"></i>{{ __('actions.report_settings.global_available') }}
                </h5>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center">
                    <button type="button"
                            class="rounded p-1 d-flex align-items-center justify-content-center border-0"
                            :class="globalView === 'table' ? 'bg-light' : 'bg-white'"
                            @click="globalView = 'table'"
                            title="{{ __('actions.table_view') }}">
                        <i class="ti ti-list fs-14 text-body"></i>
                    </button>
                    <button type="button"
                            class="rounded p-1 d-flex align-items-center justify-content-center border-0"
                            :class="globalView === 'cards' ? 'bg-light' : 'bg-white'"
                            @click="globalView = 'cards'"
                            title="{{ __('actions.card_view') }}">
                        <i class="ti ti-layout-grid fs-14 text-body"></i>
                    </button>
                </div>
            </div>
        </div>

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
                                       x-model="globalSearch"
                                       @input.debounce.300ms="globalResetPage()">
                                <button class="btn btn-outline-secondary border-start-0" type="button"
                                        x-show="globalSearch"
                                        @click="globalSearch=''; globalResetPage()">
                                    <i class="ti ti-x fs-12"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    @isset($categories)
                    <select class="form-select form-select-sm" style="width:auto"
                            x-model="globalCategoryFilter"
                            @change="globalResetPage()">
                        <option value="">{{ __('actions.report_settings.all_categories') }}</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @endisset
                </div>
            </div>
        </div>

        <div x-show="globalView === 'table'" x-cloak>
            <div class="table-responsive">
                <table class="table table-nowrap">
                    <thead>
                        <tr>
                            <th>{{ __('actions.name') }}</th>
                            <th>{{ __('actions.category') }}</th>
                            <th>{{ __('actions.type') }}</th>
                            <th>{{ __('actions.version') }}</th>
                            <th class="text-end">{{ __('actions.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="gt in pagedGlobalTemplates" :key="`global-table-${gt.id}`">
                            <tr>
                                <td>
                                    <div class="fw-medium" x-text="gt.title"></div>
                                    <small class="text-muted" x-text="truncate(gt.description, 100)"></small>
                                </td>
                                <td x-text="gt.category || '-'"></td>
                                <td x-text="gt.paper_size || '-'"></td>
                                <td x-text="gt.version || '-'"></td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <button type="button"
                                                class="btn btn-outline-secondary btn-sm"
                                                @click="openGlobalTemplatePreview(gt.preview_url)">
                                            <i class="ti ti-eye me-1"></i>{{ __('actions.view') }}
                                        </button>
                                        <form :action="gt.adopt_url" method="POST"
                                              onsubmit="return confirm('{{ __('actions.report_settings.confirm_adopt') }}')">
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="ti ti-download me-1"></i>{{ __('actions.report_settings.adopt') }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="pagedGlobalTemplates.length === 0">
                            <td colspan="5" class="text-center py-4 text-muted">
                                {{ __('actions.report_settings.empty') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div x-show="globalView === 'cards'" x-cloak>
            <div class="row g-3" x-show="pagedGlobalTemplates.length > 0">
                <template x-for="gt in pagedGlobalTemplates" :key="`global-card-${gt.id}`">
                    <div class="col-12 col-sm-6 col-xl-4">
                        <div class="card h-100 shadow-sm border-0 border-start border-3 border-primary">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <h6 class="card-title mb-0 flex-grow-1" x-text="gt.title"></h6>
                                    <span class="badge badge-soft-primary text-primary border border-primary fs-12">
                                        v<span x-text="gt.version"></span>
                                    </span>
                                </div>
                                <div class="text-muted small mb-2">
                                    <i class="ti ti-file me-1"></i><span x-text="gt.paper_size || '-'"></span>
                                    <span class="ms-2" x-show="gt.category">
                                        <i class="ti ti-folder me-1"></i><span x-text="gt.category"></span>
                                    </span>
                                </div>
                                <p class="text-muted small mb-2" x-show="gt.description" x-text="truncate(gt.description, 100)"></p>
                                <div class="d-flex flex-wrap gap-1 mb-0">
                                    <span :class="gt.show_header
                                            ? 'badge badge-soft-primary text-primary border border-primary'
                                            : 'badge bg-light text-muted'">
                                        <i class="ti ti-heading me-1"></i>{{ __('actions.report_settings.header') }}
                                    </span>
                                    <span :class="gt.show_signature
                                            ? 'badge badge-soft-primary text-primary border border-primary'
                                            : 'badge bg-light text-muted'">
                                        <i class="ti ti-signature me-1"></i>{{ __('actions.report_settings.signature') }}
                                    </span>
                                    <span :class="gt.show_footer
                                            ? 'badge badge-soft-primary text-primary border border-primary'
                                            : 'badge bg-light text-muted'">
                                        <i class="ti ti-layout-bottombar me-1"></i>{{ __('actions.report_settings.footer') }}
                                    </span>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-top-0 d-flex gap-2 justify-content-end">
                                <button type="button"
                                        class="btn btn-outline-secondary btn-sm"
                                        @click="openGlobalTemplatePreview(gt.preview_url)">
                                    <i class="ti ti-eye me-1"></i>{{ __('actions.view') }}
                                </button>
                                <form :action="gt.adopt_url" method="POST"
                                      onsubmit="return confirm('{{ __('actions.report_settings.confirm_adopt') }}')">
                                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="ti ti-download me-1"></i>{{ __('actions.report_settings.adopt') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="pagedGlobalTemplates.length === 0" class="text-center py-5 text-muted">
                <i class="ti ti-file-off fs-1 mb-3 d-block"></i>
                <p>{{ __('actions.report_settings.empty') }}</p>
            </div>
        </div>

        <nav x-show="filteredGlobalTemplates.length > globalPerPage" class="d-flex justify-content-center mt-3">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ 'disabled': globalPage === 1 }">
                    <button class="page-link" @click="setGlobalPage(globalPage - 1)">
                        <i class="ti ti-arrow-left text-body"></i>
                    </button>
                </li>
                <template x-for="page in globalLastPage" :key="`global-page-${page}`">
                    <li class="page-item" :class="{ 'active': page === globalPage }">
                        <button class="page-link" x-text="page" @click="setGlobalPage(page)"></button>
                    </li>
                </template>
                <li class="page-item" :class="{ 'disabled': globalPage === globalLastPage }">
                    <button class="page-link" @click="setGlobalPage(globalPage + 1)">
                        <i class="ti ti-arrow-right"></i>
                    </button>
                </li>
            </ul>
        </nav>

    </div>
    @endif
    {{-- ══ /Global Templates Available ══════════════════════════════════════════ --}}

    {{-- ══ Global Template Preview Modal ═══════════════════════════════════════ --}}
    <div class="modal fade" id="globalTemplatePreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="ti ti-file-search me-2"></i>{{ __('actions.preview') }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('actions.close') }}"></button>
                </div>
                <div class="modal-body">
                    <div x-show="previewLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="text-muted small mt-2">{{ __('actions.loading') }}...</div>
                    </div>

                    <div x-show="!previewLoading && previewError" class="alert alert-danger" x-text="previewError"></div>

                    <template x-if="!previewLoading && !previewError && previewTemplate">
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <h5 class="mb-0" x-text="previewTemplate.title"></h5>
                                <span class="badge badge-soft-primary text-primary border border-primary"
                                      x-show="previewTemplate.version">
                                    v<span x-text="previewTemplate.version"></span>
                                </span>
                            </div>

                            <div class="text-muted small mb-3">
                                <span><i class="ti ti-file me-1"></i><span x-text="previewTemplate.paper_size || '-'"></span></span>
                                <span class="ms-3" x-show="previewTemplate.category">
                                    <i class="ti ti-folder me-1"></i><span x-text="previewTemplate.category"></span>
                                </span>
                            </div>

                            <p class="text-muted" x-show="previewTemplate.description" x-text="previewTemplate.description"></p>

                            <div class="vstack gap-3" x-show="previewTemplate.contents && previewTemplate.contents.length > 0">
                                <template x-for="content in previewTemplate.contents" :key="content.id">
                                    <div class="border rounded">
                                        <div class="px-3 py-2 border-bottom bg-light d-flex align-items-center justify-content-between gap-2">
                                            <strong class="text-body" x-text="content.label"></strong>
                                            <span class="badge bg-white border text-muted text-uppercase" x-text="content.type"></span>
                                        </div>
                                        <div class="p-3">
                                            <div x-html="content.content"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div class="text-center text-muted py-4" x-show="!previewTemplate.contents || previewTemplate.contents.length === 0">
                                {{ __('actions.report_settings.empty') }}
                            </div>
                        </div>
                    </template>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('actions.close') }}</button>
                </div>
            </div>
        </div>
    </div>
    {{-- ══ /Global Template Preview Modal ══════════════════════════════════════ --}}

</div>{{-- /settingViewToggle --}}

@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
@endsection
