<div class="card h-100">
    <div class="card-header">
        <i class="fa fa-bar-chart me-2 text-warning"></i> {{ __('dashboard.section_day_summary') }}
    </div>
    <div class="card-body d-flex flex-column gap-2 p-3">

        <div class="day-summary-stat" style="border-left-color:#1976d2;">
            <div class="ds-value" style="color:#1976d2;">{{ $stats['today_count'] }}</div>
            <div class="ds-label">{{ __('dashboard.summary_total') }}</div>
        </div>

        <div class="day-summary-stat" style="border-left-color:#388e3c;">
            <div class="ds-value" style="color:#388e3c;">{{ $stats['attended_today'] }}</div>
            <div class="ds-label">{{ __('dashboard.summary_attended') }}</div>
        </div>

        <div class="day-summary-stat" style="border-left-color:#f57f17;">
            <div class="ds-value" style="color:#f57f17;">{{ $stats['pending_today'] }}</div>
            <div class="ds-label">{{ __('dashboard.summary_pending') }}</div>
        </div>

        <div class="day-summary-stat" style="border-left-color:#c62828;">
            <div class="ds-value" style="color:#c62828;">{{ $stats['cancelled_today'] }}</div>
            <div class="ds-label">{{ __('dashboard.summary_cancelled') }}</div>
        </div>

    </div>
</div>
