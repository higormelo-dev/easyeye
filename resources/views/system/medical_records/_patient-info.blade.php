@php
    $photoPath = $patient->person?->photo
        ? 'storage/images/patient/' . $patient->person->photo
        : null;
    $photo = $photoPath && file_exists(public_path($photoPath))
        ? asset($photoPath)
        : asset('system/images/team.png');
@endphp

<div class="btn-group w-100 mb-3" role="group">
    <a href="{{ route('panel.patients.index') }}"
       class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-arrow-left me-1"></i> {{ __('actions.sidemenu.patients') }}
    </a>
    <a href="{{ route('panel.patients.medicalrecords.create', $patient) }}"
       class="btn btn-info btn-sm">
        <i class="fa fa-plus me-1"></i> {{ __('actions.new') }}
    </a>
</div>

<div class="card mb-3">
    <img src="{{ $photo }}"
         alt="{{ $patient->person?->full_name }}"
         class="card-img-top"
         style="object-fit:cover;">
    <div class="card-body p-2">
        <p class="mb-1 text-muted small">{{ $patient->code }}</p>
        <div class="row m-t-10">
            <div class="col-12">
                <strong>{{ __('actions.patient') }}</strong>
                <p>{{ $patient->person?->full_name }}</p>
            </div>
        </div>
        <div class="row m-t-10">
            <div class="col-md-6 b-r"><strong>{{ __('actions.gender') }}</strong>
                <p>{{ $patient->person?->present()->getGender }}</p>
            </div>
            <div class="col-md-6"><strong>{{ __('actions.age.label') }}</strong>
                <p>{{ $patient->person?->present()->getAge }}</p>
            </div>
        </div>
    </div>
</div>
