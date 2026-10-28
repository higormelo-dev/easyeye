<?php

namespace App\Services;

use App\Models\ColorVisionType;

class ColorVisionTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return ColorVisionType::class;
    }
}
