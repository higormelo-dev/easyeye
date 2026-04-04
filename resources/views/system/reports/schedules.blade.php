@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

    {{-- Filtros --}}
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <form method="GET" action="{{ route('panel.reports.schedules') }}" class="row g-3 align-items-end">

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
                    <div class="col-md-2">
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

                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Convênio</label>
                        <select name="covenant_id" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            @foreach($covenants as $cov)
                                <option value="{{ $cov->id }}"
                                        {{ request('covenant_id') === $cov->id ? 'selected' : '' }}>
                                    {{ $cov->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Situação</label>
                        <select name="situation" class="form-select form-select-sm">
                            <option value="">Todas</option>
                            @foreach($situations as $sit)
                                <option value="{{ $sit->value }}"
                                        {{ request('situation') == $sit->value ? 'selected' : '' }}>
                                    {{ $sit->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="col-auto ms-auto">
                    <button type="submit" class="btn btn-info btn-sm">
                        <i class="fas fa-search me-1"></i> Filtrar
                    </button>
                    <a href="{{ route('panel.reports.schedules') }}" class="btn btn-outline-secondary btn-sm ms-1">
                        <i class="fas fa-times me-1"></i> Limpar
                    </a>
                </div>
            </form>
        </div>
    </div>

    @isset($summary)
        {{-- Cards de resumo --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2">
                <div class="card text-center border-0 shadow-sm h-100">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold">{{ $summary['total'] }}</div>
                        <div class="text-muted small">Total</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center border-0 shadow-sm h-100 border-top border-success border-3">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-success">{{ $summary['attended'] }}</div>
                        <div class="text-muted small">Atendidos</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center border-0 shadow-sm h-100 border-top border-danger border-3">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-danger">{{ $summary['cancelled'] }}</div>
                        <div class="text-muted small">Cancelados</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center border-0 shadow-sm h-100 border-top border-warning border-3">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-warning">{{ $summary['noshow'] }}</div>
                        <div class="text-muted small">Faltaram</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center border-0 shadow-sm h-100 border-top border-secondary border-3">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-secondary">{{ $summary['pending'] }}</div>
                        <div class="text-muted small">Pendentes</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center border-0 shadow-sm h-100 border-top border-info border-3">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold text-info">{{ $summary['attendance_rate'] }}%</div>
                        <div class="text-muted small">Comparecimento</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Breakdown por médico --}}
        @if($byDoctor->isNotEmpty())
            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold small text-uppercase text-muted">
                    Por Médico
                </div>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Médico</th>
                            <th class="text-center">Total</th>
                            <th class="text-center text-success">Atendidos</th>
                            <th class="text-center text-warning">Faltaram</th>
                            <th class="text-center text-danger">Cancelados</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byDoctor as $row)
                            <tr>
                                <td>{{ $row['doctor_name'] }}</td>
                                <td class="text-center fw-semibold">{{ $row['total'] }}</td>
                                <td class="text-center text-success">{{ $row['attended'] }}</td>
                                <td class="text-center text-warning">{{ $row['noshow'] }}</td>
                                <td class="text-center text-danger">{{ $row['cancelled'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Tabela detalhada --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
                <span class="fw-semibold small text-uppercase text-muted">Detalhe ({{ $schedules->count() }} registros)</span>
            </div>
            @if($schedules->isEmpty())
                <div class="card-body text-center text-muted py-4">
                    <i class="fas fa-calendar-times fa-2x mb-2"></i>
                    <p class="mb-0">Nenhum agendamento encontrado para o período.</p>
                </div>
            @else
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Data/Hora</th>
                            <th>Paciente</th>
                            <th>Médico</th>
                            <th>Convênio</th>
                            <th>Situação</th>
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
                                <td style="font-size:.85rem;">
                                    {{ $schedule->covenant?->name ?? '—' }}
                                </td>
                                <td>
                                    <span class="badge {{ $schedule->situation->badgeClass() }}" style="font-size:.72rem;">
                                        {{ $schedule->situation->label() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endisset

@endsection
