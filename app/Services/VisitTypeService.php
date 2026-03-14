<?php

namespace App\Services;

use App\Models\VisitType;

class VisitTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return VisitType::class;
    }
}
