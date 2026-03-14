<?php

namespace App\Services;

use App\Models\IrisType;

class IrisTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return IrisType::class;
    }
}
