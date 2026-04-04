{{-- ── Linha 1: KPIs Operacionais ────────────────────────────────────────── --}}
<div class="row g-3 mb-3">

    {{-- Pacientes --}}
    <div class="col-6 col-md-3">
        <div class="card stat-card stat-card--patients h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon stat-icon--patients">
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
        <div class="card stat-card stat-card--today h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon stat-icon--today">
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
        <div class="card stat-card stat-card--doctors h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon stat-icon--doctors">
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
        <div class="card stat-card stat-card--surgeries stat-card-mock h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon stat-icon--surgeries">
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
        <div class="card stat-card stat-card--exams stat-card-mock h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon stat-icon--exams">
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
        <div class="card stat-card stat-card--guides stat-card-mock h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon stat-icon--guides">
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
        <div class="card stat-card stat-card--receivable stat-card-mock h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon stat-icon--receivable">
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
        <div class="card stat-card stat-card--satisfaction stat-card-mock h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="stat-icon stat-icon--satisfaction">
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
