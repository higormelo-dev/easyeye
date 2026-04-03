{{-- ── Trials Expirando em 7 Dias ────────────────────────────────────────── --}}
<div class="card mgr-chart-card h-100">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="ti ti-alert-triangle me-2 text-warning"></i>{{ __('manager_dashboard.trials_expiring') }}</span>
        <span class="badge bg-warning text-dark">{{ $stats['trialsExpiring']->count() }}</span>
    </div>
    <div class="card-body p-0">
        @if($stats['trialsExpiring']->isEmpty())
            <div class="text-center text-muted py-4">
                <i class="ti ti-mood-happy fs-1 d-block mb-2"></i>
                {{ __('manager_dashboard.no_trials_expiring') }}
            </div>
        @else
            <div class="table-responsive">
                <table class="table mgr-table mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('manager_dashboard.col_entity') }}</th>
                            <th>{{ __('manager_dashboard.col_plan') }}</th>
                            <th>{{ __('manager_dashboard.col_expires') }}</th>
                            <th>{{ __('manager_dashboard.col_activation') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stats['trialsExpiring'] as $trial)
                            @php
                                $score      = $trial->activation_score ?? 0;
                                $scoreClass = $score >= 70 ? 'high' : ($score >= 40 ? 'mid' : 'low');
                                $daysLeft   = (int) now()->diffInDays($trial->trial_ends_at, false);
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $trial->entity?->name ?? '—' }}</div>
                                    <small class="text-muted">{{ $trial->entity?->code }}</small>
                                </td>
                                <td>{{ $trial->plan?->name ?? '—' }}</td>
                                <td>
                                    @if($daysLeft <= 2)
                                        <span class="badge bg-danger">{{ $daysLeft }}d</span>
                                    @else
                                        <span class="badge bg-warning text-dark">{{ $daysLeft }}d</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="score-bar">
                                            <div class="score-bar-fill score-{{ $scoreClass }}" style="width:{{ $score }}%;"></div>
                                        </div>
                                        <span class="fw-semibold text-score-{{ $scoreClass }}" style="font-size:.8rem;">{{ $score }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
