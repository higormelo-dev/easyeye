@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

@php
    $kpis    = $summary['kpis'];
    $balance = $kpis['balance'];
    $rcvRate = $kpis['receipt_rate'];
    $attRate = $kpis['attendance_rate'];
    $occRate = $kpis['occupancy_rate'];
@endphp

{{-- ── Filtro de período ─────────────────────────────────────────────────── --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">{{ __('financial.period_from') }}</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">{{ __('financial.period_to') }}</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
            </div>
            <div class="col-auto ms-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="ti ti-filter me-1"></i>{{ __('financial.filter') }}
                </button>
                <a href="{{ route('panel.financial.bi.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="ti ti-refresh me-1"></i>{{ __('financial.current_month') }}
                </a>
            </div>
        </form>
    </div>
</div>

{{-- ── KPIs: Financeiro ─────────────────────────────────────────────────── --}}
<div class="row g-3 mb-3">
    {{-- Receita --}}
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 border-start border-success border-3">
            <div class="card-body px-3 py-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="avatar avatar-sm bg-success-subtle rounded-circle">
                        <i class="ti ti-trending-up text-success"></i>
                    </span>
                    <span class="small text-muted">{{ __('financial.bi.income') }}</span>
                </div>
                <div class="fs-5 fw-bold text-success">
                    R$ {{ number_format($kpis['income'], 2, ',', '.') }}
                </div>
                <div class="small text-muted mt-1">{{ __('financial.bi.income_sub') }}</div>
            </div>
        </div>
    </div>

    {{-- Despesas --}}
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 border-start border-danger border-3">
            <div class="card-body px-3 py-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="avatar avatar-sm bg-danger-subtle rounded-circle">
                        <i class="ti ti-trending-down text-danger"></i>
                    </span>
                    <span class="small text-muted">{{ __('financial.bi.expense') }}</span>
                </div>
                <div class="fs-5 fw-bold text-danger">
                    R$ {{ number_format($kpis['expense'], 2, ',', '.') }}
                </div>
                <div class="small text-muted mt-1">{{ __('financial.bi.expense_sub') }}</div>
            </div>
        </div>
    </div>

    {{-- Saldo --}}
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 border-start border-{{ $balance >= 0 ? 'primary' : 'warning' }} border-3">
            <div class="card-body px-3 py-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="avatar avatar-sm bg-{{ $balance >= 0 ? 'primary' : 'warning' }}-subtle rounded-circle">
                        <i class="ti ti-scale text-{{ $balance >= 0 ? 'primary' : 'warning' }}"></i>
                    </span>
                    <span class="small text-muted">{{ __('financial.bi.balance') }}</span>
                </div>
                <div class="fs-5 fw-bold text-{{ $balance >= 0 ? 'primary' : 'warning' }}">
                    R$ {{ number_format(abs($balance), 2, ',', '.') }}
                </div>
                <div class="small text-muted mt-1">{{ $balance >= 0 ? __('financial.bi.balance_positive') : __('financial.bi.balance_negative') }}</div>
            </div>
        </div>
    </div>

    {{-- Total Faturado TISS --}}
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 border-start border-info border-3">
            <div class="card-body px-3 py-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="avatar avatar-sm bg-info-subtle rounded-circle">
                        <i class="ti ti-file-invoice text-info"></i>
                    </span>
                    <span class="small text-muted">{{ __('financial.bi.billed') }}</span>
                </div>
                <div class="fs-5 fw-bold text-info">
                    R$ {{ number_format($kpis['total_billed'], 2, ',', '.') }}
                </div>
                <div class="small text-muted mt-1">{{ __('financial.bi.billed_sub') }}</div>
            </div>
        </div>
    </div>

    {{-- Glosas --}}
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 border-start border-{{ $kpis['total_glosa'] > 0 ? 'danger' : 'success' }} border-3">
            <div class="card-body px-3 py-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="avatar avatar-sm bg-{{ $kpis['total_glosa'] > 0 ? 'danger' : 'success' }}-subtle rounded-circle">
                        <i class="ti ti-ban text-{{ $kpis['total_glosa'] > 0 ? 'danger' : 'success' }}"></i>
                    </span>
                    <span class="small text-muted">{{ __('financial.bi.glosas') }}</span>
                </div>
                <div class="fs-5 fw-bold text-{{ $kpis['total_glosa'] > 0 ? 'danger' : 'success' }}">
                    R$ {{ number_format($kpis['total_glosa'], 2, ',', '.') }}
                </div>
                <div class="small text-muted mt-1">{{ __('financial.bi.glosas_sub') }}</div>
            </div>
        </div>
    </div>

    {{-- Ticket médio --}}
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 border-start border-secondary border-3">
            <div class="card-body px-3 py-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="avatar avatar-sm bg-secondary-subtle rounded-circle">
                        <i class="ti ti-receipt text-secondary"></i>
                    </span>
                    <span class="small text-muted">{{ __('financial.bi.avg_ticket') }}</span>
                </div>
                <div class="fs-5 fw-bold">
                    R$ {{ number_format($kpis['ticket_medio'], 2, ',', '.') }}
                </div>
                <div class="small text-muted mt-1">{{ __('financial.bi.avg_ticket_sub') }}</div>
            </div>
        </div>
    </div>
</div>

{{-- ── KPIs: Agenda e Pacientes ─────────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    {{-- Consultas realizadas --}}
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body px-3 py-3 text-center">
                <div class="fs-2 fw-bold text-primary">{{ $kpis['attended'] }}</div>
                <div class="small text-muted">{{ __('financial.bi.attended_count') }}</div>
            </div>
        </div>
    </div>

    {{-- Taxa de comparecimento --}}
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body px-3 py-3 text-center">
                <div class="fs-2 fw-bold text-{{ $attRate >= 80 ? 'success' : ($attRate >= 60 ? 'warning' : 'danger') }}">
                    {{ number_format($attRate, 1) }}%
                </div>
                <div class="small text-muted">{{ __('financial.bi.attendance_rate') }}</div>
                @if($kpis['noshow'] > 0)
                    <div class="small text-danger mt-1">{{ $kpis['noshow'] }} {{ __('financial.bi.absences') }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Taxa de ocupação --}}
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body px-3 py-3 text-center">
                <div class="fs-2 fw-bold text-{{ $occRate >= 70 ? 'success' : ($occRate >= 50 ? 'warning' : 'danger') }}">
                    {{ number_format($occRate, 1) }}%
                </div>
                <div class="small text-muted">{{ __('financial.bi.occupancy_rate') }}</div>
                <div class="small text-muted mt-1">{{ $kpis['total_schedules'] }} {{ __('financial.bi.schedules') }}</div>
            </div>
        </div>
    </div>

    {{-- Novos pacientes --}}
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body px-3 py-3 text-center">
                <div class="fs-2 fw-bold" style="color: #6f42c1;">{{ $kpis['new_patients'] }}</div>
                <div class="small text-muted">{{ __('financial.bi.new_patients') }}</div>
                <div class="small text-muted mt-1">{{ __('financial.bi.new_patients_sub') }}</div>
            </div>
        </div>
    </div>
</div>

{{-- ── Gráficos: Tendência + Mix de atendimentos ──────────────────────── --}}
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold d-flex align-items-center justify-content-between">
                <span><i class="ti ti-chart-line me-2 text-muted"></i>{{ __('financial.bi.monthly_trend') }}</span>
                <span class="badge bg-primary-subtle text-primary small">{{ __('financial.bi.monthly_trend_sub') }}</span>
            </div>
            <div class="card-body">
                @if(collect($trend)->sum('income') + collect($trend)->sum('expense') > 0)
                    <div id="bi-chart-trend" style="height: 240px;"></div>
                @else
                    <div class="d-flex align-items-center justify-content-center text-muted" style="height: 240px;">
                        <div class="text-center">
                            <i class="ti ti-chart-line fs-1 d-block mb-2"></i>
                            {{ __('financial.bi.no_financial_data') }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">
                <i class="ti ti-chart-donut me-2 text-muted"></i>{{ __('financial.bi.attendance_mix') }}
            </div>
            <div class="card-body">
                @if(count($summary['schedule_chart']) > 0)
                    <div id="bi-chart-schedule" style="height: 240px;"></div>
                @else
                    <div class="d-flex align-items-center justify-content-center text-muted" style="height: 240px;">
                        <div class="text-center">
                            <i class="ti ti-calendar-off fs-1 d-block mb-2"></i>
                            {{ __('financial.bi.no_schedules') }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ── Gráfico: Faturamento por Convênio ───────────────────────────────── --}}
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">
                <i class="ti ti-chart-bar me-2 text-muted"></i>{{ __('financial.bi.billing_by_covenant') }}
            </div>
            <div class="card-body">
                @if(count($summary['by_covenant_chart']) > 0)
                    <div id="bi-chart-covenant" style="height: 240px;"></div>
                @else
                    <div class="d-flex align-items-center justify-content-center text-muted" style="height: 240px;">
                        <div class="text-center">
                            <i class="ti ti-building-hospital fs-1 d-block mb-2"></i>
                            {{ __('financial.bi.no_claims') }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Resumo TISS --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">
                <i class="ti ti-report-money me-2 text-muted"></i>{{ __('financial.bi.tiss_summary') }}
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-7 text-muted fw-normal">{{ __('financial.bi.total_billed') }}</dt>
                    <dd class="col-5 text-end fw-semibold">R$ {{ number_format($kpis['total_billed'], 2, ',', '.') }}</dd>

                    <dt class="col-7 text-muted fw-normal">{{ __('financial.bi.total_received') }}</dt>
                    <dd class="col-5 text-end fw-semibold text-success">R$ {{ number_format($kpis['total_paid'], 2, ',', '.') }}</dd>

                    <dt class="col-7 text-muted fw-normal">{{ __('financial.bi.total_glosa') }}</dt>
                    <dd class="col-5 text-end fw-semibold {{ $kpis['total_glosa'] > 0 ? 'text-danger' : '' }}">
                        R$ {{ number_format($kpis['total_glosa'], 2, ',', '.') }}
                    </dd>

                    <dt class="col-12"><hr class="my-2"></dt>

                    <dt class="col-7 text-muted fw-normal">{{ __('financial.bi.receipt_rate') }}</dt>
                    <dd class="col-5 text-end fw-bold text-{{ $rcvRate >= 80 ? 'success' : ($rcvRate >= 60 ? 'warning' : 'danger') }}">
                        {{ number_format($rcvRate, 1) }}%
                    </dd>

                    <dt class="col-7 text-muted fw-normal">{{ __('financial.bi.avg_ticket_paid') }}</dt>
                    <dd class="col-5 text-end fw-semibold">R$ {{ number_format($kpis['ticket_medio'], 2, ',', '.') }}</dd>

                    <dt class="col-12"><hr class="my-2"></dt>

                    <dt class="col-12 mb-2">
                        <a href="{{ route('panel.financial.billing.index') }}" class="btn btn-outline-info btn-sm w-100">
                            <i class="ti ti-external-link me-1"></i>{{ __('financial.bi.open_billing') }}
                        </a>
                    </dt>
                    @if($kpis['total_glosa'] > 0)
                    <dt class="col-12">
                        <a href="{{ route('panel.financial.tiss.glosas.index') }}" class="btn btn-outline-danger btn-sm w-100">
                            <i class="ti ti-gavel me-1"></i>{{ __('financial.bi.see_glosas') }}
                        </a>
                    </dt>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {

    function isDark() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function gridColors() {
        return {
            text:  isDark() ? '#a7b8d6' : '#64748b',
            lines: isDark() ? '#22334c' : '#e2e8f0',
        };
    }

    // ── Tendência Mensal ──────────────────────────────────────────────────────
    var trendEl = document.getElementById('bi-chart-trend');
    if (typeof Morris !== 'undefined' && trendEl) {
        var trendData = @json($trend);
        Morris.Area({
            element: 'bi-chart-trend',
            data: trendData,
            xkey: 'period',
            ykeys: ['income', 'expense'],
            labels: [@json(__('financial.bi.chart_income')), @json(__('financial.bi.chart_expense'))],
            lineColors: ['#198754', '#dc3545'],
            fillOpacity: 0.15,
            pointSize: 3,
            hideHover: 'auto',
            parseTime: false,
            behaveLikeLine: false,
            smooth: true,
            resize: true,
            gridTextColor: gridColors().text,
            gridLineColor: gridColors().lines,
            gridTextSize: 11,
            yLabelFormat: function (y) { return 'R$ ' + y.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
        });
    }

    // ── Mix de Atendimentos (Donut) ───────────────────────────────────────────
    var scheduleEl = document.getElementById('bi-chart-schedule');
    if (typeof Morris !== 'undefined' && scheduleEl) {
        var scheduleData = @json($summary['schedule_chart']);
        if (scheduleData.length > 0) {
            Morris.Donut({
                element: 'bi-chart-schedule',
                data: scheduleData,
                colors: ['#198754', '#dc3545', '#6c757d'],
                labelColor: gridColors().text,
                resize: true,
            });
        }
    }

    // ── Faturamento por Convênio (Bar) ────────────────────────────────────────
    var covenantEl = document.getElementById('bi-chart-covenant');
    if (typeof Morris !== 'undefined' && covenantEl) {
        var covenantData = @json($summary['by_covenant_chart']);
        if (covenantData.length > 0) {
            Morris.Bar({
                element: 'bi-chart-covenant',
                data: covenantData,
                xkey: 'label',
                ykeys: ['value'],
                labels: [@json(__('financial.bi.chart_billed'))],
                barColors: ['#0d6efd'],
                hideHover: 'auto',
                parseTime: false,
                resize: true,
                gridTextColor: gridColors().text,
                gridLineColor: gridColors().lines,
                gridTextSize: 11,
                yLabelFormat: function (y) { return 'R$ ' + y.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
            });
        }
    }

    // Redesenha ao trocar dark/light mode
    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].attributeName === 'data-bs-theme') {
                    window.location.reload();
                    break;
                }
            }
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    }
});
</script>
@endpush
