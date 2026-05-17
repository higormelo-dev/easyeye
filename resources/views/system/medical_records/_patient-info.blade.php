@php
    $photoPath = $patient->person?->photo
        ? 'storage/images/patient/' . $patient->person->photo
        : null;
    $photo = $photoPath && file_exists(public_path($photoPath))
        ? asset($photoPath)
        : Vite::asset('resources/img/system/team.png');
@endphp

<div class="card mb-0">
    <img src="{{ $photo }}"
         alt="{{ $patient->person?->full_name }}"
         class="card-img-top"
         style="object-fit:cover; max-height:180px;">

    <div class="card-body p-3">

        {{-- Código e nome --}}
        <p class="text-muted small mb-1">{{ $patient->code }}</p>
        <h6 class="mb-3 fw-semibold">{{ $patient->person?->full_name }}</h6>

        <hr class="my-2">

        {{-- Gênero e idade --}}
        <div class="row g-2 mb-2">
            <div class="col-6">
                <div class="text-muted" style="font-size:.7rem; text-transform:uppercase; letter-spacing:.04em;">
                    {{ __('actions.gender') }}
                </div>
                <div class="small">{{ $patient->person?->present()->getGender ?: '—' }}</div>
            </div>
            <div class="col-6">
                <div class="text-muted" style="font-size:.7rem; text-transform:uppercase; letter-spacing:.04em;">
                    {{ __('actions.age.label') }}
                </div>
                <div class="small">{{ $patient->person?->present()->getAge ?: '—' }}</div>
            </div>
        </div>

        {{-- Convênio --}}
        @if($patient->covenant)
            <div class="mb-2">
                <div class="text-muted" style="font-size:.7rem; text-transform:uppercase; letter-spacing:.04em;">
                    {{ __('actions.medical_records.covenant') }}
                </div>
                <div class="small">{{ $patient->covenant->name }}</div>
            </div>
        @endif

        {{-- Tipo de pele --}}
        @if($patient->skinType)
            <div class="mb-2">
                <div class="text-muted" style="font-size:.7rem; text-transform:uppercase; letter-spacing:.04em;">
                    {{ __('actions.medical_records.skin') }}
                </div>
                <div class="small">{{ $patient->skinType->name }}</div>
            </div>
        @endif

        {{-- Tipo de iris --}}
        @if($patient->irisType)
            <div class="mb-2">
                <div class="text-muted" style="font-size:.7rem; text-transform:uppercase; letter-spacing:.04em;">
                    {{ __('actions.medical_records.iris') }}
                </div>
                <div class="small">{{ $patient->irisType->name }}</div>
            </div>
        @endif

        {{-- Carteirinha --}}
        @if($patient->card_number)
            <div class="mb-2">
                <div class="text-muted" style="font-size:.7rem; text-transform:uppercase; letter-spacing:.04em;">
                    {{ __('actions.medical_records.card_number') }}
                </div>
                <div class="small font-monospace">{{ $patient->card_number }}</div>
            </div>
        @endif

        <hr class="my-2">

        {{-- Status ativo/inativo --}}
        <div>
            @if($patient->active)
                <span class="badge bg-success">{{ __('actions.active') }}</span>
            @else
                <span class="badge bg-secondary">{{ __('actions.inactive') }}</span>
            @endif
        </div>

    </div>
</div>
