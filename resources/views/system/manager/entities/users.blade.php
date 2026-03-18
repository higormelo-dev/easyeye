@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

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
