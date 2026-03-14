@props(['schedule'])

@php
    $photo = $schedule->patient_id && $schedule->patient->photo
        ? asset('storage/images/patient/' . $schedule->patient->photo)
        : asset('system/images/team.png');

    $altName = $schedule->patient_id
        ? $schedule->patient->full_name
        : $schedule->full_name;
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
            {{ $schedule->visit_id ? $schedule->visitType->name : 'NÃO INFORMADO' }}
            -
            {{ $schedule->covenant_id ? $schedule->covenant->name : 'NÃO INFORMADO' }}

            @if(!session()->get('doctor_id'))
                <br>
                {{ $schedule->doctor_id ? $schedule->doctor->abbreviation : 'NÃO INFORMADO' }}
            @endif
        </span>
    </div>

    <div class="col-sm-3 col-md-3 col-lg-3 d-flex align-items-center justify-content-end">
        <div class="d-flex gap-1" x-data>
            {{-- Editar agendamento --}}
            <button type="button"
                    class="btn btn-secondary btn-sm"
                    title="Editar agendamento"
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
                   title="Alterar ficha">
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
                   title="Cadastrar ficha">
                    <i class="fas fa-address-card"></i>
                </a>
            @endif
        </div>
    </div>
</div>
