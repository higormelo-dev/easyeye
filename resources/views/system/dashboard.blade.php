@extends('layouts.app')

@push('styles')
    @vite('resources/css/dashboard.css')
@endpush

@section('content')
@php
    $hour     = now()->hour;
    $greeting = __('dashboard.' . ($hour < 12 ? 'greeting_morning' : ($hour < 18 ? 'greeting_afternoon' : 'greeting_evening')));
    $rule     = session('selected_entity_user_rule');
@endphp

<div class="page-dashboard">
    @include('system.dashboard._header')

    @include('system.dashboard._activation')

    @include('system.dashboard._kpis')

    @include('system.dashboard._modules')

    <div class="row g-3 mb-4">
        <div class="col-md-8">
            @include('system.dashboard._patients-list')
        </div>
        <div class="col-md-4">
            @include('system.dashboard._day-summary')
        </div>
    </div>

    @include('system.dashboard._demo-block')
</div>

@endsection
