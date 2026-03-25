@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

    {{-- Filtros --}}
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <form method="GET" action="{{ route('panel.reports.absenteeism') }}" class="row g-3 align-items-end">

                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Data início <span class="text-danger">*</span></label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="{{ request('date_from', now()->startOfMonth()->toDateString()) }}" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Data fim <span class="text-danger">*</span></label>
                    <input type="date" name="date_until" class="form-control form-control-sm"
                           value="{{ request('date_until', now()->toDateString()) }}" required>
                </div>

                @if(session('selected_entity_user_rule') !== 'doctor')
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Médico</label>
                        <select name="doctor_id" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            @foreach($doctors as $doc)
                                <option value="{{ $doc->id }}"
                                        {{ request('doctor_id') === $doc->id ? 'selected' : '' }}>
                                    {{ $doc->user_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="col-auto ms-auto">
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="fas fa-search me-1"></i> Filtrar
                    </button>
                    <a href="{{ route('panel.reports.absenteeism') }}" class="btn btn-outline-secondary btn-sm ms-1">
                        <i class="fas fa-times me-1"></i> Limpar
                    </a>
                </div>
            </form>
        </div>
    </div>

    @isset($summary)
        {{-- Cards de resumo --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card text-center border-0 shadow-sm h-100">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold">{{ $summary['total_period'] }}</div>
                        <div class="text-muted small">Total no período</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center border-0 shadow-sm h-100 border-top border-warning border-3">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-warning">{{ $summary['noshow'] }}</div>
                        <div class="text-muted small">Faltaram</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center border-0 shadow-sm h-100 border-top border-danger border-3">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-danger">{{ $summary['cancelled'] }}</div>
                        <div class="text-muted small">Cancelados</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center border-0 shadow-sm h-100 border-top border-danger border-3">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-danger">{{ $summary['absenteeism_rate'] }}%</div>
                        <div class="text-muted small">Taxa de absenteísmo</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tabela detalhada --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold small text-uppercase text-muted">
                Detalhe ({{ $schedules->count() }} registros)
            </div>
            @if($schedules->isEmpty())
                <div class="card-body text-center text-muted py-4">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <p class="mb-0">Nenhuma falta ou cancelamento no período.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Código</th>
                                <th>Data/Hora</th>
                                <th>Paciente</th>
                                <th>Médico</th>
                                <th>Situação</th>
                                <th>Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($schedules as $schedule)
                                <tr>
                                    <td class="text-muted" style="font-size:.8rem;">{{ $schedule->code }}</td>
                                    <td style="font-size:.85rem; white-space:nowrap;">
                                        {{ $schedule->date_time->format('d/m/Y H:i') }}
                                    </td>
                                    <td style="font-size:.85rem;">
                                        {{ $schedule->patient?->person?->full_name ?? $schedule->full_name }}
                                    </td>
                                    <td style="font-size:.85rem;">
                                        {{ $schedule->doctor?->user_name ?? '—' }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $schedule->situation->badgeClass() }}" style="font-size:.72rem;">
                                            {{ $schedule->situation->label() }}
                                        </span>
                                    </td>
                                    <td class="text-muted" style="font-size:.8rem;">
                                        {{ $schedule->cancellation_reason ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endisset

@endsection
