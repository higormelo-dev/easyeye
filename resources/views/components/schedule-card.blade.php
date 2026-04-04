@use(App\Enums\ScheduleSituation)
@use(App\Enums\PatientMood)
@use(App\Enums\ClientRule)
@props(['schedule'])

@php
    $photo = $schedule->patient_id && $schedule->patient?->photo
        ? asset('storage/images/patient/' . $schedule->patient->photo)
        : asset('system/images/team.png');

    $altName = $schedule->patient_id
        ? $schedule->patient->full_name
        : $schedule->full_name;

    $userRule    = session('user_rule');
    $isStaff     = in_array($userRule, [ClientRule::Admin->value, ClientRule::Secretary->value], true);
    $situation   = $schedule->situation;
    $isTerminal  = $situation->isTerminal();
    $doctorColor = $schedule->doctor->color ?: '#6c757d';
@endphp

<div class="card mb-2 border schedule-card{{ $isTerminal ? ' opacity-65 bg-light' : '' }}"
     data-schedule-id="{{ $schedule->id }}"
     style="border-left-color: {{ $doctorColor }} !important;">
    <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-3">

            {{-- Checkbox de seleção em massa (visível apenas em .selection-mode) --}}
            @if($isStaff)
                <div class="schedule-select-cb flex-shrink-0">
                    <input type="checkbox"
                           class="form-check-input schedule-checkbox"
                           data-id="{{ $schedule->id }}"
                           style="width:1.2rem;height:1.2rem;cursor:pointer;">
                </div>
            @endif

            {{-- Foto (só md+) --}}
            <div class="d-none d-md-block flex-shrink-0">
                <img src="{{ $photo }}"
                     alt="{{ $altName }}"
                     class="rounded-circle"
                     width="46" height="46"
                     style="object-fit:cover;border:2px solid {{ $doctorColor }};">
            </div>

            {{-- Informações --}}
            <div class="flex-grow-1 schedule-card-info">

                {{-- Linha 1: horário + nome + código --}}
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <strong class="fs-6" style="color:{{ $doctorColor }};">
                        {{ request()->get('search') ? $schedule->present()->getDateTime : $schedule->present()->getTime }}
                    </strong>
                    <span class="fw-semibold">{{ $schedule->full_name }}</span>
                    @if($schedule->patient_id)
                        <small class="text-muted">({{ $schedule->patient->present()->getCode }})</small>
                    @endif
                </div>

                {{-- Linha 3: metadados --}}
                <div class="text-muted small mt-1">
                    {{ $schedule->visit_id ? $schedule->visitType->name : strtoupper(__('actions.not_informed')) }}
                    &mdash;
                    {{ $schedule->covenant_id ? $schedule->covenant->name : strtoupper(__('actions.not_informed')) }}
                    @if(! session()->get('doctor_id'))
                        &mdash; {{ $schedule->doctor->abbreviation }}
                    @endif
                </div>
                {{-- Linha 2: badges de estado --}}
                <div class="d-flex align-items-center gap-1 flex-wrap mt-1">

                    {{-- Badge situação --}}
                    <span class="badge {{ $situation->badgeClass() }}">
                        <i class="fas {{ $situation->icon() }} me-1"></i>{{ $situation->label() }}
                    </span>

                    {{-- Confirmado --}}
                    @if($schedule->confirmed_at)
                        <span class="badge bg-info text-dark"
                              title="Confirmado em {{ $schedule->confirmed_at->format('d/m H:i') }}">
                            <i class="fas fa-check-circle"></i>
                        </span>
                    @endif

                    {{-- Observações --}}
                    @if($schedule->notes)
                        <span class="badge bg-light text-secondary border"
                              title="{{ $schedule->notes }}">
                            <i class="fas fa-sticky-note"></i>
                        </span>
                    @endif

                    {{-- Humor do paciente --}}
                    @if($schedule->patient_mood)
                        <span class="badge {{ $schedule->patient_mood->badgeClass() }}"
                              title="{{ $schedule->patient_mood->label() }}">
                            <i class="fas {{ $schedule->patient_mood->icon() }}"></i>
                        </span>
                    @endif

                    {{-- Timer de espera --}}
                    @if($situation === ScheduleSituation::Waiting && $schedule->arrived_at)
                        <span class="badge waiting-timer"
                              data-arrived="{{ $schedule->arrived_at->toIso8601String() }}">
                            <i class="fas fa-clock me-1"></i><span class="timer-text">...</span>
                        </span>
                    @endif

                </div>

            </div>

            {{-- Ações --}}
            <div class="d-flex align-items-center float-end gap-1 flex-shrink-0">

                {{-- ── Dropdown de situação (fa-list-ul) ── --}}
                @if(! $isTerminal && $isStaff && count($situation->allowedTransitions()) > 0)
                    <div class="btn-group">
                        <button type="button"
                                class="btn btn-light btn-sm dropdown-toggle"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                title="{{ __('actions.change_situation') }}">
                            <i class="fas fa-list-ul"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            @foreach($situation->allowedTransitions() as $toValue)
                                @php $toSituation = ScheduleSituation::from($toValue); @endphp
                                @if($toSituation === ScheduleSituation::Cancelled)
                                    <li>
                                        <button type="button"
                                                class="dropdown-item btn-cancel"
                                                data-id="{{ $schedule->id }}">
                                            <i class="fas fa-circle {{ $toSituation->circleClass() }} me-2"></i>
                                            {{ strtoupper($toSituation->label()) }}
                                        </button>
                                    </li>
                                @else
                                    <li>
                                        <button type="button"
                                                class="dropdown-item btn-situation"
                                                data-id="{{ $schedule->id }}"
                                                data-to="{{ $toValue }}">
                                            <i class="fas fa-circle {{ $toSituation->circleClass() }} me-2"></i>
                                            {{ strtoupper($toSituation->label()) }}
                                        </button>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- ── Dropdown de humor do paciente (fa-smile) ── --}}
                @if(! $isTerminal && $isStaff && $schedule->patient_id)
                    <div class="dropdown">
                        <button type="button"
                                class="btn btn-light btn-sm dropdown-toggle"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                title="{{ __('actions.patient_mood_label') }}">
                            @if($schedule->patient_mood)
                                <i class="fas {{ $schedule->patient_mood->icon() }}"></i>
                            @else
                                <i class="fas fa-theater-masks"></i>
                            @endif
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <button type="button"
                                        class="dropdown-item btn-mood"
                                        data-id="{{ $schedule->id }}"
                                        data-mood="">
                                    <i class="fas fa-times text-muted me-2"></i> {{ __('actions.clear') }}
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            @foreach(PatientMood::cases() as $mood)
                                <li>
                                    <button type="button"
                                            class="dropdown-item btn-mood{{ $schedule->patient_mood === $mood ? ' fw-bold' : '' }}"
                                            data-id="{{ $schedule->id }}"
                                            data-mood="{{ $mood->value }}">
                                        <i class="fas {{ $mood->icon() }} {{ $mood->textClass() }} me-2"></i>
                                        {{ $mood->label() }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Separador visual --}}
                @if(! $isTerminal)
                    <div class="vr mx-1 d-none d-sm-block"></div>
                @endif

                {{-- ── Navegação ── --}}

                @if(! $isTerminal)
                    <button type="button"
                            class="btn btn-light btn-sm"
                            title="{{ __('actions.named.edit', ['name' => __('actions.schedule')]) }}"
                            @click="$dispatch('edit-schedule', { id: '{{ $schedule->id }}' })"
                            x-data>
                        <i class="fas fa-edit"></i>
                    </button>
                @endif

                @if($schedule->patient_id)
                    <button type="button"
                            class="btn btn-light btn-sm btn-patient"
                            data-id="{{ $schedule->patient_id }}"
                            title="{{ __('actions.named.edit', ['name' => __('actions.patient_record')]) }}">
                        <i class="fas fa-address-card"></i>
                    </button>
                    <a href="{{ route('panel.patients.medicalrecords.index', $schedule->patient_id) }}"
                       class="btn btn-light btn-sm"
                       title="{{ __('actions.medical_records.title') }}">
                        <i class="fas fa-file-medical"></i>
                    </a>
                @endif

                <button type="button"
                        class="btn btn-light btn-sm btn-show"
                        data-id="{{ $schedule->id }}"
                        title="{{ __('actions.named.view', ['name' => __('actions.schedule')]) }}">
                    <i class="fas fa-eye"></i>
                </button>

                {{-- ── Dropdown ⋮ (Reagendar) ── --}}
                @if(! $isTerminal && $isStaff)
                    <div class="dropdown">
                        <button type="button"
                                class="btn btn-outline-light btn-sm"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                title="{{ __('actions.more_options') }}">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <button type="button"
                                        class="dropdown-item btn-reschedule"
                                        data-id="{{ $schedule->id }}"
                                        data-doctor_id="{{ $schedule->doctor_id }}"
                                        data-doctors="{{ json_encode($schedule->doctor ? [['id' => $schedule->doctor_id, 'name' => $schedule->doctor->abbreviation]] : []) }}">
                                    <i class="fas fa-calendar-alt me-2"></i>{{ __('actions.reschedule_action') }}
                                </button>
                            </li>
                        </ul>
                    </div>
                @endif

            </div>
        </div>
    </div>
</div>
