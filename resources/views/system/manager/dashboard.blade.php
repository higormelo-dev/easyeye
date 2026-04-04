@extends('layouts.app')

@push('styles')
    @vite(['resources/css/dashboard.css', 'resources/css/manager-dashboard.css'])
@endpush

@section('content')
<div class="page-dashboard">

    {{-- ══ Header / Welcome Banner ══════════════════════════════════════════ --}}
    @include('system.manager.dashboard._header')

    {{-- ══ KPIs ═════════════════════════════════════════════════════════════ --}}
    @include('system.manager.dashboard._kpis')

    {{-- ══ Linha: Funil de Assinaturas + Gráfico de Crescimento ════════════ --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            @include('system.manager.dashboard._subscriptions')
        </div>
        <div class="col-12 col-lg-6">
            @include('system.manager.dashboard._charts')
        </div>
    </div>

    {{-- ══ Linha: Trials Expirando + Parceiros ═════════════════════════════ --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-7">
            @include('system.manager.dashboard._trials-expiring')
        </div>
        <div class="col-12 col-lg-5">
            @include('system.manager.dashboard._partners-summary')
        </div>
    </div>

    {{-- ══ Últimas Clínicas ════════════════════════════════════════════════ --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            @include('system.manager.dashboard._recent-entities')
        </div>
    </div>

    {{-- ══ Top 5 Clínicas por Pacientes ════════════════════════════════════ --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card mgr-chart-card">
                <div class="card-header">
                    <i class="ti ti-trophy me-2"></i>{{ __('manager_dashboard.top_entities') }}
                </div>
                <div class="card-body p-0">
                    <table class="table mgr-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ __('manager_dashboard.col_entity') }}</th>
                                <th>{{ __('manager_dashboard.col_patients') }}</th>
                                <th>{{ __('manager_dashboard.col_date') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($stats['topEntities'] as $i => $entity)
                                <tr>
                                    <td>
                                        @if($i === 0)
                                            <span class="text-warning"><i class="ti ti-trophy"></i></span>
                                        @else
                                            <span class="text-muted">{{ $i + 1 }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $entity->name }}</div>
                                        <small class="text-muted">{{ $entity->code }}</small>
                                    </td>
                                    <td><span class="fw-bold">{{ number_format($entity->patients_count, 0, ',', '.') }}</span></td>
                                    <td><small>{{ $entity->created_at->format('d/m/Y') }}</small></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">{{ __('manager_dashboard.no_entities') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    function isDarkTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function growthChartPalette() {
        if (isDarkTheme()) {
            return {
                line: '#5da6ff',
                point: '#80bdff',
                pointStroke: '#0f1729',
                gridText: '#a7b8d6',
                gridLine: '#22334c',
            };
        }

        return {
            line: '#1976d2',
            point: '#1976d2',
            pointStroke: '#ffffff',
            gridText: '#64748b',
            gridLine: '#e2e8f0',
        };
    }

    function renderGrowthChart() {
        var chartEl = document.getElementById('mgr-chart-growth');
        if (typeof Morris === 'undefined' || !chartEl) {
            return;
        }

        chartEl.innerHTML = '';

        var labels = @json($stats['growthLabels']);
        var values = @json($stats['growthValues']);
        var chartData = [];
        for (var i = 0; i < labels.length; i++) {
            chartData.push({ month: labels[i], count: values[i] });
        }
        var palette = growthChartPalette();

        Morris.Line({
            element: 'mgr-chart-growth',
            data: chartData,
            xkey: 'month',
            ykeys: ['count'],
            labels: ['{{ __("manager_dashboard.chart_new_entities") }}'],
            lineColors: [palette.line],
            pointFillColors: [palette.point],
            pointStrokeColors: [palette.pointStroke],
            gridTextColor: palette.gridText,
            gridLineColor: palette.gridLine,
            gridTextSize: 12,
            hideHover: 'auto',
            resize: true,
            parseTime: false,
            fillOpacity: 0.15,
            behaveLikeLine: true,
            smooth: true,
        });
    }

    renderGrowthChart();

    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].attributeName === 'data-bs-theme') {
                    renderGrowthChart();
                    break;
                }
            }
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    }
});
</script>
@endpush
