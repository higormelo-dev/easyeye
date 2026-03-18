@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- Solicitações pendentes --}}
@php
    $pending = \App\Models\TvDisplay::where('entity_id', $entityId)
        ->where('status', 'pending')
        ->whereNull('deleted_at')
        ->orderBy('created_at')
        ->get();
@endphp

@if($pending->isNotEmpty())
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-warning">
            <h5 class="card-header bg-warning text-dark">
                <i class="fas fa-clock me-2"></i>
                Aguardando aprovação
                <span class="badge bg-dark ms-2">{{ $pending->count() }}</span>
            </h5>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Solicitação</th>
                            <th>PIN</th>
                            <th>Aguardando desde</th>
                            <th class="text-end pe-3">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pending as $p)
                        <tr>
                            <td class="ps-3 text-muted small">{{ $p->id }}</td>
                            <td>
                                <span class="fw-bold fs-5 text-warning font-monospace" style="letter-spacing:.2em;">
                                    {{ $p->pin }}
                                </span>
                            </td>
                            <td class="text-muted small">{{ $p->created_at->diffForHumans() }}</td>
                            <td class="text-end pe-3">
                                <button type="button"
                                        class="btn btn-success btn-sm btn-approve"
                                        data-id="{{ $p->id }}">
                                    <i class="fas fa-check me-1"></i> Aprovar
                                </button>
                                <button type="button"
                                        class="btn btn-danger btn-sm btn-trash ms-1"
                                        data-id="{{ $p->id }}">
                                    <i class="fas fa-times"></i>
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

<div class="row mb-3 align-items-center">
    <div class="col-12 col-md-auto">
        <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#createTvModal">
            <i class="fa fa-plus"></i> Novo Display
        </button>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <h5 class="card-header">{{ $meta['action'] }}</h5>
            <div class="card-body">
                <div class="row">
                    <div class="col">
                        <div class="table-responsive">
                            {{ $dataTable->table(['class' => 'table table-striped']) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Approve Modal --}}
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="approveForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2 text-success"></i> Aprovar Display
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Dê um nome para identificar esta TV no sistema.</p>
                    <div class="mb-0">
                        <label class="form-label">Nome do display <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="approveName" name="name"
                               placeholder="Ex: TV Recepção" maxlength="100" required>
                        <div class="invalid-feedback" id="approveNameError"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="approveSubmit">
                        <i class="fas fa-check me-1"></i> Aprovar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Create Modal --}}
<div class="modal fade" id="createTvModal" tabindex="-1" aria-labelledby="createTvModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="createTvForm">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="createTvModalLabel">
                        <i class="fas fa-tv me-2"></i> Novo Display de TV
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="tvName" class="form-label">Nome do display <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="tvName" name="name"
                               placeholder="Ex: TV Recepção" maxlength="100" required>
                        <div class="invalid-feedback" id="tvNameError"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info" id="createTvSubmit">
                        <i class="fas fa-save me-1"></i> Criar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- QR Code Modal --}}
<div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="qrModalLabel">
                    <i class="fas fa-qrcode me-2"></i> QR Code &mdash; <span id="qrDisplayName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <canvas id="qrCanvas" style="max-width:100%;"></canvas>
                <div class="mt-3">
                    <p class="text-muted small mb-1">URL do display:</p>
                    <a id="qrUrl" href="#" target="_blank" class="text-break small"></a>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

@endsection

