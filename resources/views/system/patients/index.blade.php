@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            @include('system.patients.subnav')
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
                                <table id="patient_datatable" class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>{{ __('actions.created_at') }}</th>
                                            <th>{{ __('actions.code') }}</th>
                                            <th>{{ __('actions.patient') }}</th>
                                             <th>{{ __('actions.gender') }}</th>
                                            <th>{{ __('actions.cellphone') }}</th>
                                            <th class="text-center">{{ __('actions.active') }}</th>
                                            <th class="text-center">{{ __('actions.actions') }}</th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    @vite(['resources/js/system/patients.js'])
@endsection