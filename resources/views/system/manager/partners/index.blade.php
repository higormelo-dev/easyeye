@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- ── KPIs de comissões ──────────────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="text-muted small mb-1">Parceiros Ativos</div>
            <div class="fs-3 fw-bold text-primary">{{ $partners->count() }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="text-muted small mb-1">Leads (total)</div>
            <div class="fs-3 fw-bold text-info">{{ $funnel->sum('count') }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="text-muted small mb-1">Comissões Pendentes</div>
            <div class="fs-3 fw-bold text-warning">
                R$ {{ number_format($pendingTotal, 2, ',', '.') }}
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="text-muted small mb-1">Comissões Pagas (total)</div>
            <div class="fs-3 fw-bold text-success">
                R$ {{ number_format($paidTotal, 2, ',', '.') }}
            </div>
        </div>
    </div>
</div>

{{-- ── Funil de Leads ─────────────────────────────────────────────────── --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
        <h6 class="fw-semibold mb-0"><i class="fas fa-filter me-2 text-muted"></i>Funil de Leads</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3 text-center">
            @foreach($funnel as $status)
                <div class="col">
                    <div class="p-3 rounded border">
                        <div class="fs-4 fw-bold">{{ $status['count'] }}</div>
                        <span class="badge {{ $status['badge'] }} mt-1">{{ $status['label'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ── Tabela de Parceiros ────────────────────────────────────────────── --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
        <h6 class="fw-semibold mb-0"><i class="fas fa-handshake me-2 text-muted"></i>Parceiros</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Nome</th>
                        <th>Tipo</th>
                        <th class="text-center">Leads</th>
                        <th class="text-center">Comissões</th>
                        <th class="text-end">Pendente</th>
                        <th class="text-end pe-4">Pago</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($partners as $partner)
                        <tr>
                            <td class="px-4">
                                <div class="fw-semibold">{{ $partner->name }}</div>
                                <div class="text-muted small">{{ $partner->email }}</div>
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">
                                    {{ $partner->type->label() }}
                                </span>
                            </td>
                            <td class="text-center">{{ $partner->leads_count }}</td>
                            <td class="text-center">{{ $partner->commissions_count }}</td>
                            <td class="text-end text-warning fw-semibold">
                                R$ {{ number_format((float) $partner->pending_amount, 2, ',', '.') }}
                            </td>
                            <td class="text-end text-success fw-semibold pe-4">
                                R$ {{ number_format((float) $partner->paid_amount, 2, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Nenhum parceiro cadastrado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── Últimas Comissões ─────────────────────────────────────────────── --}}
@if($recentCommissions->isNotEmpty())
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex align-items-center justify-content-between">
        <h6 class="fw-semibold mb-0"><i class="fas fa-coins me-2 text-muted"></i>Últimas Comissões</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Parceiro</th>
                        <th>Clínica</th>
                        <th class="text-end">Valor</th>
                        <th class="text-center">Status</th>
                        <th class="text-center pe-4">Vencimento</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentCommissions as $commission)
                        <tr>
                            <td class="px-4">{{ $commission->partner?->name ?? '—' }}</td>
                            <td>{{ $commission->entity?->name ?? '—' }}</td>
                            <td class="text-end fw-semibold">
                                R$ {{ number_format((float) $commission->amount, 2, ',', '.') }}
                            </td>
                            <td class="text-center">
                                <span class="badge {{ $commission->status->badgeClass() }}">
                                    {{ $commission->status->label() }}
                                </span>
                            </td>
                            <td class="text-center pe-4">
                                {{ $commission->due_at?->format('d/m/Y') ?? '—' }}
                                @if($commission->status === \App\Enums\CommissionStatus::Pending)
                                    <form action="{{ route('panel.manager.partners.commission.pay', $commission) }}"
                                          method="POST" class="d-inline ms-2">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-success btn-xs py-0 px-2"
                                                style="font-size: 0.7rem;"
                                                onclick="return confirm('Marcar como paga?')">
                                            Pagar
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@endsection
