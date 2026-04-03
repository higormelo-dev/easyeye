@extends('portal.layouts.app')

@section('content')

<div class="row mb-3">
    <div class="col">
        <h5 class="fw-semibold mb-0">Dashboard</h5>
        <div class="text-muted small">Resumo do seu desempenho como parceiro</div>
    </div>
</div>

{{-- ── KPIs ───────────────────────────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">Total Leads</div>
            <div class="fs-3 fw-bold text-primary">{{ $metrics['total_leads'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">Leads Ativos</div>
            <div class="fs-3 fw-bold text-info">{{ $metrics['active_leads'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">Convertidos</div>
            <div class="fs-3 fw-bold text-success">{{ $metrics['converted_leads'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">{{ __('actions.partners.conversion_rate') }}</div>
            <div class="fs-3 fw-bold text-warning">{{ $metrics['conversion_rate'] }}%</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">{{ __('actions.partners.pending_amount') }}</div>
            <div class="fs-4 fw-bold text-warning">
                R$ {{ number_format($metrics['pending_commissions'], 2, ',', '.') }}
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">{{ __('actions.partners.total_paid') }}</div>
            <div class="fs-4 fw-bold text-success">
                R$ {{ number_format($metrics['total_commissions'], 2, ',', '.') }}
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- ── Últimos Leads ───────────────────────────────────────────────── --}}
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-0 pt-3 px-4 pb-0 d-flex align-items-center justify-content-between">
                <h6 class="fw-semibold mb-0"><i class="fas fa-users me-2 text-muted"></i>Últimos Leads</h6>
                <a href="{{ route('portal.leads.index') }}" class="btn btn-link btn-sm p-0">Ver todos</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">Nome</th>
                                <th>Status</th>
                                <th class="pe-4 text-end">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentLeads as $lead)
                            <tr>
                                <td class="px-4">
                                    <div class="fw-semibold">{{ $lead->name }}</div>
                                    <div class="text-muted" style="font-size:.72rem;">{{ $lead->email }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $lead->status->badgeClass() }}">{{ $lead->status->label() }}</span>
                                </td>
                                <td class="pe-4 text-end text-muted">{{ $lead->created_at->format('d/m/Y') }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">Nenhum lead cadastrado ainda.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Últimas Comissões ───────────────────────────────────────────── --}}
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-0 pt-3 px-4 pb-0 d-flex align-items-center justify-content-between">
                <h6 class="fw-semibold mb-0"><i class="fas fa-coins me-2 text-muted"></i>Últimas Comissões</h6>
                <a href="{{ route('portal.commissions.index') }}" class="btn btn-link btn-sm p-0">Ver todas</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">Clínica</th>
                                <th class="text-end">Valor</th>
                                <th class="pe-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentCommissions as $commission)
                            <tr>
                                <td class="px-4">{{ $commission->entity?->name ?? '—' }}</td>
                                <td class="text-end fw-semibold">
                                    R$ {{ number_format((float) $commission->amount, 2, ',', '.') }}
                                </td>
                                <td class="pe-4 text-center">
                                    <span class="badge {{ $commission->status->badgeClass() }}">
                                        {{ $commission->status->label() }}
                                    </span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">Nenhuma comissão ainda.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
