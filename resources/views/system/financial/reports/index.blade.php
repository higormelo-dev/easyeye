@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body d-flex flex-column align-items-start gap-3 p-4">
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                        <i class="ti ti-chart-line text-primary fs-24"></i>
                    </div>
                    <div>
                        <h5 class="card-title mb-1">Fluxo de Caixa</h5>
                        <p class="card-text text-muted small mb-0">
                            Receitas, despesas, saldo por período, composição por categoria e evolução diária.
                        </p>
                    </div>
                    <a href="{{ route('panel.financial.reports.cash-flow') }}" class="btn btn-primary btn-sm mt-auto">
                        <i class="ti ti-arrow-right me-1"></i> Acessar relatório
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body d-flex flex-column align-items-start gap-3 p-4">
                    <div class="rounded-circle bg-success bg-opacity-10 p-3">
                        <i class="ti ti-file-invoice text-success fs-24"></i>
                    </div>
                    <div>
                        <h5 class="card-title mb-1">Faturamento Convênios</h5>
                        <p class="card-text text-muted small mb-0">
                            Volume faturado por convênio, pagamentos, glosas e detalhamento das guias TISS.
                        </p>
                    </div>
                    <a href="{{ route('panel.financial.reports.covenants') }}" class="btn btn-success btn-sm mt-auto">
                        <i class="ti ti-arrow-right me-1"></i> Acessar relatório
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection

