@extends('layouts.app')

@push('styles')
    @vite('resources/css/dashboard.css')
@endpush

@section('content')

@php
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('dashboard.good_morning') : ($hour < 18 ? __('dashboard.good_afternoon') : __('dashboard.good_evening'));
    $greeting = $greeting ?: ($hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite'));
    $today = now()->translatedFormat('l, d \d\e F \d\e Y');
@endphp

{{-- ── Welcome Banner ─────────────────────────────────────────────────── --}}
<div class="welcome-banner mb-4 mt-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h4 class="mb-1">{{ $greeting }}, {{ explode(' ', auth()->user()->name)[0] }}! 👋</h4>
            <p>{{ ucfirst($today) }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap" style="position:relative;z-index:1">
            <a href="{{ route('panel.schedules.index') }}"
               class="btn btn-sm"
               style="background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.35);color:#fff;backdrop-filter:blur(4px);">
                <i class="fa fa-calendar me-1"></i> {{ __('actions.sidemenu.schedules') }}
            </a>
            <a href="{{ route('panel.patients.index') }}"
               class="btn btn-sm"
               style="background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.35);color:#fff;backdrop-filter:blur(4px);">
                <i class="fa fa-users me-1"></i> {{ __('actions.sidemenu.patients') }}
            </a>
        </div>
    </div>
</div>

{{-- ── KPI Cards ──────────────────────────────────────────────────────── --}}
<div class="row g-3 mb-4">

    {{-- Consultas Hoje --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #1976d2!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e3f2fd;color:#1976d2;">
                    <i class="fa fa-calendar-check-o"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['today_count'] }}</div>
                    <div class="stat-label">Consultas hoje</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Pacientes Ativos --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #388e3c!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e8f5e9;color:#388e3c;">
                    <i class="fa fa-users"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['total_patients'] }}</div>
                    <div class="stat-label">Pacientes ativos</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Médicos --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #c62828!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#fce4ec;color:#c62828;">
                    <i class="fa fa-user-md"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['total_doctors'] }}</div>
                    <div class="stat-label">Médicos</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Agendamentos Pendentes --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #f57f17!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#fff8e1;color:#f57f17;">
                    <i class="fa fa-clock-o"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['pending_today'] }}</div>
                    <div class="stat-label">Pendentes hoje</div>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ── Resumo do Dia ───────────────────────────────────────────────────── --}}
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa fa-clock-o me-2 text-warning"></i> Resumo de hoje</span>
        <a href="{{ route('panel.schedules.index') }}" class="btn btn-sm btn-outline-primary">
            Ver agenda <i class="fa fa-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="card-body p-3">
        @if($stats['today_count'] == 0)
            <p class="text-muted mb-0 text-center py-3">
                <i class="fa fa-calendar-o fa-2x d-block mb-2"></i>
                Nenhuma consulta agendada para hoje.
            </p>
        @else
            <div class="row text-center g-2">
                <div class="col-4">
                    <div class="fs-4 fw-bold text-primary">{{ $stats['today_count'] }}</div>
                    <div class="small text-muted">Total hoje</div>
                </div>
                <div class="col-4">
                    <div class="fs-4 fw-bold text-warning">{{ $stats['pending_today'] }}</div>
                    <div class="small text-muted">Aguardando</div>
                </div>
                <div class="col-4">
                    <div class="fs-4 fw-bold text-success">{{ $stats['today_count'] - $stats['pending_today'] }}</div>
                    <div class="small text-muted">Realizadas</div>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ── Quick Actions ──────────────────────────────────────────────────── --}}
<div class="card mb-4">
    <div class="card-header">
        <i class="fa fa-bolt text-warning me-2"></i> Ações Rápidas
    </div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                <a href="{{ route('panel.schedules.index') }}"
                   class="quick-action-btn w-100">
                    <i class="fa fa-calendar-plus-o" style="color:#1976d2;"></i>
                    <span>Novo Agendamento</span>
                </a>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                <a href="{{ route('panel.patients.index') }}"
                   class="quick-action-btn w-100">
                    <i class="fa fa-user-plus" style="color:#388e3c;"></i>
                    <span>Novo Paciente</span>
                </a>
            </div>

            @if(in_array(session('selected_entity_user_rule'), ['admin', 'secretary', 'doctor'], true))
            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                <a href="{{ route('panel.waiting-room.index') }}"
                   class="quick-action-btn w-100">
                    <i class="fa fa-hourglass-half" style="color:#f57f17;"></i>
                    <span>Sala de Espera</span>
                </a>
            </div>
            @endif

            @if(in_array(session('selected_entity_user_rule'), ['admin', 'secretary'], true))
            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                <a href="{{ route('panel.doctors.index') }}"
                   class="quick-action-btn w-100">
                    <i class="fa fa-user-md" style="color:#c62828;"></i>
                    <span>Médicos</span>
                </a>
            </div>
            @endif

        </div>
    </div>
</div>

@endsection
