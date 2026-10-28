<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Setting\BaseSettingController;
use App\Http\Requests\ProductCategoryRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Services\ProductCategoryService;

/**
 * Catálogo de categorias de produto/material de estoque (ex.: "Colírios",
 * "Materiais cirúrgicos", "OPM") — mesmo padrão simples name/active de
 * AdditionTypesController/VisitTypesController.
 *
 * MOVIDO pro namespace/menu/rotas de Estoque (era App\Http\Controllers\
 * Setting\ProductCategoriesController, `panel.setting.product-categories.*`,
 * fora do gate `feature:has_inventory_module`) — mesma decisão aplicada às
 * lentes IOL: categoria de produto não tem consumidor fora do módulo de
 * Estoque (confirmado — só EntityProduct/ProductsController/
 * StockCountsController/ProductImportService/IolLensStockBridgeService a
 * usam), então cadastro agora É parte do módulo pago, atrás do mesmo gate
 * duplo do resto do grupo (`permission:stock.manage` +
 * `feature:has_inventory_module`, ver routes/web.php). BaseSettingController
 * continua em App\Http\Controllers\Setting — genérico o bastante pra servir
 * catálogos de qualquer área, não só configuração.
 */
class ProductCategoriesController extends BaseSettingController
{
    public function __construct(ProductCategoryService $service)
    {
        $this->titleController = __('actions.sidemenu.product_categories');
        $this->service         = $service;
        $this->resourceClass   = ProductCategoryResource::class;
        $this->routePrefix     = 'panel.stock.product-categories';
        $this->viewSlot        = 'productcategories';
    }

    public function store(ProductCategoryRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(ProductCategoryRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
