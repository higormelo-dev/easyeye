@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div x-data="crudForm({
        storeUrl:  '{{ $storeUrl }}',
        updateUrl: null,
        fields: {
            name: '', subdomain: '', email: '', telephone: '', cellphone: '',
            national_registration: '', state_registration: '', municipal_registration: '',
            website: '', active: true,
            zipcode: '', address: '', number: '', complement: '',
            district: '', city: '', state: '', country: ''
        },
        onSuccess: () => window.dispatchEvent(new CustomEvent('entity-saved'))
    })"
     x-init="$nextTick(() => document.getElementById('entityModal').addEventListener('hidden.bs.modal', () => reset()))"
     @open-create-entity.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('entityModal')).show()"
     @edit-entity.window="loadAndEdit(
         '{{ url('panel/manager/entities') }}' + '/' + $event.detail.id + '/edit-data',
         '{{ url('panel/manager/entities') }}' + '/' + $event.detail.id,
         'entityModal'
     )">

    @include('system.manager.entities._form-modal')

    <div class="row mb-3 align-items-center">
        <div class="col-12 col-md-auto">
            <button type="button" class="btn btn-info btn-sm" @click="$dispatch('open-create-entity')">
                <i class="fa fa-plus"></i> {{ __('actions.new') }}
            </button>
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

</div>

@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    <script>
    $(function () {
        const baseUrl = '{{ url('panel/manager/entities') }}';

        $(document).on('click', '.btn-edit', function () {
            window.dispatchEvent(new CustomEvent('edit-entity', { detail: { id: $(this).data('id') } }));
        });

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => __("actions.entity")]) }}');
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

        $(document).on('click', '.btn-active', function () {
            const id        = $(this).data('id');
            const situation = $(this).data('situation');
            $.ajax({
                method: 'PUT',
                url: baseUrl + '/' + id,
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: { active: situation },
                success: function (res) {
                    if (window.showSuccessToast) showSuccessToast(res.message);
                    window.dispatchEvent(new CustomEvent('entity-saved'));
                },
                error: function (res) {
                    if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                }
            });
        });

        $(document).on('click', '.btn-trash', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Deletar empresa?',
                text: 'Esta ação não poderá ser desfeita.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(result => {
                if (!result.isConfirmed) return;
                $.ajax({
                    method: 'DELETE',
                    url: baseUrl + '/' + id,
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        window.dispatchEvent(new CustomEvent('entity-saved'));
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
                title: 'Restaurar empresa?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(result => {
                if (!result.isConfirmed) return;
                $.ajax({
                    method: 'PUT',
                    url: baseUrl + '/' + id + '/restore',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        window.dispatchEvent(new CustomEvent('entity-saved'));
                    },
                    error: function (res) {
                        if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                    }
                });
            });
        });

        window.addEventListener('entity-saved', () => {
            if (window.LaravelDataTables && window.LaravelDataTables['entities_datatable']) {
                window.LaravelDataTables['entities_datatable'].ajax.reload(null, false);
            }
        });
    });
    </script>
@endsection
