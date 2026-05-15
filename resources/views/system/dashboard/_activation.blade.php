@php
    $score    = $activationScore ?? 0;
    $progress = $activation ?? [];
    $color    = match (true) {
        $score >= 80 => 'success',
        $score >= 50 => 'info',
        $score >= 30 => 'warning',
        default      => 'danger',
    };
@endphp

@if($score < 100)
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-rocket text-{{ $color }} me-2"></i>{{ __('dashboard.activation_title') }}
                </h6>
                <p class="text-muted small mb-0">
                    {{ __('dashboard.activation_subtitle') }}
                </p>
            </div>
            <div class="text-end">
                <span class="fs-4 fw-bold text-{{ $color }}">{{ $score }}%</span>
                <div class="text-muted" style="font-size: 0.7rem;">{{ __('dashboard.activation_done') }}</div>
            </div>
        </div>

        <div class="progress mb-3" style="height: 8px; border-radius: 4px;">
            <div class="progress-bar bg-{{ $color }}" role="progressbar"
                 style="width: {{ $score }}%; border-radius: 4px;"
                 aria-valuenow="{{ $score }}" aria-valuemin="0" aria-valuemax="100"></div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            @foreach($progress as $item)
                @if($item['completed'])
                    <span class="badge bg-success d-flex align-items-center float-end gap-1 py-2 px-3"
                          title="{{ __('dashboard.activation_completed_on') }} {{ $item['completed_at'] ? \Carbon\Carbon::parse($item['completed_at'])->format('d/m/Y') : '' }}">
                        <i class="fas fa-check fa-xs"></i>
                        {{ $item['label'] }}
                    </span>
                @else
                    <span class="badge bg-light text-muted border d-flex align-items-center float-end gap-1 py-2 px-3">
                        <i class="fas fa-circle fa-xs" style="font-size: 0.5rem;"></i>
                        {{ $item['label'] }}
                        <span class="fw-semibold text-{{ $color }}">+{{ $item['weight'] }}%</span>
                    </span>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endif
