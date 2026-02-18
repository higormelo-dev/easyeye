<?php

namespace App\Services;

use App\Http\Requests\AdditionTypeRequest;
use App\Models\AdditionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdditionTypeService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function create(AdditionTypeRequest $request): AdditionType
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
    public function update(AdditionType $record, AdditionTypeRequest $request): AdditionType
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
    public function findByIdOrCode(string $idOrCode): ?AdditionType
    {
        $query = AdditionType::query()
            ->withTrashed()
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
    private function findOrCreate(AdditionTypeRequest $request): AdditionType
    {
        $existingAdditionType = AdditionType::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $skinTypeData = [
            'name' => $request->name,
        ];

        if ($existingAdditionType) {
            if ($existingAdditionType->trashed()) {
                $existingAdditionType->restore();
            }
            $existingAdditionType->update($skinTypeData);

            return $existingAdditionType;
        }

        return AdditionType::create(array_merge($skinTypeData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
