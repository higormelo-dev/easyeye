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

    <div class="row mb-3 align-items-center">
        <div class="col-12 col-md-auto">
            <button type="button"
                    class="btn btn-info btn-sm"
                    @click="$dispatch('open-create-setting')">
                <i class="fa fa-plus"></i> {{ __('actions.new') }}
            </button>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <h5 class="card-header">{{ $meta['action'] }}</h5>
                <div class="card-body">
                    <div class="row">
                        <div class="col">
                            <div class="table-responsive">
                                {{ $dataTable->table(['class' => 'table table-striped']) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    @vite([$jsFile])
@endsection
