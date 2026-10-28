<?php

namespace App\Services;

use App\Models\VisualAcuityType;
use Illuminate\Foundation\Http\FormRequest;

class VisualAcuityTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return VisualAcuityType::class;
    }

    protected function getCreateData(FormRequest $request): array
    {
        return ['scale' => $request->scale, 'name' => $request->name];
    }

    protected function getUpdateData(FormRequest $request): array
    {
        $data = parent::getUpdateData($request);

        if ($request->has('scale')) {
            $data['scale'] = $request->scale;
        }

        return $data;
    }
}
