@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- ── Subnav ──────────────────────────────────────────────────────────── --}}
<div class="row mb-3 align-items-center">
    <div class="col-12 col-md-auto">
        <a href="{{ route('panel.patients.medicalrecords.index', $patient) }}" class="btn btn-outline-white btn-sm">
            <i class="fas fa-arrow-left me-1"></i>{{ __('actions.medical_records.title') }}
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
            @include('system.medical_records._form', ['medicalrecord' => null])
        </div>
    </div>

</div>

@endsection

@section('javascript')
@include('system.medical_records._form-styles')
@vite(['resources/js/system/medical-records.js'])
