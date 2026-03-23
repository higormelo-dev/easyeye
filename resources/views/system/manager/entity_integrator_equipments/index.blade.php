@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

    <div class="row mb-3 align-items-center">
        <div class="col-12 col-md-auto">
            <div class="btn-group" role="group">
                <a href="{{ route('panel.manager.entities.user-integrators.integrators.index', [$entity->id, $userIntegrator->id]) }}" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> {{ __('actions.back') }}
                </a>
            </div>
        </div>
    </div>

    <div class="card">
        <h5 class="card-header">{{ $meta['action'] }}</h5>
        <div class="card-body">
            <div class="table-responsive">
                {{ $dataTable->table() }}
            </div>
        </div>
    </div>

@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    <script>
    $(function () {
        const baseUrl = '{{ url('panel/manager/entities/' . $entity->id . '/user-integrators/' . $userIntegrator->id . '/integrators/' . $integrator->id . '/equipments') }}';

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => __("actions.equipments")]) }}');
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
    });
    </script>
@endsection