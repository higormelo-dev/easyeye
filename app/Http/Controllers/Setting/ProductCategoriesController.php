<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\ProductCategoryRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Services\ProductCategoryService;

/**
 * Catálogo de categorias de produto/material de estoque (ex.: "Colírios",
 * "Materiais cirúrgicos", "OPM") — mesmo padrão simples name/active de
 * AdditionTypesController/VisitTypesController.
 */
class ProductCategoriesController extends BaseSettingController
{
    public function __construct(ProductCategoryService $service)
    {
        $this->titleController = __('actions.sidemenu.product_categories');
        $this->service         = $service;
        $this->resourceClass   = ProductCategoryResource::class;
        $this->routePrefix     = 'panel.setting.product-categories';
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
