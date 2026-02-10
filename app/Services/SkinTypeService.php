<?php

namespace App\Services;

use App\Http\Requests\SkinTypeRequest;
use App\Models\SkinType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SkinTypeService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function create(SkinTypeRequest $request): SkinType
    {
        return DB::transaction(function () use ($request) {
            return $this->findOrCreate($request);
        });
    }

    /**
     * Update existing covenant and related entities
     *
     * @throws \Throwable
     */
    public function update(SkinType $record, SkinTypeRequest $request): SkinType
    {
        return DB::transaction(function () use ($record, $request) {
            $data = [];

            if ($request->has('name')) {
                $data['name'] = $request->name;
            }

            if ($request->has('active')) {
                $data['active'] = $request->boolean('active');
            }

            $record->update($data);

            return $record;
        });
    }

    /**
     * Find by ID or Code including soft-deleted records
     */
    public function findByIdOrCode(string $idOrCode): ?SkinType
    {
        $query = SkinType::query()
            ->where('entity_id', session()->get('selected_entity_id'));

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->firstOrFail();
    }

    /**
     * Find or create skin type
     */
    private function findOrCreate(SkinTypeRequest $request): SkinType
    {
        $existingSkinType = SkinType::query()->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $skinTypeData = [
            'name' => $request->name,
        ];

        if ($existingSkinType) {
            if ($existingSkinType->trashed()) {
                $existingSkinType->restore();
            }
            $existingSkinType->update($skinTypeData);

            return $existingSkinType;
        }

        return SkinType::create(array_merge($skinTypeData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
