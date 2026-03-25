@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div class="row g-4">

        {{-- Relatório de Produção --}}
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body d-flex flex-column align-items-start gap-3 p-4">
                    <div class="rounded-circle bg-info bg-opacity-10 p-3">
                        <i class="fas fa-chart-bar fa-2x text-info"></i>
                    </div>
                    <div>
                        <h5 class="card-title mb-1">Relatório de Produção</h5>
                        <p class="card-text text-muted small">
                            Visualize o total de agendamentos por período, médico, convênio e situação.
                            Inclui taxa de comparecimento e breakdown por médico.
                        </p>
                    </div>
                    <a href="{{ route('panel.reports.schedules') }}"
                       class="btn btn-info btn-sm mt-auto">
                        <i class="fas fa-arrow-right me-1"></i> Acessar relatório
                    </a>
                </div>
            </div>
        </div>

        {{-- Relatório de Absenteísmo --}}
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body d-flex flex-column align-items-start gap-3 p-4">
                    <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                        <i class="fas fa-user-times fa-2x text-warning"></i>
                    </div>
                    <div>
                        <h5 class="card-title mb-1">Relatório de Absenteísmo</h5>
                        <p class="card-text text-muted small">
                            Analise faltas e cancelamentos por período e médico.
                            Identifique padrões e calcule a taxa de absenteísmo.
                        </p>
                    </div>
                    <a href="{{ route('panel.reports.absenteeism') }}"
                       class="btn btn-warning btn-sm mt-auto">
                        <i class="fas fa-arrow-right me-1"></i> Acessar relatório
                    </a>
                </div>
            </div>
        </div>

    </div>
@endsection
