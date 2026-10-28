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
            'name'               => $request->name,
            'color'              => $request->color,
            'table'              => (bool) $request->table,
            'contract_starts_at' => $request->input('contract_starts_at'),
            'contract_ends_at'   => $request->input('contract_ends_at'),
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

        // has() (não filled()): enviar vazio limpa a vigência.
        foreach (['contract_starts_at', 'contract_ends_at'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        return $data;
    }
}
