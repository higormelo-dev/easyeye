<?php

namespace App\Services;

use App\Models\Covenant;
use Illuminate\Foundation\Http\FormRequest;

class CovenantService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return Covenant::class;
    }

    protected function getCreateData(FormRequest $request): array
    {
        return [
            'name'  => $request->name,
            'color' => $request->color,
            'table' => (bool) $request->table,
        ];
    }

    protected function getUpdateData(FormRequest $request): array
    {
        $data = parent::getUpdateData($request);

        if ($request->has('color')) {
            $data['color'] = $request->color;
        }

        if ($request->has('table')) {
            $data['table'] = $request->boolean('table');
        }

        return $data;
    }
}
