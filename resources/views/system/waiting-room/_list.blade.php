@use(App\Enums\ScheduleSituation)
@use(App\Enums\ClientRule)

@if($schedules->isEmpty())
    <div class="text-center text-muted py-5">
        <i class="fa fa-check-circle fa-3x mb-3 d-block opacity-25"></i>
        Nenhum paciente aguardando no momento.
    </div>
@else
<table class="table table-hover align-middle mb-0">
    <thead class="table-light">
        <tr>
            <th class="ps-3">Paciente</th>
            <th>Horário</th>
            <th>Tipo de visita</th>
            <th>Espera</th>
            <th class="text-center">Situação</th>
            @if(session('user_rule') === ClientRule::Doctor->value)
                <th class="text-end pe-3">Ação</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach($schedules as $schedule)
        @php
            $name      = $schedule->patient_id ? $schedule->patient->person->full_name : $schedule->full_name;
            $photo     = $schedule->patient_id && $schedule->patient->photo
                ? asset('storage/images/patient/' . $schedule->patient->photo)
                : asset('system/images/team.png');
            $waiting   = $schedule->arrived_at ? $schedule->arrived_at->diffForHumans(null, true) : '—';
            $color     = $schedule->doctor->color ?: '#6c757d';
        @endphp
        <tr>
            <td class="ps-3">
                <div class="d-flex align-items-center gap-2">
                    <img src="{{ $photo }}" alt="{{ $name }}"
                         width="36" height="36"
                         class="img-circle"
                         style="border: 2px solid {{ $color }}; object-fit: cover;">
                    <div>
                        <div class="fw-semibold">{{ $name }}</div>
                        <small class="text-muted">{{ $schedule->doctor->person->full_name ?? '—' }}</small>
                    </div>
                </div>
            </td>
            <td>{{ $schedule->date_time->format('H:i') }}</td>
            <td>{{ $schedule->visitType?->name ?? '—' }}</td>
            <td>{{ $waiting }}</td>
            <td class="text-center">
                <span class="badge {{ $schedule->situation->badgeClass() }}">
                    {{ $schedule->situation->label() }}
                </span>
            </td>
            @if(session('user_rule') === ClientRule::Doctor->value)
            <td class="text-end pe-3">
                @if($schedule->situation === ScheduleSituation::Waiting)
                    <button type="button"
                            class="btn btn-success btn-sm"
                            data-situation-action="{{ ScheduleSituation::InProgress->value }}"
                            data-id="{{ $schedule->id }}"
                            data-bs-toggle="tooltip"
                            title="Chamar paciente">
                        <i class="fa fa-bullhorn me-1"></i> Chamar
                    </button>
                @elseif($schedule->situation === ScheduleSituation::InProgress)
                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            data-situation-action="{{ ScheduleSituation::Attended->value }}"
                            data-id="{{ $schedule->id }}"
                            data-bs-toggle="tooltip"
                            title="Finalizar atendimento">
                        <i class="fa fa-check me-1"></i> Finalizar
                    </button>
                @endif
            </td>
            @endif
        </tr>
        @endforeach
    </tbody>
</table>
@endif
