@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div x-data="userViewToggle(@js(route('panel.accesscontrol.users.cards')), @js(asset('system/images/team.png')))"
         x-init="init()">

        {{-- Toolbar --}}
        <div class="row mb-3 align-items-center">
            <div class="col-12 col-md-auto">
                @include('components.subnav')
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
                        <div class="card-header">
                            <h4 class="card-title mb-0">{{ $meta['action'] }}</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                {{ $dataTable->table(['class' => 'table table-nowrap']) }}
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
                <div class="row" x-show="users.length > 0">
                    <template x-for="user in users" :key="user.id">
                        <div class="col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6 mb-3">
                            <div class="card card-body h-100">
                                <div class="row align-items-center">
                                    <div class="col-md-4 col-lg-3 text-center">
                                        <img :src="user.photo_url"
                                             :alt="user.full_name"
                                             class="img-fluid rounded-circle"
                                             x-on:error="$el.src = fallbackPhoto">
                                    </div>
                                    <div class="col-md-8 col-lg-9">
                                        <h5 class="mb-1" x-text="user.full_name"></h5>
                                        <div class="mb-2">
                                            <template x-if="user.deleted">
                                                <span class="badge bg-danger">{{ __('actions.delete') }}</span>
                                            </template>
                                            <template x-if="!user.deleted">
                                                <span :class="user.active ? 'badge bg-success' : 'badge bg-secondary'"
                                                      x-text="user.active ? '{{ __("actions.active") }}' : '{{ __("actions.inactive") }}'">
                                                </span>
                                            </template>
                                        </div>
                                        <address class="small text-muted mb-2">
                                            <strong>{{ __('actions.email') }}:</strong>
                                            <span x-text="user.email"></span><br>
                                            <strong>{{ __('actions.rule') }}:</strong>
                                            <span x-text="user.rule_label ?? '{{ __("actions.not_informed") }}'"></span>
                                        </address>
                                        <hr class="my-2">
                                        <div class="d-flex gap-1">
                                            <template x-if="!user.deleted && user.own_entity">
                                                <div class="d-flex gap-1">
                                                    <button class="btn btn-sm btn-light btn-show"
                                                            :data-id="user.id"
                                                            data-bs-toggle="tooltip"
                                                            title="{{ __('actions.view') }}">
                                                        <i class="fa fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-light btn-edit"
                                                            :data-id="user.id"
                                                            data-bs-toggle="tooltip"
                                                            title="{{ __('actions.edit') }}">
                                                        <i class="fa fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-light btn-active"
                                                            :data-id="user.id"
                                                            :data-situation="user.active ? 0 : 1"
                                                            data-bs-toggle="tooltip"
                                                            :title="user.active ? '{{ __("actions.disable") }}' : '{{ __("actions.enable") }}'">
                                                        <i :class="user.active ? 'fas fa-lock-open' : 'fas fa-unlock'"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger btn-trash"
                                                            :data-id="user.id"
                                                            data-bs-toggle="tooltip"
                                                            title="{{ __('actions.delete') }}">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </div>
                                            </template>
                                            <template x-if="user.deleted && user.own_entity">
                                                <button class="btn btn-sm btn-light btn-restore"
                                                        :data-id="user.id"
                                                        data-bs-toggle="tooltip"
                                                        title="{{ __('actions.restore') }}">
                                                    <i class="fas fa-recycle"></i>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Empty state --}}
                <div x-show="users.length === 0 && !loading" class="text-center py-5 text-muted">
                    <i class="fa fa-users fa-3x mb-3"></i>
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

    </div>
@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    @vite(['resources/js/system/users.js'])
@endsection