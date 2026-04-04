@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

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
        </div>
    </div>
    {{-- ══ /Page Header ═════════════════════════════════════════════════════ --}}

    {{ $dataTable->table(['class' => 'table table-nowrap']) }}

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
