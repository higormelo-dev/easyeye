@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Período inicial</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Período final</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}">
                </div>
                <div class="col-auto ms-auto">
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="ti ti-filter me-1"></i> Filtrar
                    </button>
                    <a href="{{ route('panel.financial.reports.covenants.export', request()->query()) }}" class="btn btn-outline-secondary btn-sm ms-1">
                        <i class="ti ti-download me-1"></i> Exportar CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total de guias</div>
                    <div class="fs-3 fw-bold">{{ $summary['total_claims'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Valor faturado</div>
                    <div class="fs-3 fw-bold text-primary">R$ {{ number_format($summary['total_amount'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Valor recebido</div>
                    <div class="fs-3 fw-bold text-success">R$ {{ number_format($summary['total_paid'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Glosas</div>
                    <div class="fs-3 fw-bold text-danger">R$ {{ number_format($summary['total_denied'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">Consolidado por convênio</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Convênio</th>
                        <th class="text-center">Guias</th>
                        <th class="text-end">Faturado</th>
                        <th class="text-end">Recebido</th>
                        <th class="text-end">Glosa</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($byCovenant as $row)
                        <tr>
                            <td>{{ $row['covenant'] }}</td>
                            <td class="text-center">{{ $row['claims'] }}</td>
                            <td class="text-end text-primary">R$ {{ number_format($row['amount'], 2, ',', '.') }}</td>
                            <td class="text-end text-success">R$ {{ number_format($row['paid'], 2, ',', '.') }}</td>
                            <td class="text-end text-danger">R$ {{ number_format($row['denied'], 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Sem dados para o período.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent fw-semibold">Detalhamento de guias</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Guia</th>
                        <th>Lote</th>
                        <th>Paciente</th>
                        <th>Convênio</th>
                        <th>Status</th>
                        <th class="text-end">Faturado</th>
                        <th class="text-end">Pago</th>
                        <th class="text-end">Glosa</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($claims as $claim)
                        <tr>
                            <td>{{ $claim->attendance_date?->format('d/m/Y') }}</td>
                            <td><code>{{ $claim->code }}</code></td>
                            <td><code>{{ $claim->batch?->code ?? '—' }}</code></td>
                            <td>{{ $claim->patient?->person?->full_name ?? '—' }}</td>
                            <td>{{ $claim->covenant?->name ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $claim->status->badgeClass() }}">
                                    {{ $claim->status->label() }}
                                </span>
                            </td>
                            <td class="text-end">R$ {{ number_format((float) $claim->amount, 2, ',', '.') }}</td>
                            <td class="text-end text-success">R$ {{ number_format((float) $claim->paid_amount, 2, ',', '.') }}</td>
                            <td class="text-end text-danger">R$ {{ number_format((float) $claim->glosa_amount, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Nenhuma guia encontrada.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

