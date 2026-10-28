<?php

namespace App\Services;

use App\Models\Lense;
use Illuminate\Foundation\Http\FormRequest;

class LenseService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return Lense::class;
    }

    protected function getCreateData(FormRequest $request): array
    {
        return [
            'name' => $request->name,
            'away' => (bool) $request->away,
            'near' => (bool) $request->near,
        ];
    }

    protected function getUpdateData(FormRequest $request): array
    {
        $data = parent::getUpdateData($request);

        if ($request->has('away')) {
            $data['away'] = (bool) $request->away;
        }

        if ($request->has('near')) {
            $data['near'] = (bool) $request->near;
        }

        return $data;
    }
}
