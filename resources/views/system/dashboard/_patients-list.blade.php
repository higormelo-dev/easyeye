<div class="card h-100">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa fa-users me-2 text-primary"></i> {{ __('dashboard.section_recent_patients') }}</span>
        <a href="{{ route('panel.patients.index') }}" class="btn btn-sm btn-outline-primary">
            {{ __('dashboard.btn_see_all') }} <i class="fa fa-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="card-body p-0">
        @if($stats['recent_patients']->isEmpty())
            <p class="text-muted text-center py-4 mb-0">
                <i class="fa fa-users fa-2x d-block mb-2"></i>
                {{ __('dashboard.empty_patients') }}
            </p>
        @else
            <div class="table-responsive">
                <table class="schedule-table table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('dashboard.col_name') }}</th>
                            <th>{{ __('dashboard.col_phone') }}</th>
                            <th>{{ __('dashboard.col_code') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats['recent_patients'] as $patient)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="patient-initial"
                                         style="background:{{ '#' . substr(md5($patient->person?->full_name ?? '?'), 0, 6) }};">
                                        {{ mb_strtoupper(mb_substr($patient->person?->full_name ?? '?', 0, 1)) }}
                                    </div>
                                    <span class="fw-medium">{{ $patient->person?->full_name ?? '—' }}</span>
                                </div>
                            </td>
                            <td class="text-muted small">
                                {{ $patient->person?->cellphone ?? $patient->person?->telephone ?? '—' }}
                            </td>
                            <td>
                                <code class="text-muted small">{{ $patient->code }}</code>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('panel.patients.show', $patient) }}"
                                   class="btn btn-xs btn-outline-secondary">
                                    {{ __('dashboard.btn_view') }}
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
