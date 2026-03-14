<?php

namespace App\Services;

use App\Models\AdditionType;

class AdditionTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return AdditionType::class;
    }
}
