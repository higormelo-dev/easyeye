@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div x-data="crudForm({
        storeUrl:  '{{ route('panel.manager.entities.user-integrators.integrators.store', [$entity->id, $userIntegrator->id]) }}',
        updateUrl: null,
        fields: { name: '', ip: '', mac: '', active: true },
        onSuccess: () => window.dispatchEvent(new CustomEvent('integrator-saved'))
    })"
     x-init="$nextTick(() => document.getElementById('integratorModal').addEventListener('hidden.bs.modal', () => reset()))"
     @open-create-integrator.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('integratorModal')).show()"
     @edit-integrator.window="loadAndEdit(
         '{{ url('panel/manager/entities/' . $entity->id . '/user-integrators/' . $userIntegrator->id . '/integrators') }}' + '/' + $event.detail.id + '/edit-data',
         '{{ url('panel/manager/entities/' . $entity->id . '/user-integrators/' . $userIntegrator->id . '/integrators') }}' + '/' + $event.detail.id,
         'integratorModal'
     )">

    @include('system.manager.entity_integrators._form-modal')

    <div class="row mb-3 align-items-center">
        <div class="col-12 col-md-auto">
            <div class="btn-group" role="group">
                <a href="{{ route('panel.manager.entities.user-integrators.index', $entity->id) }}" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> {{ __('actions.back') }}
                </a>
                <button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-create-integrator')">
                    <i class="fa fa-plus"></i> {{ __('actions.new') }}
                </button>
            </div>
        </div>
    </div>

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

@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    <script>
    $(function () {
        const entityBaseUrl = '{{ url('panel/manager/entities/' . $entity->id . '/user-integrators/' . $userIntegrator->id . '/integrators') }}';

        window.addEventListener('integrator-saved', () => {
            if (window.LaravelDataTables && window.LaravelDataTables['integrators_datatable']) {
                window.LaravelDataTables['integrators_datatable'].ajax.reload(null, false);
            }
        });

        $(document).on('click', '.btn-edit', function () {
            window.dispatchEvent(new CustomEvent('edit-integrator', { detail: { id: $(this).data('id') } }));
        });

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => __("actions.integrator")]) }}');
            $('#btn-modal-default').hide();
            $('#erro-default').hide();
            $.ajax({
                url: entityBaseUrl + '/' + id,
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
                url: entityBaseUrl + '/' + id + '/activate',
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: { active: situation },
                success: function (res) {
                    if (window.showSuccessToast) showSuccessToast(res.message);
                    window.dispatchEvent(new CustomEvent('integrator-saved'));
                },
                error: function (res) {
                    if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                }
            });
        });

        $(document).on('click', '.btn-trash', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: '{{ __("actions.messages.delete_confirm_title") }}',
                text: '{{ __("actions.messages.delete_confirm_text") }}',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: '{{ __("actions.messages.confirm_yes") }}',
                cancelButtonText: '{{ __("actions.messages.confirm_no") }}'
            }).then(result => {
                if (!result.isConfirmed) return;
                $.ajax({
                    method: 'DELETE',
                    url: entityBaseUrl + '/' + id,
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        window.dispatchEvent(new CustomEvent('integrator-saved'));
                    },
                    error: function (res) {
                        if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                    }
                });
            });
        });

        $(document).on('click', '.btn-restore', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: '{{ __("actions.messages.restore_confirm_title") }}',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '{{ __("actions.messages.confirm_yes") }}',
                cancelButtonText: '{{ __("actions.messages.confirm_no") }}'
            }).then(result => {
                if (!result.isConfirmed) return;
                $.ajax({
                    method: 'PUT',
                    url: entityBaseUrl + '/' + id + '/restore',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        window.dispatchEvent(new CustomEvent('integrator-saved'));
                    },
                    error: function (res) {
                        if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                    }
                });
            });
        });
    });
    </script>
@endsection
