<?php

namespace App\Services;

use App\Models\ClinicResource;
use Illuminate\Foundation\Http\FormRequest;

class ClinicResourceService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return ClinicResource::class;
    }

    protected function getCreateData(FormRequest $request): array
    {
        return [
            'name'        => $request->name,
            'type'        => $request->input('type', 'room'),
            'description' => $request->input('description'),
        ];
    }

    protected function getUpdateData(FormRequest $request): array
    {
        $data = parent::getUpdateData($request);

        if ($request->has('type')) {
            $data['type'] = $request->input('type');
        }

        if ($request->has('description')) {
            $data['description'] = $request->input('description');
        }

        return $data;
    }
}
