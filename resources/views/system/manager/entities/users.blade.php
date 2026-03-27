@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div class="row mb-3 align-items-center">
    <div class="col-12 col-md-auto">
        <a href="{{ route('panel.manager.entities.index') }}" class="btn btn-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> {{ __('actions.back') }}
        </a>
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

@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    <script>
    $(function () {
        $(document).on('click', '.btn-impersonate', function () {
            const url  = $(this).data('url');
            const name = $(this).data('name');

            Swal.fire({
                title: '{{ __("actions.impersonate.use_as") }}',
                text: name,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '{{ __("actions.messages.confirm_yes") }}',
                cancelButtonText: '{{ __("actions.messages.confirm_no") }}'
            }).then(result => {
                if (!result.isConfirmed) return;

                const form = $('<form method="POST">').attr('action', url);
                form.append($('<input type="hidden" name="_token">').val($('meta[name="csrf-token"]').attr('content')));
                $('body').append(form);
                form.submit();
            });
        });
    });
    </script>
@endsection