@section('javascript')
    {{ $dataTable->scripts() }}
    @vite(['resources/js/system/tv-displays.js'])

    <script>
        const CSRF = '{{ csrf_token() }}';
        const TV_BASE_URL = '{{ url('panel/setting/tv-displays') }}';
        let pendingApproveId = null;

        $(document).ready(function () {

            // ── Approve button (pending table) ───────────────────────────────
            $(document).on('click', '.btn-approve', function () {
                pendingApproveId = $(this).data('id');
                $('#approveName').val('').removeClass('is-invalid');
                $('#approveNameError').text('');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('approveModal')).show();
            });

            $('#approveForm').on('submit', function (e) {
                e.preventDefault();
                if (!pendingApproveId) return;

                var $btn  = $('#approveSubmit');
                var name  = $('#approveName').val().trim();
                $btn.prop('disabled', true);
                $('#approveName').removeClass('is-invalid');

                $.ajax({
                    url:  TV_BASE_URL + '/' + pendingApproveId + '/approve',
                    type: 'POST',
                    data: { _token: CSRF, name: name },
                    success: function () {
                        bootstrap.Modal.getInstance(document.getElementById('approveModal')).hide();
                        location.reload();
                    },
                    error: function (xhr) {
                        if (xhr.status === 422 && xhr.responseJSON?.errors?.name) {
                            $('#approveName').addClass('is-invalid');
                            $('#approveNameError').text(xhr.responseJSON.errors.name[0]);
                        } else {
                            Swal.fire('Erro', 'Não foi possível aprovar o display.', 'error');
                        }
                    },
                    complete: function () { $btn.prop('disabled', false); },
                });
            });

            // ── Create form ──────────────────────────────────────────────────
            $('#createTvForm').on('submit', function (e) {
                e.preventDefault();
                var $btn = $('#createTvSubmit');
                $btn.prop('disabled', true);
                $('#tvName').removeClass('is-invalid');

                $.ajax({
                    url:  '{{ route('panel.setting.tv-displays.store') }}',
                    type: 'POST',
                    data: { _token: CSRF, name: $('#tvName').val() },
                    success: function (response) {
                        bootstrap.Modal.getInstance(document.getElementById('createTvModal')).hide();
                        $('#createTvForm')[0].reset();
                        $('#tv_displays_datatable').DataTable().ajax.reload();
                        Swal.fire({ icon: 'success', title: 'Display criado!', text: response.message, timer: 2500, showConfirmButton: false });
                    },
                    error: function (xhr) {
                        if (xhr.status === 422 && xhr.responseJSON?.errors?.name) {
                            $('#tvName').addClass('is-invalid');
                            $('#tvNameError').text(xhr.responseJSON.errors.name[0]);
                        } else {
                            Swal.fire('Erro', 'Ocorreu um erro ao criar o display.', 'error');
                        }
                    },
                    complete: function () { $btn.prop('disabled', false); },
                });
            });

            // ── Delete ───────────────────────────────────────────────────────
            $(document).on('click', '.btn-trash', function () {
                var id = $(this).data('id');
                Swal.fire({
                    title: window.translations.messages.delete_confirm_title,
                    text: window.translations.messages.delete_confirm_text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: window.translations.messages.confirm_yes,
                    cancelButtonText: window.translations.messages.confirm_no,
                }).then(function (result) {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: TV_BASE_URL + '/' + id,
                            type: 'DELETE',
                            data: { _token: CSRF },
                            success: function (response) {
                                location.reload();
                                Swal.fire({ icon: 'success', title: 'Removido!', text: response.message, timer: 2000, showConfirmButton: false });
                            },
                            error: function () { Swal.fire('Erro', 'Não foi possível remover o display.', 'error'); },
                        });
                    }
                });
            });

            // ── Copy link ────────────────────────────────────────────────────
            $(document).on('click', '.btn-copy-link', function () {
                var url = $(this).data('url');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(function () {
                        Swal.fire({ icon: 'success', title: 'Link copiado!', timer: 1500, showConfirmButton: false });
                    });
                } else {
                    var $tmp = $('<input>').val(url).appendTo('body').select();
                    document.execCommand('copy');
                    $tmp.remove();
                    Swal.fire({ icon: 'success', title: 'Link copiado!', timer: 1500, showConfirmButton: false });
                }
            });

            // ── QR Code ──────────────────────────────────────────────────────
            $(document).on('click', '.btn-qr', function () {
                var url  = $(this).data('url');
                var name = $(this).data('name');
                $('#qrDisplayName').text(name);
                $('#qrUrl').attr('href', url).text(url);

                var qrModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('qrModal'));
                qrModal.show();

                document.getElementById('qrModal').addEventListener('shown.bs.modal', function generateQr() {
                    if (typeof window.QRCode !== 'undefined') {
                        window.QRCode.toCanvas(document.getElementById('qrCanvas'), url, { width: 280 }, function (error) {
                            if (error) console.error('QR error:', error);
                        });
                    }
                    this.removeEventListener('shown.bs.modal', generateQr);
                });
            });

            // ── Reset create modal ───────────────────────────────────────────
            document.getElementById('createTvModal').addEventListener('hidden.bs.modal', function () {
                $('#createTvForm')[0].reset();
                $('#tvName').removeClass('is-invalid');
                $('#tvNameError').text('');
            });

            // ── Auto-refresh pending section every 10s ───────────────────────
            @if($pending->isNotEmpty())
            setInterval(function () {
                if (!document.querySelector('.modal.show')) {
                    location.reload();
                }
            }, 10000);
            @endif
        });
    </script>
@endsection
