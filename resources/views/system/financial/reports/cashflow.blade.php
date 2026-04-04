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
                    <div class="btn-group ms-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="ti ti-download me-1"></i> Exportar
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('panel.financial.reports.cash-flow.export', array_merge(request()->query(), ['format' => 'csv'])) }}">CSV</a></li>
                            <li><a class="dropdown-item" href="{{ route('panel.financial.reports.cash-flow.export', array_merge(request()->query(), ['format' => 'xls'])) }}">XLS (XSL)</a></li>
                            <li><a class="dropdown-item" href="{{ route('panel.financial.reports.cash-flow.export', array_merge(request()->query(), ['format' => 'xlsx'])) }}">XLSX</a></li>
                            <li><a class="dropdown-item" href="{{ route('panel.financial.reports.cash-flow.export', array_merge(request()->query(), ['format' => 'pdf'])) }}">PDF</a></li>
                        </ul>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total de receitas</div>
                    <div class="fs-3 fw-bold text-success">R$ {{ number_format($summary['income'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total de despesas</div>
                    <div class="fs-3 fw-bold text-danger">R$ {{ number_format($summary['expense'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Saldo do período</div>
                    <div class="fs-3 fw-bold {{ $summary['balance'] >= 0 ? 'text-primary' : 'text-danger' }}">
                        R$ {{ number_format($summary['balance'], 2, ',', '.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">Composição por categoria</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Categoria</th>
                                <th>Tipo</th>
                                <th class="text-end">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($byCategory as $row)
                                <tr>
                                    <td>{{ $row['category'] }}</td>
                                    <td>
                                        <span class="badge {{ $row['type'] === 'income' ? 'bg-success' : 'bg-danger' }}">
                                            {{ $row['type'] === 'income' ? 'Receita' : 'Despesa' }}
                                        </span>
                                    </td>
                                    <td class="text-end">R$ {{ number_format($row['total'], 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-3">Sem dados no período.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">Evolução diária</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Dia</th>
                                <th class="text-end">Receitas</th>
                                <th class="text-end">Despesas</th>
                                <th class="text-end">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($byDay as $row)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($row['day'])->format('d/m/Y') }}</td>
                                    <td class="text-end text-success">R$ {{ number_format($row['income'], 2, ',', '.') }}</td>
                                    <td class="text-end text-danger">R$ {{ number_format($row['expense'], 2, ',', '.') }}</td>
                                    <td class="text-end fw-semibold {{ ($row['income'] - $row['expense']) >= 0 ? 'text-primary' : 'text-danger' }}">
                                        R$ {{ number_format($row['income'] - $row['expense'], 2, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">Sem dados no período.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent fw-semibold">Detalhamento de lançamentos</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Código</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th class="text-end">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr>
                            <td>{{ $entry->entry_date?->format('d/m/Y') }}</td>
                            <td><code>{{ $entry->code }}</code></td>
                            <td>{{ $entry->description }}</td>
                            <td>{{ $entry->category?->name ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $entry->type->value === 'income' ? 'bg-success' : 'bg-danger' }}">
                                    {{ $entry->type->label() }}
                                </span>
                            </td>
                            <td>{{ $entry->status->label() }}</td>
                            <td class="text-end fw-semibold {{ $entry->type->value === 'income' ? 'text-success' : 'text-danger' }}">
                                R$ {{ number_format((float) $entry->amount, 2, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Nenhum lançamento encontrado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
