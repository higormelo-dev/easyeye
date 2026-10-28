<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Financial\ClinicBiService;
use App\Support\ReportPeriod;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};

class ClinicBiController extends Controller
{
    public function __construct(
        private readonly ClinicBiService $biService,
    ) {
    }

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        $entityId = (string) $entity->id;

        // Data inválida (?from=abc, from[]=x) cai no mês atual e período
        // invertido é trocado — nunca chega ao PostgreSQL nem à chave de cache.
        [$from, $to] = ReportPeriod::resolve($request->query('from'), $request->query('to'));

        // "Atualizar": descarta o cache desta clínica/período e volta para a
        // URL limpa (recarregar a página depois não força outro recálculo).
        if ($request->boolean('refresh')) {
            $this->biService->forget($entityId, $from, $to);

            return redirect()->route('panel.financial.bi.index', ['from' => $from, 'to' => $to]);
        }

        $summary = $this->biService->summary($entityId, $from, $to);
        $trend   = $this->biService->trendSnapshot($entityId);

        return Inertia::render('Panel/Financial/Bi/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
                ['label' => __('financial_bi.title'), 'url' => '#', 'active' => true],
            ],
            'entity'  => ['id' => $entity->id, 'name' => $entity->name],
            'filters' => ['from' => $from, 'to' => $to],
            // "Hoje" do servidor (fuso da aplicação) para os atalhos do PeriodFilter.
            'today'   => now()->toDateString(),
            'summary' => $summary,
            'trend'   => $trend['series'],
            // O dado mais antigo da tela manda no "Atualizado às".
            'generated_at' => $this->oldest($summary['generated_at'], $trend['generated_at']),
            // Prazo máximo que um número da tela pode ficar guardado: o resumo
            // (10 min) ou a tendência (30 min) — o tooltip do "Atualizar" usa este.
            'cache_minutes' => max(ClinicBiService::SUMMARY_TTL_MINUTES, ClinicBiService::TREND_TTL_MINUTES),
            // Atalhos abrem no MESMO período aplicado no BI (os números batem).
            'routes' => [
                'index'     => route('panel.financial.bi.index'),
                'billing'   => route('panel.financial.billing.index', ['from' => $from, 'to' => $to]),
                'glosas'    => route('panel.financial.tiss.glosas.index', ['from' => $from, 'to' => $to]),
                'cash_flow' => route('panel.financial.reports.cash-flow', ['from' => $from, 'to' => $to]),
                'covenants' => route('panel.financial.reports.covenants', ['from' => $from, 'to' => $to]),
            ],
            // `shared.period`: rótulos do PeriodFilter (componente compartilhado).
            't' => trans('financial_bi') + ['shared' => trans('financial_shared')],
        ]);
    }

    private function oldest(string $first, string $second): string
    {
        return Carbon::parse($first)->lessThanOrEqualTo(Carbon::parse($second)) ? $first : $second;
    }
}
