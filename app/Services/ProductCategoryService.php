<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductCategory;

class ProductCategoryService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return ProductCategory::class;
    }
}
