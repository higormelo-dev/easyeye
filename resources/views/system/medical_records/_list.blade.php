@use(Illuminate\Support\Str)

@if($records->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="fas fa-file-medical fa-3x mb-3 d-block opacity-25"></i>
        <p>Nenhum prontuário cadastrado.</p>
    </div>
@else
    <ul class="timeline">
        @foreach($records as $i => $record)
            @php
                $doctorColor = $record->doctor?->color ?: '#6c757d';
                $userId      = $record->doctor?->entityUser?->user_id;
                $photoPath   = 'system/images/users/' . $userId . '.jpg';
                $photoUrl    = $userId && file_exists(public_path($photoPath))
                    ? asset($photoPath)
                    : Vite::asset('resources/img/system/team.png');
                $inverted    = $i % 2 !== 0;
            @endphp
            <li class="{{ $inverted ? 'timeline-inverted' : '' }}">

                <div class="timeline-badge" style="background-color: {{ $doctorColor }};">
                    <img src="{{ $photoUrl }}"
                         alt="{{ $record->doctor?->person?->full_name ?? '' }}"
                         style="width:100%;height:100%;object-fit:cover;">
                </div>

                <div class="timeline-panel">
                    <div class="timeline-heading">
                        <h5 class="timeline-title" style="color: {{ $doctorColor }};">
                            {{ $record->doctor?->person?->full_name ?? __('actions.not_informed') }}
                        </h5>
                        <p class="mb-1">
                            <small class="text-muted">
                                <i class="fa fa-clock-o me-1"></i>
                                {{ $record->created_at?->format('d/m/Y H:i') ?? __('actions.not_informed') }}
                                &mdash;
                                <span class="text-secondary">{{ $record->code }}</span>
                            </small>
                        </p>
                    </div>
                    <div class="timeline-body">
                        <p class="text-muted small">
                            {{ Str::limit(strip_tags($record->main_complaint ?? ''), 120) }}
                        </p>
                        <div class="mt-2 {{ $inverted ? 'text-start' : 'text-end' }}">
                            <button type="button"
                                    class="btn btn-secondary btn-sm"
                                    @click.prevent="$dispatch('show-record', { id: '{{ $record->id }}' })"
                                    x-data
                                    title="{{ __('actions.view') }}">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a href="{{ route('panel.patients.medicalrecords.edit', [$patient, $record]) }}"
                               class="btn btn-secondary btn-sm"
                               title="{{ __('actions.edit') }}">
                                <i class="fas fa-edit"></i>
                            </a>
                        </div>
                    </div>
                </div>

            </li>
        @endforeach
    </ul>

    {{-- Paginação --}}
    @if($records->lastPage() > 1)
        <nav class="d-flex justify-content-center mt-4">
            <ul class="pagination pagination-sm mb-0">
                @for($p = 1; $p <= $records->lastPage(); $p++)
                    <li class="page-item {{ $records->currentPage() === $p ? 'active' : '' }}">
                        <button class="page-link" data-page="{{ $p }}">{{ $p }}</button>
                    </li>
                @endfor
            </ul>
        </nav>
    @endif
@endif
