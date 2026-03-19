@use(App\Enums\ScheduleSituation)
@use(App\Enums\ClientRule)
@props(['schedule'])

@php
    $photo = $schedule->patient_id && $schedule->patient->photo
        ? asset('storage/images/patient/' . $schedule->patient->photo)
        : asset('system/images/team.png');

    $altName = $schedule->patient_id
        ? $schedule->patient->full_name
        : $schedule->full_name;

    $canMarkArrived = in_array(session('user_rule'), [ClientRule::Admin->value, ClientRule::Secretary->value], true)
        && $schedule->situation === ScheduleSituation::Scheduled;
@endphp

<div class="row mb-4">
    <div class="col-sm-2 col-md-2 col-lg-1 col-xl-1 d-flex align-items-center justify-content-center">
        <img src="{{ $photo }}"
             alt="{{ $altName }}"
             class="media-object img-circle w-50"
             style="border: 2px solid {{ $schedule->doctor->color }};">
    </div>

    <div class="col-sm-7 col-md-7 col-lg-8 col-xl-8 d-flex flex-column">
        <h4 class="m-b-0" style="color: {{ $schedule->doctor->color }};">
            {{ request()->get('search') ? $schedule->present()->getDateTime : $schedule->present()->getTime }}
            - {{ $schedule->full_name }}
            @if($schedule->patient_id)
                - {{ $schedule->patient->present()->getCode }}
            @endif
        </h4>

        <span class="text-muted">
            {{ $schedule->visit_id ? $schedule->visitType->name : strtoupper(__('actions.not_informed')) }}
            -
            {{ $schedule->covenant_id ? $schedule->covenant->name : strtoupper(__('actions.not_informed')) }}

            @if(!session()->get('doctor_id'))
                <br>
                {{ $schedule->doctor_id ? $schedule->doctor->abbreviation : strtoupper(__('actions.not_informed')) }}
            @endif
        </span>

        @if($schedule->situation !== ScheduleSituation::Scheduled)
            <div class="mt-1">
                <span class="badge {{ $schedule->situation->badgeClass() }}">
                    {{ $schedule->situation->label() }}
                </span>
            </div>
        @endif
    </div>

    <div class="col-sm-3 col-md-3 col-lg-3 d-flex align-items-center justify-content-end">
        <div class="d-flex gap-1" x-data>
            {{-- Editar agendamento --}}
            <button type="button"
                    class="btn btn-secondary btn-sm"
                    title="{{ __('actions.named.edit', ['name' => __('actions.schedule')]) }}"
                    @click="$dispatch('edit-schedule', { id: '{{ $schedule->id }}' })">
                <i class="fas fa-edit"></i>
            </button>

            @if($schedule->patient_id)
                {{-- Ir para ficha do paciente --}}
                <a href="javascript:;"
                   class="btn btn-secondary btn-sm btn-edit_patient"
                   data-id="{{ $schedule->id }}"
                   data-data="2"
                   data-doctor_id="{{ $schedule->doctor_id }}"
                   data-patient_id="{{ $schedule->patient_id }}"
                   title="{{ __('actions.named.edit', ['name' => __('actions.patient_record')]) }}">
                    <i class="fas fa-address-card"></i>
                </a>
            @else
                {{-- Cadastrar ficha --}}
                <a href="javascript:;"
                   class="btn btn-secondary btn-sm btn-arrives1"
                   data-id="{{ $schedule->id }}"
                   data-full_name="{{ $schedule->full_name }}"
                   data-data="2"
                   data-doctor_id="{{ $schedule->doctor_id }}"
                   title="{{ __('actions.register_record') }}">
                    <i class="fas fa-address-card"></i>
                </a>
            @endif

            @if($canMarkArrived)
                <button type="button"
                        class="btn btn-warning btn-sm btn-arrived"
                        data-id="{{ $schedule->id }}"
                        title="{{ __('actions.mark_arrival') }}">
                    <i class="fas fa-check-circle me-1"></i> {{ __('actions.arrived') }}
                </button>
            @endif

            {{-- Visualizar agendamento --}}
            <button type="button"
                    class="btn btn-secondary btn-sm btn-show"
                    data-id="{{ $schedule->id }}"
                    title="{{ __('actions.named.view', ['name' => __('actions.schedule')]) }}">
                <i class="fas fa-eye"></i>
            </button>
        </div>
    </div>
</div>
