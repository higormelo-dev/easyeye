<?php

namespace App\Services;

use App\Models\CoverTestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

class CoverTestTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return CoverTestType::class;
    }

    protected function getCreateData(FormRequest $request): array
    {
        return ['name' => $request->name, 'abbreviation' => $request->abbreviation];
    }

    protected function getUpdateData(FormRequest $request): array
    {
        $data = parent::getUpdateData($request);

        if ($request->has('abbreviation')) {
            $data['abbreviation'] = $request->abbreviation;
        }

        return $data;
    }

    protected function findOrCreate(FormRequest $request): Model
    {
        $existing = CoverTestType::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('abbreviation', $request->abbreviation)
            ->where('name', $request->name)
            ->first();

        $data = $this->getCreateData($request);

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($data);

            return $existing->fresh();
        }

        return CoverTestType::create(array_merge($data, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
