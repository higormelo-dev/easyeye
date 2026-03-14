<?php

namespace App\Services;

use App\Models\SkinType;

class SkinTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return SkinType::class;
    }
}
