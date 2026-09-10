<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImportConfirmRequest;
use App\Services\Stock\ProductImportService;
use Illuminate\Http\{JsonResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importação em massa de produtos via CSV (GAP fechado — revisão pós-Fase
 * 4, "melhorar o módulo de estoque"). Mesmo VALOR do fluxo de
 * App\Http\Controllers\PatientImportsController (upload → preview com erro
 * por linha → confirmação → relatório), mas SÍNCRONO (sem Job/polling) —
 * ver docblock de App\Services\Stock\ProductImportService pro porquê.
 *
 * Mesmo grupo de rota `stock.` (permission:stock.manage +
 * feature:has_inventory_module) do resto do módulo.
 */
class ProductImportsController extends Controller
{
    public function __construct(
        private readonly ProductImportService $importService,
    ) {
    }

    public function index(): InertiaResponse
    {
        return Inertia::render('Panel/Stock/Products/Import', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.products'), 'url' => route('panel.stock.products.index'), 'active' => false],
                ['label' => 'Importar produtos', 'url' => '#', 'active' => true],
            ],
            'maxRows' => ProductImportService::MAX_ROWS,
            'routes'  => [
                'template' => route('panel.stock.products.import.template'),
                'preview'  => route('panel.stock.products.import.preview'),
                'confirm'  => route('panel.stock.products.import.confirm'),
                'products' => route('panel.stock.products.index'),
            ],
        ]);
    }

    public function template(): StreamedResponse
    {
        $headers = ['nome', 'unidade', 'sku', 'codigo_barras', 'categoria', 'preco_venda', 'estoque_minimo', 'estoque_maximo'];
        $example = ['Lente IOL Monofocal', 'un', 'LENTE-001', '7891234567890', 'Lentes', '1500.00', '5', '20'];

        return response()->streamDownload(function () use ($headers, $example) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, ';');
            fputcsv($handle, $example, ';');
            fclose($handle);
        }, 'modelo_importacao_produtos.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Lê e valida o CSV inteiro (sem gravar nada) — retorna preview pra
     * confirmação. `Accept: application/json` obrigatório: a tela consome
     * isso via fetch/axios, não navegação de página.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.required' => 'Selecione um arquivo CSV.',
            'file.mimes'    => 'O arquivo precisa ser um CSV.',
            'file.max'      => 'Arquivo muito grande (máx. 5MB).',
        ]);

        $entityId = (string) session('selected_entity_id');
        $result   = $this->importService->preview($request->file('file'), $entityId);

        return response()->json($result);
    }

    /**
     * Cria de fato os produtos — recebe as linhas válidas ECOADAS de volta
     * pela tela (ver preview() acima). Revalidação de negócio acontece
     * dentro do service, de propósito (ver docblock de
     * ProductImportService::import()).
     */
    public function confirm(ProductImportConfirmRequest $request): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        $result   = $this->importService->import($request->rows(), $entityId);

        return response()->json($result);
    }
}
