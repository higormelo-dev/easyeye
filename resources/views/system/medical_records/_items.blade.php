@use(Illuminate\Support\Str)

@foreach($records as $i => $record)
    @php
        $doctorColor = $record->doctor?->color ?: '#6c757d';
        $userId      = $record->doctor?->entityUser?->user_id;
        $photoPath   = 'system/images/users/' . $userId . '.jpg';
        $photoUrl    = $userId && file_exists(public_path($photoPath))
            ? asset($photoPath)
            : asset('system/images/team.png');
        $inverted = $i % 2 !== 0;
        $hasFlags = $record->diabetic || $record->hypertensive || $record->glaucomatous;
    @endphp

    <li class="{{ $inverted ? 'timeline-inverted' : '' }}">

        <div class="timeline-badge" style="background-color: {{ $doctorColor }};">
            <img src="{{ $photoUrl }}"
                 alt="{{ $record->doctor?->person?->full_name ?? '' }}"
                 style="width:100%;height:100%;object-fit:cover;">
        </div>

        <div class="timeline-panel">

            <div class="timeline-heading">
                <h5 class="timeline-title mb-1" style="color: {{ $doctorColor }};">
                    {{ $record->doctor?->person?->full_name ?? __('actions.medical_records.not_informed') }}
                </h5>
                <p class="text-muted small mb-2">
                    <i class="fa fa-clock-o me-1"></i>
                    {{ $record->created_at?->format('d/m/Y H:i') ?? __('actions.medical_records.not_informed') }}
                    &mdash;
                    <span class="text-secondary fw-semibold">{{ $record->code }}</span>
                </p>

                @if($hasFlags)
                    <div class="mb-2">
                        @if($record->diabetic)
                            <span class="badge bg-warning text-dark me-1">
                                <i class="fas fa-tint me-1"></i>{{ __('actions.medical_records.diabetic') }}
                            </span>
                        @endif
                        @if($record->hypertensive)
                            <span class="badge bg-danger me-1">
                                <i class="fas fa-heartbeat me-1"></i>{{ __('actions.medical_records.hypertensive') }}
                            </span>
                        @endif
                        @if($record->glaucomatous)
                            <span class="badge bg-info me-1">
                                <i class="fas fa-eye me-1"></i>{{ __('actions.medical_records.glaucomatous') }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            <div class="timeline-body">
                @if($record->main_complaint)
                    <p class="text-muted small mb-2">
                        {{ Str::limit(strip_tags($record->main_complaint), 150) }}
                    </p>
                @endif

                @if($record->tonometer_right || $record->tonometer_left)
                    <p class="small mb-2 text-secondary">
                        <i class="fas fa-ruler-horizontal me-1"></i>
                        <strong>OD:</strong> {{ $record->tonometer_right ?? '—' }}
                        &nbsp;<strong>OE:</strong> {{ $record->tonometer_left ?? '—' }}
                    </p>
                @endif

                <div class="mt-2 {{ $inverted ? 'text-start' : 'text-end' }}">
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm"
                            @click.prevent="$dispatch('show-record', { id: '{{ $record->id }}' })"
                            x-data
                            title="{{ __('actions.view') }}">
                        <i class="fas fa-eye"></i>
                    </button>
                    <a href="{{ route('panel.patients.medicalrecords.edit', [$patient, $record]) }}"
                       class="btn btn-outline-secondary btn-sm"
                       title="{{ __('actions.edit') }}">
                        <i class="fas fa-edit"></i>
                    </a>
                </div>
            </div>

        </div>

    </li>
@endforeach
