<?php

namespace App\Services;

use App\Models\VisitType;
use Illuminate\Foundation\Http\FormRequest;

class VisitTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return VisitType::class;
    }

    protected function getCreateData(FormRequest $request): array
    {
        return [
            'name'         => $request->name,
            'procedure_id' => $request->procedure_id ?: null,
        ];
    }

    protected function getUpdateData(FormRequest $request): array
    {
        $data = parent::getUpdateData($request);

        if ($request->has('procedure_id')) {
            $data['procedure_id'] = $request->procedure_id ?: null;
        }

        return $data;
    }
}
