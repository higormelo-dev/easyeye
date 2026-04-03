{{-- ── KPIs Primários ───────────────────────────────────────────────────── --}}

{{-- Linha 1: Clínicas, Ativas, Trial, MRR --}}
<div class="row g-3 mb-3">

    {{-- Total Clínicas --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #1976d2!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e3f2fd;color:#1976d2;">
                    <i class="ti ti-building"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['totalEntities'] }}</div>
                    <div class="stat-label">{{ __('manager_dashboard.kpi_entities') }}</div>
                    <div class="d-flex gap-2 mt-1">
                        <span class="period-badge">
                            {{ __('manager_dashboard.today') }}
                            <span class="period-value">+{{ $stats['newEntitiesToday'] }}</span>
                        </span>
                        <span class="period-badge">
                            {{ __('manager_dashboard.month') }}
                            <span class="period-value">+{{ $stats['newEntitiesMonth'] }}</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Assinaturas Ativas --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #388e3c!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e8f5e9;color:#388e3c;">
                    <i class="ti ti-circle-check"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['subscriptionCounts'][\App\Enums\SubscriptionStatus::Active->value] ?? 0 }}</div>
                    <div class="stat-label">{{ __('manager_dashboard.kpi_active_subs') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Em Trial --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #f57c00!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#fff3e0;color:#f57c00;">
                    <i class="ti ti-clock-hour-4"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['subscriptionCounts'][\App\Enums\SubscriptionStatus::Trial->value] ?? 0 }}</div>
                    <div class="stat-label">{{ __('manager_dashboard.kpi_trial') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- MRR Estimado --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #7b1fa2!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#f3e5f5;color:#7b1fa2;">
                    <i class="ti ti-currency-real"></i>
                </div>
                <div>
                    <div class="stat-value">R$ {{ number_format($stats['mrr'], 0, ',', '.') }}</div>
                    <div class="stat-label">{{ __('manager_dashboard.kpi_mrr') }}</div>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- Linha 2: Pacientes, Médicos, Prontuários, Agendamentos --}}
<div class="row g-3 mb-4">

    {{-- Total Pacientes --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #0d47a1!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e3f2fd;color:#0d47a1;">
                    <i class="ti ti-users"></i>
                </div>
                <div>
                    <div class="stat-value">{{ number_format($stats['totalPatients'], 0, ',', '.') }}</div>
                    <div class="stat-label">{{ __('manager_dashboard.kpi_patients') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Total Médicos --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #2e7d32!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e8f5e9;color:#2e7d32;">
                    <i class="ti ti-stethoscope"></i>
                </div>
                <div>
                    <div class="stat-value">{{ number_format($stats['totalDoctors'], 0, ',', '.') }}</div>
                    <div class="stat-label">{{ __('manager_dashboard.kpi_doctors') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Total Prontuários --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #00695c!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e0f2f1;color:#00695c;">
                    <i class="ti ti-file-text"></i>
                </div>
                <div>
                    <div class="stat-value">{{ number_format($stats['totalMedicalRecords'], 0, ',', '.') }}</div>
                    <div class="stat-label">{{ __('manager_dashboard.kpi_records') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Agendamentos --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #5c6bc0!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#ede7f6;color:#5c6bc0;">
                    <i class="ti ti-calendar-event"></i>
                </div>
                <div>
                    <div class="stat-value">{{ number_format($stats['totalSchedules'], 0, ',', '.') }}</div>
                    <div class="stat-label">{{ __('manager_dashboard.kpi_schedules') }}</div>
                    <div class="d-flex gap-2 mt-1">
                        <span class="period-badge">
                            {{ __('manager_dashboard.today') }}
                            <span class="period-value">{{ $stats['schedulesToday'] }}</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
