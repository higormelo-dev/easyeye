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
    // ── Gráfico de Crescimento (Morris.js Line) ─────────────────────────
    if (typeof Morris !== 'undefined' && document.getElementById('mgr-chart-growth')) {
        var labels = @json($stats['growthLabels']);
        var values = @json($stats['growthValues']);
        var chartData = [];
        for (var i = 0; i < labels.length; i++) {
            chartData.push({ month: labels[i], count: values[i] });
        }
        Morris.Line({
            element: 'mgr-chart-growth',
            data: chartData,
            xkey: 'month',
            ykeys: ['count'],
            labels: ['{{ __("manager_dashboard.chart_new_entities") }}'],
            lineColors: ['#1976d2'],
            pointFillColors: ['#1976d2'],
            pointStrokeColors: ['#fff'],
            gridTextColor: '#64748b',
            gridTextSize: 12,
            hideHover: 'auto',
            resize: true,
            parseTime: false,
            fillOpacity: 0.15,
            behaveLikeLine: true,
            smooth: true,
        });
    }
});
</script>
@endpush
