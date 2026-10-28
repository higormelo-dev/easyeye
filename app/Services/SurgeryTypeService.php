<?php

namespace App\Services;

use App\Models\SurgeryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

class SurgeryTypeService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return SurgeryType::class;
    }

    protected function getCreateData(FormRequest $request): array
    {
        return ['category' => $request->category, 'name' => $request->name];
    }

    protected function getUpdateData(FormRequest $request): array
    {
        $data = parent::getUpdateData($request);

        if ($request->has('category')) {
            $data['category'] = $request->category;
        }

        return $data;
    }

    protected function findOrCreate(FormRequest $request): Model
    {
        $existing = SurgeryType::query()
            ->withTrashed()
            ->where([
                ['entity_id', '=', session()->get('selected_entity_id')],
                ['category', '=', $request->category],
                ['name', '=', $request->name],
            ])
            ->first();

        $data = $this->getCreateData($request);

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($data);

            return $existing->fresh();
        }

        return SurgeryType::create(array_merge($data, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
