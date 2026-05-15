@php
    $schedules ??= collect();
    $events    ??= collect();

    $items = $schedules
        ->map(fn ($s) => ['type' => 'schedule', 'time' => $s->date_time, 'data' => $s])
        ->merge($events->map(fn ($e) => ['type' => 'event', 'time' => $e->starts_at, 'data' => $e]))
        ->sortBy('time')
        ->values();
@endphp

@if($items->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="fa fa-calendar-times-o fa-3x mb-3"></i>
        <p class="mt-2">{{ __('schedules.empty') }}</p>
    </div>
@else
    @foreach($items as $item)
        @if($item['type'] === 'schedule')
            <x-schedule-card :schedule="$item['data']" />
        @else
            @include('system.schedules._event-card', ['event' => $item['data']])
        @endif
    @endforeach
@endif
