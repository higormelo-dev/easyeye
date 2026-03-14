@php $schedules ??= collect(); @endphp

@if($schedules->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="fa fa-calendar-times-o fa-3x mb-3"></i>
        <p class="mt-2">Nenhum agendamento encontrado para os filtros selecionados.</p>
    </div>
@else
    @foreach($schedules as $schedule)
        <x-schedule-card :schedule="$schedule" />
    @endforeach
@endif
