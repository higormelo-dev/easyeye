@extends('portal.layouts.app')

@section('content')
<div x-data="leadsPortal()">

{{-- Cabeçalho --}}
<div class="row mb-3 align-items-center">
    <div class="col">
        <h5 class="fw-semibold mb-0">
            <i class="fas fa-users me-2 text-muted"></i>{{ __('actions.partners.my_leads') }}
        </h5>
        <div class="text-muted small">Clínicas e prospects indicados por você</div>
    </div>
    <div class="col-auto">
        <button type="button" class="btn btn-primary btn-sm" @click="openModal()">
            <i class="fas fa-plus me-1"></i>{{ __('actions.partners.new_lead') }}
        </button>
    </div>
</div>

{{-- Faixa de totais por status --}}
@php use App\Enums\PartnerLeadStatus; @endphp
<div class="row g-2 mb-4">
    @foreach(PartnerLeadStatus::cases() as $s)
    <div class="col-6 col-sm-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center py-2 px-1">
            <span class="badge {{ $s->badgeClass() }} mb-1">{{ $s->label() }}</span>
            <div class="fw-bold fs-5">{{ $leads->getCollection()->where('status', $s)->count() }}</div>
        </div>
    </div>
    @endforeach
</div>

{{-- Tabela de leads --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-0 pt-3 px-4 pb-0 d-flex align-items-center justify-content-between">
        <h6 class="fw-semibold mb-0"><i class="fas fa-list me-2 text-muted"></i>Todos os Leads</h6>
        <span class="text-muted small">{{ $leads->total() }} lead(s)</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th class="px-4">Nome / E-mail</th>
                    <th>Telefone</th>
                    <th>Cidade / UF</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Convertido em</th>
                    <th class="pe-4 text-end">Cadastrado em</th>
                </tr>
            </thead>
            <tbody>
                @forelse($leads as $lead)
                <tr>
                    <td class="px-4">
                        <div class="fw-semibold">{{ $lead->name }}</div>
                        <div class="text-muted" style="font-size:.72rem;">{{ $lead->email }}</div>
                    </td>
                    <td>{{ $lead->phone ?? '—' }}</td>
                    <td>
                        @if($lead->city || $lead->state)
                            {{ $lead->city }}{{ $lead->city && $lead->state ? '/' : '' }}{{ $lead->state }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-center">
                        <span class="badge {{ $lead->status->badgeClass() }}">{{ $lead->status->label() }}</span>
                    </td>
                    <td class="text-center text-muted">{{ $lead->converted_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="pe-4 text-end text-muted">{{ $lead->created_at->format('d/m/Y') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        {{ __('actions.partners.no_leads') }}
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="openModal()">
                            Cadastrar agora
                        </button>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($leads->hasPages())
            <div class="px-4 py-3 border-top">{{ $leads->links() }}</div>
        @endif
    </div>
</div>

{{-- Modal: Novo Lead --}}
<div class="modal fade" id="leadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#26a69a 0%,#1565c0 100%);">
                <h5 class="modal-title text-white fw-semibold">
                    <i class="fas fa-user-plus me-2"></i>{{ __('actions.partners.new_lead') }}
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ route('portal.leads.store') }}">
                @csrf
                <div class="modal-body">
                    @if($errors->any())
                    <div class="alert alert-danger py-2 small">
                        <ul class="mb-0 ps-3">
                            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                    @endif
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Nome da clínica / contato <span class="text-danger">*</span></label>
                            <input type="text" name="name"
                                   class="form-control form-control-sm @error('name') is-invalid @enderror"
                                   value="{{ old('name') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">E-mail <span class="text-danger">*</span></label>
                            <input type="email" name="email"
                                   class="form-control form-control-sm @error('email') is-invalid @enderror"
                                   value="{{ old('email') }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold small">Telefone</label>
                            <input type="text" name="phone"
                                   class="form-control form-control-sm @error('phone') is-invalid @enderror"
                                   value="{{ old('phone') }}" maxlength="20">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-8 col-sm-4">
                            <label class="form-label fw-semibold small">Cidade</label>
                            <input type="text" name="city"
                                   class="form-control form-control-sm @error('city') is-invalid @enderror"
                                   value="{{ old('city') }}" maxlength="100">
                            @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-4 col-sm-2">
                            <label class="form-label fw-semibold small">UF</label>
                            <input type="text" name="state"
                                   class="form-control form-control-sm @error('state') is-invalid @enderror"
                                   value="{{ old('state') }}" maxlength="2" style="text-transform:uppercase;">
                            @error('state')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Observações</label>
                            <textarea name="notes"
                                      class="form-control form-control-sm @error('notes') is-invalid @enderror"
                                      rows="3" maxlength="500">{{ old('notes') }}</textarea>
                            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-save me-1"></i>Salvar Lead
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>{{-- /x-data --}}
@endsection

@section('javascript')
<script>
function leadsPortal() {
    return {
        init() {
            @if($errors->any())
            this.$nextTick(() => {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('leadModal')).show();
            });
            @endif
        },
        openModal() {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('leadModal')).show();
        },
    };
}
</script>
@endsection
