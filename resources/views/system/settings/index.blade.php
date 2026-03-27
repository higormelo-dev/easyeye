@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div x-data="crudForm({
        storeUrl:  @js($storeUrl),
        updateUrl: null,
        fields:    @js($crudFields),
        onSuccess: () => window.dispatchEvent(new CustomEvent('setting-saved'))
    })"
     x-init="$nextTick(() => document.getElementById('settingModal').addEventListener('hidden.bs.modal', () => reset()))"
     @open-create-setting.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('settingModal')).show()"
     @edit-setting.window="loadAndEdit(
         @js($baseUrl) + '/' + $event.detail.id,
         @js($baseUrl) + '/' + $event.detail.id,
         'settingModal'
     )">

    @include('system.settings._form-modal')

    <div x-data="{ search: '' }">

        {{-- ══ Page Header ══════════════════════════════════════════════════════ --}}
        <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-1 border-bottom">
            <div class="flex-grow-1">
                <h4 class="fw-bold mb-0">{{ $meta['title'] }}</h4>
            </div>
            <div class="d-flex align-items-center gap-2">
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
                                   x-on:input.debounce.400ms="Object.values(window.LaravelDataTables ?? {})[0]?.search(search).draw()">
                            <button class="btn btn-outline-secondary border-start-0" type="button"
                                    x-show="search"
                                    x-on:click="search = ''; Object.values(window.LaravelDataTables ?? {})[0]?.search('').draw()">
                                <i class="ti ti-x fs-12"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        {{-- ══ /Filter Bar ══════════════════════════════════════════════════════ --}}

        {{-- ══ DataTable ════════════════════════════════════════════════════════ --}}
        <div class="table-responsive">
            {{ $dataTable->table(['class' => 'table table-nowrap']) }}
        </div>
        {{-- ══ /DataTable ═══════════════════════════════════════════════════════ --}}

    </div>{{-- /search wrapper --}}

</div>{{-- /crudForm --}}

@endsection

@section('modals')
    @includeIf('system.settings._partials.' . $viewSlot . '.modals')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    @vite([$jsFile])
@endsection
