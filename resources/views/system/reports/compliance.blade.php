@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
<div class="row g-4">

    {{-- Exportar Log de Auditoria (CFM) --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4 d-flex flex-column gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-danger bg-opacity-10 p-3">
                        <i class="fas fa-shield-alt fa-2x text-danger"></i>
                    </div>
                    <div>
                        <h5 class="mb-1">Log de Auditoria <span class="badge bg-danger-subtle text-danger small">CFM</span></h5>
                        <p class="text-muted small mb-0">
                            Histórico de criação, alteração e exclusão de registros clínicos.
                            Atende à <strong>Resolução CFM 2.227/2018</strong>.
                        </p>
                    </div>
                </div>

                <form action="{{ route('panel.reports.compliance.audit') }}" method="GET"
                      class="d-flex flex-column gap-2 mt-auto">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">De</label>
                            <input type="date" name="date_from" class="form-control form-control-sm"
                                   value="{{ now()->startOfMonth()->format('Y-m-d') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Até</label>
                            <input type="date" name="date_until" class="form-control form-control-sm"
                                   value="{{ now()->format('Y-m-d') }}" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-download me-1"></i> Exportar CSV
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Exportar Log de Acesso a Dados (LGPD) --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4 d-flex flex-column gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                        <i class="fas fa-lock fa-2x text-primary"></i>
                    </div>
                    <div>
                        <h5 class="mb-1">Log de Acesso a Dados <span class="badge bg-primary-subtle text-primary small">LGPD</span></h5>
                        <p class="text-muted small mb-0">
                            Registro de leitura de prontuários e dados sensíveis.
                            Atende ao <strong>Art. 37 da LGPD</strong>.
                        </p>
                    </div>
                </div>

                <form action="{{ route('panel.reports.compliance.access') }}" method="GET"
                      class="d-flex flex-column gap-2 mt-auto">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">De</label>
                            <input type="date" name="date_from" class="form-control form-control-sm"
                                   value="{{ now()->startOfMonth()->format('Y-m-d') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Até</label>
                            <input type="date" name="date_until" class="form-control form-control-sm"
                                   value="{{ now()->format('Y-m-d') }}" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-download me-1"></i> Exportar CSV
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Informativo legal --}}
    <div class="col-12">
        <div class="alert alert-info border-0 d-flex gap-3 align-items-start mb-0" role="alert">
            <i class="fas fa-info-circle mt-1 flex-shrink-0"></i>
            <div class="small">
                <strong>Sobre estes logs</strong><br>
                Os registros são gerados automaticamente pelo sistema e possuem integridade garantida —
                não podem ser editados ou excluídos via interface. Os arquivos exportados (.csv) podem ser
                apresentados ao <strong>CFM em fiscalizações clínicas</strong> e ao DPO/ANPD em
                <strong>auditorias de privacidade</strong>, atendendo à Resolução CFM 2.227/2018 e ao
                Art. 37 da Lei 13.709/2018 (LGPD).
            </div>
        </div>
    </div>

</div>
@endsection
