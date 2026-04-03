@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div x-data="crudForm({
        storeUrl:  '{{ route('panel.manager.entities.user-integrators.store', $entity->id) }}',
        updateUrl: null,
        fields: { name: '', email: '', password: '', active: true },
        onSuccess: () => window.dispatchEvent(new CustomEvent('user-integrator-saved'))
    })"
     x-init="$nextTick(() => document.getElementById('userIntegratorModal').addEventListener('hidden.bs.modal', () => reset()))"
     @open-create-user-integrator.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('userIntegratorModal')).show()"
     @edit-user-integrator.window="loadAndEdit(
         '{{ url('panel/manager/entities/' . $entity->id . '/user-integrators') }}' + '/' + $event.detail.id + '/edit-data',
         '{{ url('panel/manager/entities/' . $entity->id . '/user-integrators') }}' + '/' + $event.detail.id,
         'userIntegratorModal'
     )">

    @include('system.manager.entity_user_integrators._form-modal')

    {{-- ══ Page Header ══════════════════════════════════════════════════════ --}}
    <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-1 border-bottom">
        <div class="flex-grow-1">
            <h4 class="fw-bold mb-0">
                {{ $meta['action'] }}
                <span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13 ms-1">
                    {{ __('actions.total') }}: {{ $meta['total'] }}
                </span>
            </h4>
        </div>
        <div class="btn-group" role="group">
            <a href="{{ route('panel.manager.entities.index') }}" class="btn btn-outline-white fs-13 btn-md">
                <i class="fas fa-arrow-left me-1"></i>{{ __('actions.back') }}
            </a>
            <a href="javascript:void(0);" class="btn btn-primary fs-13 btn-md"
               @click="$dispatch('open-create-user-integrator')">
                <i class="ti ti-plus me-1"></i>{{ __('actions.new') }}
            </a>
        </div>
    </div>
    {{-- ══ /Page Header ═════════════════════════════════════════════════════ --}}

    <div class="table-responsive">
        {{ $dataTable->table(['class' => 'table table-nowrap']) }}
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
        const entityBaseUrl = '{{ url('panel/manager/entities/' . $entity->id . '/user-integrators') }}';

        window.addEventListener('user-integrator-saved', () => {
            if (window.LaravelDataTables && window.LaravelDataTables['user_integrators_datatable']) {
                window.LaravelDataTables['user_integrators_datatable'].ajax.reload(null, false);
            }
        });

        $(document).on('click', '.btn-edit', function () {
            window.dispatchEvent(new CustomEvent('edit-user-integrator', { detail: { id: $(this).data('id') } }));
        });

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => "Usuário Integrador"]) }}');
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
                    window.dispatchEvent(new CustomEvent('user-integrator-saved'));
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
                        window.dispatchEvent(new CustomEvent('user-integrator-saved'));
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
                        window.dispatchEvent(new CustomEvent('user-integrator-saved'));
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
