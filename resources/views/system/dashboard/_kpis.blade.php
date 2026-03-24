{{-- ── Linha 1: KPIs Operacionais ────────────────────────────────────────── --}}
<div class="row g-3 mb-3">

    {{-- Pacientes --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #1976d2!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e3f2fd;color:#1976d2;">
                    <i class="fa fa-users"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['total_patients'] }}</div>
                    <div class="stat-label">{{ __('dashboard.kpi_patients') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Consultas Hoje --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #5c6bc0!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#ede7f6;color:#5c6bc0;">
                    <i class="fa fa-calendar-check-o"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['today_count'] }}</div>
                    <div class="stat-label">{{ __('dashboard.kpi_today') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Médicos Ativos --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100" style="border-top:3px solid #388e3c!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e8f5e9;color:#388e3c;">
                    <i class="fa fa-user-md"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $stats['total_doctors'] }}</div>
                    <div class="stat-label">{{ __('dashboard.kpi_doctors') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Cirurgias Hoje (mock) --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card stat-card-mock h-100" style="border-top:3px solid #7b1fa2!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#f3e5f5;color:#7b1fa2;">
                    <i class="fa fa-scissors"></i>
                </div>
                <div>
                    <div class="stat-value stat-value-mock">—</div>
                    <div class="stat-label">{{ __('dashboard.kpi_surgeries') }}</div>
                    <span class="stat-badge-soon">{{ __('dashboard.kpi_coming_soon') }}</span>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ── Linha 2: KPIs Clínicos / Financeiros ─────────────────────────────── --}}
<div class="row g-3 mb-4">

    {{-- Exames Pendentes (mock) --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card stat-card-mock h-100" style="border-top:3px solid #e65100!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#fbe9e7;color:#e65100;">
                    <i class="fa fa-eye"></i>
                </div>
                <div>
                    <div class="stat-value stat-value-mock">—</div>
                    <div class="stat-label">{{ __('dashboard.kpi_exams_pending') }}</div>
                    <span class="stat-badge-soon">{{ __('dashboard.kpi_coming_soon') }}</span>
                </div>
            </div>
        </div>
    </div>

    @if($rule !== 'doctor')
    {{-- Guias Aguardando (mock) --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card stat-card-mock h-100" style="border-top:3px solid #bf360c!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#fce4ec;color:#bf360c;">
                    <i class="fa fa-file-text-o"></i>
                </div>
                <div>
                    <div class="stat-value stat-value-mock">—</div>
                    <div class="stat-label">{{ __('dashboard.kpi_guides_waiting') }}</div>
                    <span class="stat-badge-soon">{{ __('dashboard.kpi_coming_soon') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- A Receber (mock) --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card stat-card-mock h-100" style="border-top:3px solid #00695c!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#e0f2f1;color:#00695c;">
                    <i class="fa fa-dollar"></i>
                </div>
                <div>
                    <div class="stat-value stat-value-mock">—</div>
                    <div class="stat-label">{{ __('dashboard.kpi_receivable') }}</div>
                    <span class="stat-badge-soon">{{ __('dashboard.kpi_coming_soon') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Satisfação (mock) --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card stat-card-mock h-100" style="border-top:3px solid #f57f17!important;">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon" style="background:#fff8e1;color:#f57f17;">
                    <i class="fa fa-star-o"></i>
                </div>
                <div>
                    <div class="stat-value stat-value-mock">—</div>
                    <div class="stat-label">{{ __('dashboard.kpi_satisfaction') }}</div>
                    <span class="stat-badge-soon">{{ __('dashboard.kpi_coming_soon') }}</span>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>
