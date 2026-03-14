@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<div x-data="crudForm({
        storeUrl:  null,
        updateUrl: null,
        fields: {
            plan_id: '', status: '', starts_at: '', ends_at: '', trial_ends_at: ''
        },
        onSuccess: () => window.dispatchEvent(new CustomEvent('subscription-saved'))
    })"
     x-init="$nextTick(() => document.getElementById('subscriptionModal').addEventListener('hidden.bs.modal', () => reset()))"
     @edit-subscription.window="loadAndEdit(
         '{{ $baseUrl }}' + '/' + $event.detail.id,
         '{{ $baseUrl }}' + '/' + $event.detail.id,
         'subscriptionModal'
     )">

    {{-- Modal de edição --}}
    <x-crud-modal id="subscriptionModal" title="Assinatura">
        <x-slot:body>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Plano <span class="text-danger">*</span></label>
                    <select class="form-select" x-model="form.plan_id" :class="{'is-invalid': hasError('plan_id')}">
                        <option value="">-- Selecione --</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback" x-text="firstError('plan_id')"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Status <span class="text-danger">*</span></label>
                    <select class="form-select" x-model="form.status" :class="{'is-invalid': hasError('status')}">
                        @foreach($statuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback" x-text="firstError('status')"></div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Início <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" x-model="form.starts_at"
                           :class="{'is-invalid': hasError('starts_at')}">
                    <div class="invalid-feedback" x-text="firstError('starts_at')"></div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Vencimento</label>
                    <input type="date" class="form-control" x-model="form.ends_at">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Trial até</label>
                    <input type="date" class="form-control" x-model="form.trial_ends_at">
                </div>
            </div>
        </x-slot:body>
    </x-crud-modal>

    {{-- Configurações de Trial e Graça --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold">Configurações de Trial e Graça</div>
        <div class="card-body">
            <form id="settings-form" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Dias de Trial</label>
                    <input type="number" class="form-control" name="trial_days" value="{{ $trialDays }}" min="1" max="365">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Dias de Graça (após expiração)</label>
                    <input type="number" class="form-control" name="grace_period_days" value="{{ $graceDays }}" min="0" max="30">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- DataTable --}}
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
        const baseUrl = '{{ $baseUrl }}';

        $(document).on('click', '.btn-edit', function () {
            window.dispatchEvent(new CustomEvent('edit-subscription', { detail: { id: $(this).data('id') } }));
        });

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => __("actions.subscription")]) }}');
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

        $(document).on('click', '.btn-cancel', function () {
            const entityId = $(this).data('entity-id');
            Swal.fire({
                title: 'Cancelar assinatura?',
                text: 'O acesso será mantido durante o período de graça.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sim, cancelar',
                cancelButtonText: 'Não'
            }).then(r => {
                if (!r.isConfirmed) return;
                $.ajax({
                    method: 'POST',
                    url: baseUrl + '/cancel',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    data: { entity_id: entityId },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        window.dispatchEvent(new CustomEvent('subscription-saved'));
                    },
                    error: function (res) {
                        if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                    }
                });
            });
        });

        $(document).on('click', '.btn-block', function () {
            const entityId  = $(this).data('entity-id');
            const active    = $(this).data('active');
            const isBlocking = active == 0;

            Swal.fire({
                title: isBlocking ? 'Bloquear acesso?' : 'Desbloquear acesso?',
                text: isBlocking
                    ? 'A empresa e todos os seus usuários perderão o acesso ao sistema.'
                    : 'A empresa e todos os seus usuários terão o acesso restaurado.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: isBlocking ? '#dc3545' : '#198754',
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(r => {
                if (!r.isConfirmed) return;
                $.ajax({
                    method: 'PATCH',
                    url: baseUrl + '/block-access',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    data: { entity_id: entityId, active: active },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        window.dispatchEvent(new CustomEvent('subscription-saved'));
                    },
                    error: function (res) {
                        if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                    }
                });
            });
        });

        $('#settings-form').on('submit', function (e) {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(this));
            $.ajax({
                method: 'POST',
                url: baseUrl + '/settings',
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: data,
                success: function (res) {
                    if (window.showSuccessToast) showSuccessToast(res.message);
                },
                error: function (res) {
                    if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                }
            });
        });

        window.addEventListener('subscription-saved', () => {
            if (window.LaravelDataTables && window.LaravelDataTables['subscriptions_datatable']) {
                window.LaravelDataTables['subscriptions_datatable'].ajax.reload(null, false);
            }
        });
    });
    </script>
@endsection
