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
                'index'  => route('panel.stock.reports.index'),
                'export' => route('panel.stock.reports.export'),
            ],
        ]);
    }

    /**
     * GAP fechado (revisão pós-Fase 4): Financeiro já tinha exportação de
     * relatório (FinancialReportsController::exportCashFlowCsv()), Estoque
     * não tinha nenhuma — cada relatório era só leitura na tela, sem forma
     * de levar pra planilha. CSV puro (não o CSV/XLS/XLSX/PDF completo do
     * Financeiro — volume de dado de estoque é bem menor, não justifica a
     * mesma máquina); mesmo padrão de streaming de
     * ComplianceController::exportAuditLogs().
     */
    public function exportCsv(Request $request, StockReportService $reports)
    {
        $entityId = (string) session('selected_entity_id');
        $from     = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to       = (string) $request->input('to', now()->toDateString());
        $report   = $request->string('report', 'inventory')->value();

        [$header, $rows, $filename] = match ($report) {
            'turnover' => [
                ['Produto', 'Código', 'Saída no período', 'Saldo atual', 'Giro'],
                collect($reports->turnoverByProduct($entityId, $from, $to))->map(fn ($r) => [
                    $r['name'], $r['code'], $r['qty_out'], $r['qty_on_hand'], $r['turnover_ratio'] ?? '',
                ]),
                "estoque_giro_{$from}_{$to}.csv",
            ],
            'consumption' => [
                ['Procedimento', 'Médico', 'Executado em', 'Produto', 'Quantidade', 'Custo unitário', 'Custo total'],
                collect($reports->consumptionByProcedure($entityId, $from, $to))
                    ->flatMap(fn ($group) => collect($group['items'])->map(fn ($item) => [
                        $group['procedure_name'], $group['doctor_name'], $group['executed_at'],
                        $item['product_name'], $item['quantity'], $item['unit_cost'], $item['total_cost'],
                    ])),
                "estoque_consumo_{$from}_{$to}.csv",
            ],
            'purchases' => [
                ['Fornecedor', 'Pedidos com recebimento', 'Total gasto'],
                collect($reports->purchasesBySupplier($entityId, $from, $to))->map(fn ($r) => [
                    $r['supplier_name'], $r['orders_count'], $r['total_spent'],
                ]),
                "estoque_compras_{$from}_{$to}.csv",
            ],
            default => [
                ['Produto', 'Código', 'Categoria', 'Saldo', 'Custo médio', 'Valor total', '% acumulado', 'Classe ABC'],
                collect($reports->valuedInventory($entityId)['items'])->map(fn ($r) => [
                    $r['name'], $r['code'], $r['category_name'] ?? '', $r['qty_on_hand'],
                    $r['cost_avg'], $r['total_value'], $r['cumulative_pct'], $r['abc_class'],
                ]),
                'estoque_posicao_valorizada_' . now()->format('Y-m-d') . '.csv',
            ],
        };

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF"); // BOM UTF-8 — Excel BR abre acentuação certa sem isso
        fputcsv($stream, $header, ';');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ';');
        }
        rewind($stream);
        $content = stream_get_contents($stream) ?: '';
        fclose($stream);

        return response($content, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
