<div class="card h-100">
    <div class="card-header">
        <i class="fa fa-bar-chart me-2 text-warning"></i> {{ __('dashboard.section_day_summary') }}
    </div>
    <div class="card-body d-flex flex-column gap-2 p-3">

        <div class="day-summary-stat day-summary-stat--total">
            <div class="ds-value ds-value--total">{{ $stats['today_count'] }}</div>
            <div class="ds-label">{{ __('dashboard.summary_total') }}</div>
        </div>

        <div class="day-summary-stat day-summary-stat--attended">
            <div class="ds-value ds-value--attended">{{ $stats['attended_today'] }}</div>
            <div class="ds-label">{{ __('dashboard.summary_attended') }}</div>
        </div>

        <div class="day-summary-stat day-summary-stat--pending">
            <div class="ds-value ds-value--pending">{{ $stats['pending_today'] }}</div>
            <div class="ds-label">{{ __('dashboard.summary_pending') }}</div>
        </div>

        <div class="day-summary-stat day-summary-stat--cancelled">
            <div class="ds-value ds-value--cancelled">{{ $stats['cancelled_today'] }}</div>
            <div class="ds-label">{{ __('dashboard.summary_cancelled') }}</div>
        </div>

    </div>
</div>
