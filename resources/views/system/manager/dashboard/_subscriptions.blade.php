{{-- ── Funil de Assinaturas ──────────────────────────────────────────────── --}}
@php
    $statusColors = [
        'trial'     => '#17a2b8',
        'active'    => '#28a745',
        'expired'   => '#dc3545',
        'cancelled' => '#6c757d',
        'past_due'  => '#ffc107',
    ];
    $maxCount = max(1, max($stats['subscriptionCounts']));
@endphp

<div class="card mgr-chart-card h-100">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="ti ti-chart-bar me-2"></i>{{ __('manager_dashboard.subscription_funnel') }}</span>
        <span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13">
            {{ __('actions.total') }}: {{ $stats['totalSubscriptions'] }}
        </span>
    </div>
    <div class="card-body">
        @foreach (\App\Enums\SubscriptionStatus::cases() as $status)
            @php
                $count   = $stats['subscriptionCounts'][$status->value] ?? 0;
                $percent = $maxCount > 0 ? round(($count / $maxCount) * 100) : 0;
                $color   = $statusColors[$status->value] ?? '#6c757d';
            @endphp
            <div class="funnel-row">
                <span class="funnel-label">
                    <span class="badge {{ $status->badgeClass() }}">{{ $status->label() }}</span>
                </span>
                <div class="funnel-bar-wrapper">
                    <div class="funnel-bar" style="width:{{ $percent }}%;background:{{ $color }};"></div>
                </div>
                <span class="funnel-count">{{ $count }}</span>
            </div>
        @endforeach
    </div>
</div>
