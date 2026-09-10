<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Services\Stock\StockReportService;
use Illuminate\Http\Request;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Relatórios de estoque (GAP fechado nesta revisão — ver docblock de
 * App\Services\Stock\StockReportService). Leitura pura, mesmo grupo de
 * rota `stock.` (permission:stock.manage + feature:has_inventory_module).
 */
class StockReportsController extends Controller
{
    public function index(Request $request, StockReportService $reports): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');
        $from     = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to       = (string) $request->input('to', now()->toDateString());

        return Inertia::render('Panel/Stock/Reports/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.stock_reports'), 'url' => '#', 'active' => true],
            ],
            'filters'                => ['from' => $from, 'to' => $to],
            'valuedInventory'        => $reports->valuedInventory($entityId),
            'turnover'               => $reports->turnoverByProduct($entityId, $from, $to),
            'consumptionByProcedure' => $reports->consumptionByProcedure($entityId, $from, $to),
            'purchasesBySupplier'    => $reports->purchasesBySupplier($entityId, $from, $to),
            'routes'                 => [
                'index' => route('panel.stock.reports.index'),
            ],
        ]);
    }
}
