@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <h5 class="card-header">{{ $meta['action'] }}</h5>
                <div class="card-body">
                    <div class="row">
                        <div class="col">
                            <div class="table-responsive">
                                <table id="record_datatable" class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>{{ __('actions.created_at') }}</th>
                                            <th>Equipamento</th>
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