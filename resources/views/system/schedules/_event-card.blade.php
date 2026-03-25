{{--
    Card de compromisso não-clínico (ScheduleEvent).
    Renderizado em list.blade.php intercalado com os agendamentos regulares.
--}}
@php
    $typeLabels = [
        'meeting'     => 'Reunião',
        'maintenance' => 'Manutenção',
        'personal'    => 'Pessoal',
        'training'    => 'Treinamento',
        'other'       => 'Outro',
    ];
    $typeLabel = $typeLabels[$event->type] ?? $event->type;
    $color     = $event->color ?? '#6c757d';
@endphp

<div class="card mb-2 border-0 shadow-sm"
     style="border-left: 4px solid {{ $color }} !important; background: #f8f9fa;">
    <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-3">

            {{-- Horário --}}
            <div class="text-center flex-shrink-0" style="min-width: 48px;">
                <div class="fw-bold" style="font-size:.9rem; color:{{ $color }}">
                    {{ $event->starts_at->format('H:i') }}
                </div>
                <div class="text-muted" style="font-size:.7rem;">
                    {{ $event->ends_at->format('H:i') }}
                </div>
            </div>

            {{-- Ícone --}}
            <div class="flex-shrink-0 text-center" style="width:36px;">
                <i class="fas fa-calendar-check fa-lg" style="color:{{ $color }}; opacity:.7;"></i>
            </div>

            {{-- Conteúdo --}}
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-semibold text-truncate" style="font-size:.9rem;">
                        {{ $event->title }}
                    </span>
                    <span class="badge rounded-pill"
                          style="background:{{ $color }}; font-size:.68rem; opacity:.85;">
                        {{ $typeLabel }}
                    </span>
                </div>

                @if($event->doctor)
                    <div class="text-muted" style="font-size:.78rem;">
                        <i class="fas fa-user-md me-1"></i>{{ $event->doctor->name ?? $event->doctor->record }}
                    </div>
                @endif

                @if($event->notes)
                    <div class="text-muted fst-italic text-truncate" style="font-size:.75rem;">
                        {{ $event->notes }}
                    </div>
                @endif
            </div>

            {{-- Badge "Compromisso" --}}
            <div class="flex-shrink-0">
                <span class="badge bg-secondary" style="font-size:.68rem;">Compromisso</span>
            </div>

        </div>
    </div>
</div>
