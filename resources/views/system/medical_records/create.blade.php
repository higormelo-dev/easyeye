@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
<div class="pmr-screen">
    {{-- ── Subnav ──────────────────────────────────────────────────────────── --}}
    <div class="row mb-3 align-items-center">
        <div class="col-12 col-auto">
            <div class="btn-group" role="group">
                <a href="{{ route('panel.patients.medicalrecords.index', $patient) }}" class="btn btn-outline-white btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>{{ __('actions.sidemenu.medical_records') }}
                </a>
                <a href="{{ route('panel.patients.medicalrecords.create', $patient) }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i>{{ __('actions.new') }}
                </a>
            </div>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-12 col-lg-3 col-xl-2">
            <div class="patient-info-sticky">
                @include('system.medical_records._patient-info')
            </div>
        </div>

        <div class="col-12 col-lg-9 col-xl-10">
            <div class="card pmr-content-card overflow-hidden bg-white">
                @include('system.medical_records._form', ['medicalrecord' => null])
            </div>
        </div>
    </div>
</div>
@endsection

@section('javascript')
@include('system.medical_records._form-styles')
@vite(['resources/js/system/medical-records.js'])
@endsection
