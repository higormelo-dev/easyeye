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
                    <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Período final</label>
                    <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Convênio</label>
                    <select name="covenant_id" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach($covenants as $covenant)
                            <option value="{{ $covenant->id }}" @selected(request('covenant_id') === $covenant->id)>
                                {{ $covenant->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Status das guias</label>
                    <select name="claim_status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="draft" @selected(request('claim_status') === 'draft')>Rascunho</option>
                        <option value="submitted" @selected(request('claim_status') === 'submitted')>Enviado</option>
                        <option value="paid" @selected(request('claim_status') === 'paid')>Pago</option>
                        <option value="denied" @selected(request('claim_status') === 'denied')>Glosado</option>
                        <option value="cancelled" @selected(request('claim_status') === 'cancelled')>Cancelado</option>
                    </select>
                </div>
                <div class="col-auto ms-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="ti ti-filter me-1"></i> Filtrar
                    </button>
                    <a href="{{ route('panel.financial.billing.index') }}" class="btn btn-outline-secondary btn-sm ms-1">
                        <i class="ti ti-x me-1"></i> Limpar
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">
                    Faturamento individual
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('panel.financial.billing.individual.store') }}" class="row g-2">
                        @csrf
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Agendamento atendido</label>
                            <select name="schedule_id" class="form-select form-select-sm" required>
                                <option value="">Selecione...</option>
                                @foreach($eligibleSchedules as $schedule)
                                    <option value="{{ $schedule->id }}">
                                        {{ $schedule->date_time?->format('d/m/Y H:i') }} - {{ $schedule->patient?->person?->full_name ?? $schedule->full_name }} ({{ $schedule->covenant?->name ?? 'Sem convênio' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Qtde</label>
                            <input type="number" name="quantity" min="1" value="1" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Valor unitário (R$)</label>
                            <input type="number" name="unit_price" min="0" step="0.01" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Vencimento</label>
                            <input type="date" name="due_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Status inicial</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="draft">Rascunho</option>
                                <option value="submitted">Enviado</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Código TUSS</label>
                            <input type="text" name="tuss_code" value="10101012" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Autorização</label>
                            <input type="text" name="authorization_code" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Descrição procedimento</label>
                            <input type="text" name="procedure_description" value="CONSULTA OFTALMOLOGICA" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="ti ti-plus me-1"></i> Criar guia individual
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <form method="POST" action="{{ route('panel.financial.billing.batch.store') }}">
                @csrf
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent fw-semibold">
                        Faturamento em lote (TISS)
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Convênio</label>
                                <select name="covenant_id" class="form-select form-select-sm" required>
                                    <option value="">Selecione...</option>
                                    @foreach($covenants as $covenant)
                                        <option value="{{ $covenant->id }}">{{ $covenant->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Qtde</label>
                                <input type="number" name="quantity" min="1" value="1" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Valor unitário (R$)</label>
                                <input type="number" name="unit_price" min="0" step="0.01" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Vencimento</label>
                                <input type="date" name="due_date" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Período de</label>
                                <input type="date" name="date_from" value="{{ $from }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Período até</label>
                                <input type="date" name="date_until" value="{{ $to }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Versão TISS</label>
                                <select name="tiss_version" class="form-select form-select-sm">
                                    @foreach($tissVersionOptions as $version)
                                        <option value="{{ $version }}" @selected(old('tiss_version', $selectedTissVersion) === $version)>{{ $version }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Layout comunicação</label>
                                <select name="tiss_layout_version" class="form-select form-select-sm">
                                    @foreach($tissLayoutOptions as $layout)
                                        <option value="{{ $layout }}" @selected(old('tiss_layout_version', $selectedTissLayout) === $layout)>{{ $layout }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Código TUSS</label>
                                <input type="text" name="tuss_code" value="10101012" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small fw-semibold">Descrição procedimento</label>
                                <input type="text" name="procedure_description" value="CONSULTA OFTALMOLOGICA" class="form-control form-control-sm">
                            </div>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">Agendamentos elegíveis ({{ $eligibleSchedules->count() }})</h6>
                            <small class="text-muted">Marque para faturar no lote. Se não marcar, o sistema usa todos elegíveis no período.</small>
                        </div>

                        <div class="table-responsive border rounded">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:32px;">
                                            <input type="checkbox" onclick="document.querySelectorAll('.schedule-check').forEach(el => el.checked = this.checked);">
                                        </th>
                                        <th>Data</th>
                                        <th>Paciente</th>
                                        <th>Médico</th>
                                        <th>Convênio</th>
                                        <th>Código agenda</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($eligibleSchedules as $schedule)
                                        <tr>
                                            <td>
                                                <input class="schedule-check" type="checkbox" name="schedule_ids[]" value="{{ $schedule->id }}">
                                            </td>
                                            <td>{{ $schedule->date_time?->format('d/m/Y H:i') }}</td>
                                            <td>{{ $schedule->patient?->person?->full_name ?? $schedule->full_name }}</td>
                                            <td>{{ $schedule->doctor?->user_name ?? '—' }}</td>
                                            <td>{{ $schedule->covenant?->name ?? '—' }}</td>
                                            <td><code>{{ $schedule->code }}</code></td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-3">
                                                Nenhum agendamento elegível para faturar no período.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="ti ti-stack-2 me-1"></i> Criar lote TISS
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold">Guias de faturamento</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Guia</th>
                                <th>Data</th>
                                <th>Paciente</th>
                                <th>Convênio</th>
                                <th class="text-end">Valor</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($claims as $claim)
                                <tr>
                                    <td><code>{{ $claim->code }}</code></td>
                                    <td>{{ $claim->attendance_date?->format('d/m/Y') }}</td>
                                    <td>{{ $claim->patient?->person?->full_name ?? '—' }}</td>
                                    <td>{{ $claim->covenant?->name ?? '—' }}</td>
                                    <td class="text-end fw-semibold">R$ {{ number_format((float) $claim->amount, 2, ',', '.') }}</td>
                                    <td>
                                        <span class="badge {{ $claim->status->badgeClass() }}">
                                            {{ $claim->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if($claim->status->value !== 'paid')
                                            <form method="POST" action="{{ route('panel.financial.billing.claims.paid', $claim) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-outline-success btn-xs" type="submit">Pagar</button>
                                            </form>
                                        @endif
                                        @if(!in_array($claim->status->value, ['paid', 'denied', 'cancelled'], true))
                                            <form method="POST" action="{{ route('panel.financial.billing.claims.denied', $claim) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-outline-danger btn-xs" type="submit">Glosar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Nenhuma guia encontrada.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold">Lotes TISS</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Lote</th>
                                <th>Convênio</th>
                                <th>Guias</th>
                                <th class="text-end">Valor</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($batches as $batch)
                                <tr>
                                    <td><code>{{ $batch->code }}</code></td>
                                    <td>{{ $batch->covenant?->name ?? '—' }}</td>
                                    <td>{{ $batch->claims_count }}</td>
                                    <td class="text-end">R$ {{ number_format((float) $batch->total_amount, 2, ',', '.') }}</td>
                                    <td>
                                        <span class="badge {{ $batch->status->badgeClass() }}">
                                            {{ $batch->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if($batch->status->value === 'draft')
                                            <form method="POST" action="{{ route('panel.financial.billing.batches.submit', $batch) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-outline-primary btn-xs" type="submit">Enviar</button>
                                            </form>
                                        @endif
                                        <a href="{{ route('panel.financial.billing.batches.xml', $batch) }}"
                                           class="btn btn-outline-secondary btn-xs">
                                            XML
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Nenhum lote encontrado.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
