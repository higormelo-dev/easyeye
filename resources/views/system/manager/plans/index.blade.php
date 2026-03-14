@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
<div x-data="crudForm({
    storeUrl: '{{ $storeUrl }}',
    updateUrl: null,
    fields: {
        name: '', description: '', price: '', billing_cycle: 'monthly',
        active: true, sort_order: 0,
        features: {
            @foreach($features as $f)
            '{{ $f->value }}': '',
            @endforeach
        }
    },
    onSuccess: () => window.dispatchEvent(new CustomEvent('plan-saved'))
})"
     x-init="$nextTick(() => document.getElementById('planModal').addEventListener('hidden.bs.modal', () => reset()))"
     @open-create-plan.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('planModal')).show()"
     @edit-plan.window="loadAndEdit(
         '{{ $baseUrl }}' + '/' + $event.detail.id,
         '{{ $baseUrl }}' + '/' + $event.detail.id,
         'planModal'
     )">

    {{-- Modal --}}
    <x-crud-modal id="planModal" title="Plano">
        <x-slot:body>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Nome <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" :class="{'is-invalid': hasError('name')}"
                           x-model="form.name" maxlength="100" autocomplete="off">
                    <div class="invalid-feedback" x-text="firstError('name')"></div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Ordem</label>
                    <input type="number" class="form-control" x-model="form.sort_order" min="0">
                </div>

                <div class="col-12">
                    <label class="form-label">Descrição</label>
                    <textarea class="form-control" x-model="form.description" rows="2" maxlength="500"></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Preço (R$)</label>
                    <input type="number" class="form-control" x-model="form.price" step="0.01" min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Ciclo de Cobrança <span class="text-danger">*</span></label>
                    <select class="form-select" x-model="form.billing_cycle">
                        @foreach($billingCycles as $cycle)
                            <option value="{{ $cycle->value }}">{{ $cycle->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4" x-show="editing">
                    <label class="form-label">Status</label>
                    <select class="form-select" x-model="form.active">
                        <option :value="true">Ativo</option>
                        <option :value="false">Inativo</option>
                    </select>
                </div>

                <div class="col-12">
                    <hr><h6 class="text-muted">Limites e Features</h6>
                    <div class="alert alert-info alert-sm py-2 mb-2" role="alert">
                        <i class="fas fa-info-circle me-1"></i>
                        Para limites numéricos, <strong>0 significa ilimitado</strong>. Features booleanas indicam se o recurso está disponível no plano.
                    </div>
                </div>

                @foreach($features as $feature)
                <div class="col-md-4">
                    <label class="form-label">{{ $feature->label() }}</label>
                    @if($feature->isBoolean())
                    <select class="form-select" x-model="form.features['{{ $feature->value }}']">
                        <option value="0">Não</option>
                        <option value="1">Sim</option>
                    </select>
                    @else
                    <input type="number" class="form-control"
                           x-model="form.features['{{ $feature->value }}']"
                           min="0">
                    @endif
                </div>
                @endforeach
            </div>
        </x-slot:body>
    </x-crud-modal>

    {{-- Toolbar --}}
    <div class="row mb-3">
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-info btn-sm" @click="$dispatch('open-create-plan')">
                <i class="fa fa-plus me-1"></i> {{ __('actions.new') }}
            </button>
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
            window.dispatchEvent(new CustomEvent('edit-plan', { detail: { id: $(this).data('id') } }));
        });

        $(document).on('click', '.btn-show', function () {
            const id = $(this).data('id');
            $('.modal-title-default').text('{{ __("actions.messages.view", ["name" => __("actions.plan")]) }}');
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
                method: 'PATCH',
                url: baseUrl + '/' + id,
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: { active: situation },
                success: function (res) {
                    if (window.showSuccessToast) showSuccessToast(res.message);
                    window.dispatchEvent(new CustomEvent('plan-saved'));
                },
                error: function (res) {
                    if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                }
            });
        });

        $(document).on('click', '.btn-trash', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Remover plano?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(r => {
                if (!r.isConfirmed) return;
                $.ajax({
                    method: 'DELETE',
                    url: baseUrl + '/' + id,
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (res) {
                        if (window.showSuccessToast) showSuccessToast(res.message);
                        window.dispatchEvent(new CustomEvent('plan-saved'));
                    },
                    error: function (res) {
                        if (window.showErrorToast) showErrorToast(res.responseJSON?.message);
                    }
                });
            });
        });

        window.addEventListener('plan-saved', () => {
            if (window.LaravelDataTables && window.LaravelDataTables['plans_datatable']) {
                window.LaravelDataTables['plans_datatable'].ajax.reload(null, false);
            }
        });
    });
    </script>
@endsection
