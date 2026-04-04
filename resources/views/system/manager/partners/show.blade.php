@extends('layouts.app')

@push('styles')
    @vite(['resources/css/dashboard.css', 'resources/css/manager-dashboard.css'])
@endpush

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
<div class="page-dashboard">

{{-- ══ Banner / Cabeçalho ═══════════════════════════════════════════════════ --}}
<div class="welcome-banner mb-4 mt-2">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h4 class="mb-1">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle me-2"
                     style="width:36px;height:36px;background:rgba(255,255,255,.2);font-size:1.1rem;">
                    <i class="ti ti-affiliate"></i>
                </div>
                {{ $partner->name }}
            </h4>
            <p>{{ $partner->email }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap" style="position:relative;z-index:1">
            <a href="{{ route('panel.manager.partners.index') }}" class="btn btn-sm btn-banner">
                <i class="ti ti-arrow-left me-1"></i>{{ __('actions.partners.title') }}
            </a>
        </div>
    </div>
</div>

{{-- ══ Conteúdo Principal ═══════════════════════════════════════════════════ --}}
<div class="row g-3">

    {{-- ── Coluna lateral: info do parceiro ─────────────────────────────── --}}
    <div class="col-12 col-md-4">
        <div class="card mgr-chart-card h-100">
            <div class="card-header">
                <i class="ti ti-info-circle me-2"></i>{{ __('actions.partners.title') }}
            </div>
            <div class="card-body">

                {{-- Mini KPIs --}}
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="mgr-mini-kpi">
                            <div class="mini-icon" style="background:#e1f5fe;color:#0288d1;">
                                <i class="ti ti-target-arrow"></i>
                            </div>
                            <div>
                                <div class="mini-value">{{ $leads->total() }}</div>
                                <div class="mini-label">{{ __('actions.partners.leads') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="mgr-mini-kpi">
                            <div class="mini-icon" style="background:#fff3e0;color:#f57c00;">
                                <i class="ti ti-cash"></i>
                            </div>
                            <div>
                                <div class="mini-value">{{ $commissions->total() }}</div>
                                <div class="mini-label">{{ __('actions.partners.commissions') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Dados do parceiro --}}
                <ul class="list-unstyled mb-0" style="font-size:.875rem;">
                    <li class="d-flex justify-content-between align-items-center py-2"
                        style="border-bottom:1px solid var(--ee-border,#e2e8f0);">
                        <span style="color:var(--ee-text-muted,#64748b);">{{ __('actions.partners.col_type') }}</span>
                        <span class="badge bg-secondary-subtle text-secondary">{{ $partner->type->label() }}</span>
                    </li>
                    <li class="d-flex justify-content-between align-items-center py-2"
                        style="border-bottom:1px solid var(--ee-border,#e2e8f0);">
                        <span style="color:var(--ee-text-muted,#64748b);">{{ __('actions.partners.col_status') }}</span>
                        @if($partner->status === 'active')
                            <span class="badge bg-success-subtle text-success">{{ __('actions.partners.status_active') }}</span>
                        @elseif($partner->status === 'inactive')
                            <span class="badge bg-secondary-subtle text-secondary">{{ __('actions.partners.status_inactive') }}</span>
                        @else
                            <span class="badge bg-danger-subtle text-danger">{{ __('actions.partners.status_suspended') }}</span>
                        @endif
                    </li>
                    <li class="d-flex justify-content-between align-items-center py-2"
                        style="border-bottom:1px solid var(--ee-border,#e2e8f0);">
                        <span style="color:var(--ee-text-muted,#64748b);">{{ __('actions.partners.commission_label') }}</span>
                        <span class="fw-semibold">{{ number_format((float) $partner->commission_rate, 1) }}%</span>
                    </li>
                    @if($partner->document)
                    <li class="d-flex justify-content-between align-items-center py-2"
                        style="border-bottom:1px solid var(--ee-border,#e2e8f0);">
                        <span style="color:var(--ee-text-muted,#64748b);">{{ __('actions.partners.document_label') }}</span>
                        <span>{{ $partner->document }}</span>
                    </li>
                    @endif
                    <li class="d-flex justify-content-between align-items-center py-2"
                        style="border-bottom:1px solid var(--ee-border,#e2e8f0);">
                        <span style="color:var(--ee-text-muted,#64748b);">{{ __('actions.partners.utm_token') }}</span>
                        <div class="d-flex align-items-center float-end gap-1">
                            <code style="font-size:.75rem;">{{ $partner->token }}</code>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                    onclick="navigator.clipboard.writeText('{{ $partner->token }}').then(() => this.textContent='✓')"
                                    title="{{ __('actions.copy') ?? 'Copiar' }}">
                                <i class="fas fa-copy" style="font-size:.7rem;"></i>
                            </button>
                        </div>
                    </li>
                    <li class="d-flex justify-content-between align-items-center py-2">
                        <span style="color:var(--ee-text-muted,#64748b);">{{ __('actions.partners.registered_at') }}</span>
                        <span>{{ $partner->created_at->format('d/m/Y') }}</span>
                    </li>
                </ul>

                @if($partner->notes)
                <div class="mt-3 p-3 rounded" style="background:var(--ee-bg,#f5f7fa);font-size:.875rem;color:var(--ee-text-muted,#64748b);">
                    <i class="ti ti-notes me-1"></i>{{ $partner->notes }}
                </div>
                @endif

            </div>
        </div>
    </div>

    {{-- ── Coluna principal: leads + comissões ──────────────────────────── --}}
    <div class="col-12 col-md-8">
        <div class="card mgr-chart-card">
            <div class="card-header p-0" style="padding:0!important;">
                <ul class="nav nav-tabs" style="border-bottom:none;padding:0 1.25rem;">
                    <li class="nav-item">
                        <button class="nav-link active fw-semibold" id="leads-tab"
                                data-bs-toggle="tab" data-bs-target="#leads-pane"
                                type="button" role="tab"
                                style="font-size:.875rem;">
                            <i class="ti ti-target-arrow me-1"></i>{{ __('actions.partners.leads') }}
                            <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $leads->total() }}</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold" id="commissions-tab"
                                data-bs-toggle="tab" data-bs-target="#commissions-pane"
                                type="button" role="tab"
                                style="font-size:.875rem;">
                            <i class="ti ti-cash me-1"></i>{{ __('actions.partners.commissions') }}
                            <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $commissions->total() }}</span>
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-0">
                <div class="tab-content">

                    {{-- ── Leads tab ──────────────────────────────────────── --}}
                    <div class="tab-pane fade show active" id="leads-pane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table mgr-table mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('actions.partners.col_name') }}</th>
                                        <th>{{ __('actions.partners.col_city') }}</th>
                                        <th class="text-center">{{ __('actions.partners.col_status') }}</th>
                                        <th class="text-center">{{ __('actions.partners.col_advance') }}</th>
                                        <th class="text-center">{{ __('actions.partners.col_date') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($leads as $lead)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $lead->name }}</div>
                                            <small style="color:var(--ee-text-muted,#64748b);">{{ $lead->email }}</small>
                                        </td>
                                        <td>{{ $lead->city ? $lead->city . '/' . $lead->state : '—' }}</td>
                                        <td class="text-center">
                                            <span class="badge {{ $lead->status->badgeClass() }}">
                                                {{ $lead->status->label() }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            @if($lead->status !== \App\Enums\PartnerLeadStatus::Converted && $lead->status !== \App\Enums\PartnerLeadStatus::Lost)
                                            <select class="form-select form-select-sm py-0"
                                                    style="font-size:.75rem;height:26px;min-width:120px;"
                                                    data-advance-url="{{ route('panel.manager.partners.leads.advance', [$partner, $lead]) }}"
                                                    onchange="advanceLeadStatus(this)">
                                                <option value="">{{ __('actions.partners.advance_placeholder') }}</option>
                                                @foreach($leadStatuses as $s)
                                                    @if($s !== $lead->status)
                                                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                            @else
                                                <span style="color:var(--ee-text-muted,#64748b);font-size:.8125rem;">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            {{ $lead->created_at->format('d/m/Y') }}
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-4"
                                            style="color:var(--ee-text-muted,#64748b);">
                                            {{ __('actions.partners.no_leads') }}
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if($leads->hasPages())
                        <div class="px-4 py-3" style="border-top:1px solid var(--ee-border,#e2e8f0);">
                            {{ $leads->links() }}
                        </div>
                        @endif
                    </div>

                    {{-- ── Comissões tab ──────────────────────────────────── --}}
                    <div class="tab-pane fade" id="commissions-pane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table mgr-table mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('actions.partners.col_clinic') }}</th>
                                        <th class="text-end">{{ __('actions.partners.col_value') }}</th>
                                        <th class="text-center">{{ __('actions.partners.col_rate') }}</th>
                                        <th class="text-center">{{ __('actions.partners.col_period') }}</th>
                                        <th class="text-center">{{ __('actions.partners.col_status') }}</th>
                                        <th class="text-center">{{ __('actions.partners.col_due') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($commissions as $commission)
                                    <tr>
                                        <td>{{ $commission->entity?->name ?? '—' }}</td>
                                        <td class="text-end fw-semibold">
                                            R$ {{ number_format((float) $commission->amount, 2, ',', '.') }}
                                        </td>
                                        <td class="text-center">{{ number_format((float) $commission->rate, 1) }}%</td>
                                        <td class="text-center">{{ $commission->period }}</td>
                                        <td class="text-center">
                                            <span class="badge {{ $commission->status->badgeClass() }}">
                                                {{ $commission->status->label() }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            {{ $commission->due_at?->format('d/m/Y') ?? '—' }}
                                            @if($commission->status === \App\Enums\CommissionStatus::Pending)
                                            <form action="{{ route('panel.manager.partners.commission.pay', $commission) }}"
                                                  method="POST" class="d-inline ms-1">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-success py-0 px-1"
                                                        style="font-size:.65rem;"
                                                        onclick="return confirm('Marcar como paga?')">
                                                    {{ __('actions.partners.pay') }}
                                                </button>
                                            </form>
                                            @endif
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4"
                                            style="color:var(--ee-text-muted,#64748b);">
                                            {{ __('actions.partners.no_commissions') }}
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if($commissions->hasPages())
                        <div class="px-4 py-3" style="border-top:1px solid var(--ee-border,#e2e8f0);">
                            {{ $commissions->links() }}
                        </div>
                        @endif
                    </div>

                </div>
            </div>
        </div>
    </div>

</div>

</div>{{-- /page-dashboard --}}
@endsection

@section('javascript')
<script>
function advanceLeadStatus(select) {
    const status = select.value;
    if (!status) return;

    const url  = select.dataset.advanceUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    select.disabled = true;

    fetch(url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ status }),
    })
    .then(async r => {
        const json = await r.json();
        if (r.ok) {
            window.location.reload();
        } else {
            alert(json.message ?? 'Erro ao avançar lead.');
            select.value   = '';
            select.disabled = false;
        }
    })
    .catch(() => {
        alert('Erro de conexão.');
        select.value   = '';
        select.disabled = false;
    });
}
</script>
@endsection
