@extends('portal.layouts.app')

@section('content')

{{-- Cabeçalho --}}
<div class="row mb-3 align-items-center">
    <div class="col">
        <h5 class="fw-semibold mb-0">
            <i class="fas fa-coins me-2 text-muted"></i>{{ __('actions.partners.my_commissions') }}
        </h5>
        <div class="text-muted small">Histórico de comissões geradas pelas suas indicações</div>
    </div>
</div>

{{-- Cards KPI --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">Total de Registros</div>
            <div class="fs-3 fw-bold text-primary">{{ $commissions->total() }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">Pendente a Receber</div>
            <div class="fs-4 fw-bold text-warning">R$ {{ number_format($pendingTotal, 2, ',', '.') }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">Total Recebido</div>
            <div class="fs-4 fw-bold text-success">R$ {{ number_format($paidTotal, 2, ',', '.') }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 h-100">
            <div class="text-muted small mb-1">Total Geral</div>
            <div class="fs-4 fw-bold text-secondary">R$ {{ number_format($pendingTotal + $paidTotal, 2, ',', '.') }}</div>
        </div>
    </div>
</div>

{{-- Tabela de comissões --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-0 pt-3 px-4 pb-0 d-flex align-items-center justify-content-between">
        <h6 class="fw-semibold mb-0"><i class="fas fa-list me-2 text-muted"></i>Histórico de Comissões</h6>
        <span class="text-muted small">{{ $commissions->total() }} registro(s)</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th class="px-4">Clínica</th>
                    <th class="text-center">Período</th>
                    <th class="text-end">Taxa</th>
                    <th class="text-end">Valor</th>
                    <th class="text-center">Vencimento</th>
                    <th class="text-center">Pago em</th>
                    <th class="pe-4 text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($commissions as $commission)
                <tr>
                    <td class="px-4">
                        <div class="fw-semibold">{{ $commission->entity?->name ?? '—' }}</div>
                    </td>
                    <td class="text-center text-muted">{{ $commission->period ?? '—' }}</td>
                    <td class="text-end text-muted">
                        {{ $commission->rate ? number_format((float) $commission->rate, 1, ',', '.') . '%' : '—' }}
                    </td>
                    <td class="text-end fw-semibold">R$ {{ number_format((float) $commission->amount, 2, ',', '.') }}</td>
                    <td class="text-center text-muted">{{ $commission->due_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="text-center text-muted">{{ $commission->paid_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="pe-4 text-center">
                        <span class="badge {{ $commission->status->badgeClass() }}">{{ $commission->status->label() }}</span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        {{ __('actions.partners.no_commissions') }}
                        <a href="{{ route('portal.leads.index') }}" class="btn btn-link btn-sm p-0 ms-1">
                            Cadastre leads para gerar comissões
                        </a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($commissions->hasPages())
            <div class="px-4 py-3 border-top">{{ $commissions->links() }}</div>
        @endif
    </div>
</div>

@endsection
