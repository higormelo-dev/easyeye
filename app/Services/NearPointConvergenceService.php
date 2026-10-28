<?php

namespace App\Services;

use App\Models\NearPointConvergence;

class NearPointConvergenceService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return NearPointConvergence::class;
    }
}
