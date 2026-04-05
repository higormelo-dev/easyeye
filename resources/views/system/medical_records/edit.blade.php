@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
<div class="pmr-screen">
    <div class="pmr-toolbar mb-2">
        <a href="{{ route('panel.patients.medicalrecords.index', $patient) }}" class="btn pmr-toolbar-btn">
            <i class="fas fa-reply me-1"></i>Voltar
        </a>
        <a href="{{ route('panel.patients.medicalrecords.create', $patient) }}" class="btn pmr-toolbar-btn pmr-toolbar-btn-new">
            + Novo
        </a>
        <a href="{{ route('panel.patients.medicalrecords.pdf', [$patient, $medicalrecord]) }}"
           target="_blank" class="btn pmr-toolbar-btn">
            <i class="fas fa-file-pdf me-1"></i>PDF
        </a>
    </div>

    <div class="row g-2">
        <div class="col-12 col-lg-3 col-xl-2">
            <div class="patient-info-sticky">
                @include('system.medical_records._patient-info')
            </div>
        </div>

        <div class="col-12 col-lg-9 col-xl-10">
            <div class="card pmr-content-card overflow-hidden">
                <div class="d-flex align-items-center justify-content-between px-3 py-1 pmr-record-strip">
                    <span>
                        <i class="fas fa-file-medical-alt me-1"></i>{{ $medicalrecord->code }}
                    </span>
                    @if($medicalrecord->isLocked())
                    <span class="badge bg-warning text-dark" style="font-size:.65rem;">
                        <i class="fas fa-lock me-1"></i>{{ __('actions.medical_records.locked') }}
                    </span>
                    @endif
                </div>

                @include('system.medical_records._form')
            </div>
        </div>
    </div>
</div>
@endsection

@section('javascript')
@include('system.medical_records._form-styles')
@vite(['resources/js/system/medical-records.js'])
@endsection
