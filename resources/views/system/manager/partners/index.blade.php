@extends('layouts.app')

@push('styles')
    @vite(['resources/css/dashboard.css', 'resources/css/manager-dashboard.css'])
@endpush

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
<div class="page-dashboard" x-data="partnerManager('{{ $storeUrl }}', '{{ $baseUrl }}')">

{{-- ══ Banner / Cabeçalho ═══════════════════════════════════════════════════ --}}
<div class="welcome-banner mb-4 mt-2">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h4 class="mb-1"><i class="ti ti-affiliate me-2"></i>{{ __('actions.partners.title') }}</h4>
            <p>{{ __('actions.partners.subtitle') }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap" style="position:relative;z-index:1">
            <a href="{{ route('panel.dashboard') }}" class="btn btn-sm btn-banner">
                <i class="ti ti-arrow-left me-1"></i>Dashboard
            </a>
            <button type="button" class="btn btn-sm btn-banner-solid" @click="openCreate()">
                <i class="ti ti-plus me-1"></i>{{ __('actions.partners.new') }}
            </button>
        </div>
    </div>
</div>

{{-- ══ KPIs ═════════════════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">

    {{-- Parceiros Ativos --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #1976d2!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e3f2fd;color:#1976d2;">
                    <i class="ti ti-affiliate"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $partners->count() }}</div>
                    <div class="stat-label">{{ __('actions.partners.active_partners') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Leads Total --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #0288d1!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e1f5fe;color:#0288d1;">
                    <i class="ti ti-target-arrow"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $funnel->sum(fn ($s) => $s['count']) }}</div>
                    <div class="stat-label">{{ __('actions.partners.total_leads') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Comissões Pendentes --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #f57c00!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#fff3e0;color:#f57c00;">
                    <i class="ti ti-cash"></i>
                </div>
                <div>
                    <div class="stat-value" style="font-size:1.375rem;">R$ {{ number_format($pendingTotal, 0, ',', '.') }}</div>
                    <div class="stat-label">{{ __('actions.partners.pending_commissions') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Comissões Pagas --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #388e3c!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e8f5e9;color:#388e3c;">
                    <i class="ti ti-circle-check"></i>
                </div>
                <div>
                    <div class="stat-value" style="font-size:1.375rem;">R$ {{ number_format($paidTotal, 0, ',', '.') }}</div>
                    <div class="stat-label">{{ __('actions.partners.paid_commissions') }}</div>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ══ Funil de Leads ══════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card mgr-chart-card">
            <div class="card-header">
                <i class="ti ti-filter me-2"></i>{{ __('actions.partners.lead_funnel') }}
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    @foreach($funnel as $status)
                    <div class="col">
                        <div class="p-3 rounded" style="border:1px solid var(--ee-border,#e2e8f0);">
                            <div class="fw-bold mb-1" style="font-size:1.75rem;color:var(--ee-text,#1e293b);">{{ $status['count'] }}</div>
                            <span class="badge {{ $status['badge'] }}">{{ $status['label'] }}</span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ══ Tabela de Parceiros ══════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card mgr-chart-card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="ti ti-affiliate me-2"></i>{{ __('actions.partners.title') }}</span>
                <button type="button" class="btn btn-sm btn-primary" @click="openCreate()">
                    <i class="ti ti-plus me-1"></i>{{ __('actions.partners.new') }}
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table mgr-table mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('actions.partners.col_name') }}</th>
                            <th>{{ __('actions.partners.col_type') }}</th>
                            <th class="text-center">{{ __('actions.partners.col_leads') }}</th>
                            <th class="text-center">{{ __('actions.partners.col_commissions') }}</th>
                            <th class="text-end">{{ __('actions.partners.col_pending') }}</th>
                            <th class="text-end">{{ __('actions.partners.col_paid') }}</th>
                            <th class="text-center">{{ __('actions.partners.col_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($partners as $partner)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $partner->name }}</div>
                                <small style="color:var(--ee-text-muted,#64748b);">{{ $partner->email }}</small>
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">
                                    {{ $partner->type->label() }}
                                </span>
                            </td>
                            <td class="text-center">{{ $partner->leads_count }}</td>
                            <td class="text-center">{{ $partner->commissions_count }}</td>
                            <td class="text-end fw-semibold" style="color:#f57c00;">
                                R$ {{ number_format((float) $partner->pending_amount, 2, ',', '.') }}
                            </td>
                            <td class="text-end fw-semibold" style="color:#388e3c;">
                                R$ {{ number_format((float) $partner->paid_amount, 2, ',', '.') }}
                            </td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center">
                                    <a href="{{ route('panel.manager.partners.show', $partner) }}"
                                       class="btn btn-sm btn-outline-info py-0 px-2"
                                       title="{{ __('actions.view') }}">
                                        <i class="fas fa-eye" style="font-size:.75rem;"></i>
                                    </a>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary py-0 px-2"
                                            title="{{ __('actions.edit') }}"
                                            @click="openEdit('{{ $partner->id }}', '{{ route('panel.manager.partners.edit-data', $partner) }}', '{{ route('panel.manager.partners.update', $partner) }}')">
                                        <i class="fas fa-edit" style="font-size:.75rem;"></i>
                                    </button>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger py-0 px-2"
                                            title="{{ __('actions.delete') }}"
                                            @click="deletePartner('{{ $partner->id }}', '{{ route('panel.manager.partners.destroy', $partner) }}')">
                                        <i class="fas fa-trash" style="font-size:.75rem;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-4" style="color:var(--ee-text-muted,#64748b);">
                                {{ __('actions.partners.empty') }}
                                <button type="button" class="btn btn-link p-0 ms-1" @click="openCreate()">
                                    {{ __('actions.partners.register_now') }}
                                </button>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ══ Últimas Comissões ═══════════════════════════════════════════════════ --}}
@if($recentCommissions->isNotEmpty())
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card mgr-chart-card">
            <div class="card-header">
                <i class="ti ti-cash me-2"></i>{{ __('actions.partners.recent_commissions') }}
            </div>
            <div class="card-body p-0">
                <table class="table mgr-table mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('actions.partners.col_partner') }}</th>
                            <th>{{ __('actions.partners.col_clinic') }}</th>
                            <th class="text-end">{{ __('actions.partners.col_value') }}</th>
                            <th class="text-center">{{ __('actions.partners.col_status') }}</th>
                            <th class="text-center">{{ __('actions.partners.col_due') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentCommissions as $commission)
                        <tr>
                            <td>{{ $commission->partner?->name ?? '—' }}</td>
                            <td>{{ $commission->entity?->name ?? '—' }}</td>
                            <td class="text-end fw-semibold">
                                R$ {{ number_format((float) $commission->amount, 2, ',', '.') }}
                            </td>
                            <td class="text-center">
                                <span class="badge {{ $commission->status->badgeClass() }}">
                                    {{ $commission->status->label() }}
                                </span>
                            </td>
                            <td class="text-center">
                                {{ $commission->due_at?->format('d/m/Y') ?? '—' }}
                                @if($commission->status === \App\Enums\CommissionStatus::Pending)
                                <form action="{{ route('panel.manager.partners.commission.pay', $commission) }}"
                                      method="POST" class="d-inline ms-2">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-success py-0 px-2"
                                            style="font-size:0.7rem;"
                                            onclick="return confirm('Marcar como paga?')">
                                        {{ __('actions.partners.pay') }}
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
</div>
@endif

{{-- ══ Modal ════════════════════════════════════════════════════════════════ --}}
@include('system.manager.partners._form-modal', ['partnerTypes' => $partnerTypes])

</div>{{-- /page-dashboard /x-data --}}
@endsection

@section('javascript')
<script>
function partnerManager(storeUrl, baseUrl) {
    return {
        showModal: false,
        isEdit: false,
        saving: false,
        currentUpdateUrl: '',
        form: {
            name: '', email: '', type: '', document: '', commission_rate: '', notes: '', status: 'active',
        },
        errors: {},

        defaultRates: { distributor: 15, association: 10, consultant: 20, agency: 12 },

        openCreate() {
            this.isEdit = false;
            this.form = { name: '', email: '', type: '', document: '', commission_rate: '', notes: '', status: 'active' };
            this.errors = {};
            this.currentUpdateUrl = '';
            this.showModal = true;
            this.$nextTick(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('partnerModal')).show());
        },

        openEdit(id, editDataUrl, updateUrl) {
            this.isEdit = true;
            this.errors = {};
            this.currentUpdateUrl = updateUrl;
            fetch(editDataUrl)
                .then(r => r.json())
                .then(json => {
                    this.form = {
                        name: json.data.name,
                        email: json.data.email,
                        type: json.data.type,
                        document: json.data.document ?? '',
                        commission_rate: json.data.commission_rate,
                        notes: json.data.notes ?? '',
                        status: json.data.status,
                    };
                    this.showModal = true;
                    this.$nextTick(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('partnerModal')).show());
                });
        },

        onTypeChange() {
            if (!this.isEdit && this.form.type && this.defaultRates[this.form.type]) {
                this.form.commission_rate = this.defaultRates[this.form.type];
            }
        },

        save() {
            this.saving = true;
            this.errors = {};
            const url    = this.isEdit ? this.currentUpdateUrl : storeUrl;
            const method = this.isEdit ? 'PUT' : 'POST';

            fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(this.form),
            })
            .then(async r => {
                const json = await r.json();
                if (!r.ok) {
                    if (r.status === 422) this.errors = json.errors ?? {};
                    else alert(json.message ?? 'Erro ao salvar.');
                    return;
                }
                bootstrap.Modal.getInstance(document.getElementById('partnerModal'))?.hide();
                window.location.reload();
            })
            .finally(() => this.saving = false);
        },

        deletePartner(id, destroyUrl) {
            if (!confirm('Excluir este parceiro?')) return;
            fetch(destroyUrl, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            })
            .then(async r => {
                const json = await r.json();
                if (!r.ok) { alert(json.message ?? 'Erro ao excluir.'); return; }
                window.location.reload();
            });
        },
    };
}
</script>
@endsection
