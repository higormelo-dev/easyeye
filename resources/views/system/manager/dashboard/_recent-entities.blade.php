{{-- ── Últimas Clínicas Criadas ──────────────────────────────────────────── --}}
<div class="card mgr-chart-card h-100">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="ti ti-building-plus me-2"></i>{{ __('manager_dashboard.recent_entities') }}</span>
        <a href="{{ route('panel.manager.entities.index') }}" class="btn btn-sm btn-outline-primary">
            {{ __('manager_dashboard.view_all') }}
        </a>
    </div>
    <div class="card-body p-0">
        @if($stats['recentEntities']->isEmpty())
            <div class="text-center text-muted py-4">
                {{ __('manager_dashboard.no_entities') }}
            </div>
        @else
            <div class="table-responsive">
                <table class="table mgr-table mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('manager_dashboard.col_entity') }}</th>
                            <th>{{ __('manager_dashboard.col_date') }}</th>
                            <th>{{ __('manager_dashboard.col_subscription') }}</th>
                            <th>{{ __('manager_dashboard.col_activation') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stats['recentEntities'] as $entity)
                            @php
                                $sub        = $entity->latest_sub;
                                $score      = $entity->activation_score ?? 0;
                                $scoreClass = $score >= 70 ? 'high' : ($score >= 40 ? 'mid' : 'low');
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $entity->name }}</div>
                                    <small class="text-muted">{{ $entity->code }}</small>
                                </td>
                                <td>
                                    <small>{{ $entity->created_at->format('d/m/Y') }}</small>
                                </td>
                                <td>
                                    @if($sub)
                                        <span class="badge {{ $sub->status->badgeClass() }}">
                                            {{ $sub->status->label() }}
                                        </span>
                                        @if($sub->plan)
                                            <small class="d-block text-muted mt-1">{{ $sub->plan->name }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
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
