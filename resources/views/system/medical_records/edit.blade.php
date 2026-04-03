@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- ── Subnav ──────────────────────────────────────────────────────────── --}}
<div class="row mb-3 align-items-center">
    <div class="col-12 col-md-auto d-flex gap-2">
        <a href="{{ route('panel.patients.medicalrecords.index', $patient) }}" class="btn btn-outline-white btn-sm">
            <i class="fas fa-arrow-left me-1"></i>{{ __('actions.medical_records.title') }}
        </a>
        <a href="{{ route('panel.patients.medicalrecords.pdf', [$patient, $medicalrecord]) }}"
           target="_blank" class="btn btn-outline-info btn-sm">
            <i class="fas fa-file-pdf me-1"></i>PDF
        </a>
    </div>
</div>

<div class="row g-3">

    {{-- ── Coluna lateral: info do paciente ─────────────────────────────── --}}
    <div class="col-12 col-md-3">
        <div class="patient-info-sticky">
            @include('system.medical_records._patient-info')
        </div>
    </div>

    {{-- ── Coluna principal: formulário ─────────────────────────────────── --}}
    <div class="col-12 col-md-9">
        <div class="card overflow-hidden" style="border-top:3px solid #26a69a;">
            {{-- Micro-strip: código + status (apenas no edit) --}}
            <div class="d-flex align-items-center justify-content-between px-3 py-1"
                 style="background:#f0fafa; border-bottom:1px solid #e0f2f1;">
                <span style="font-size:.72rem; color:#26a69a; font-style:italic;">
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

@endsection

@section('javascript')
@include('system.medical_records._form-styles')
@vite(['resources/js/system/medical-records.js'])
@endsection
