<?php

namespace App\Services;

use App\Http\Requests\VisualAcuityTypeRequest;
use App\Models\VisualAcuityType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VisualAcuityTypeService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function create(VisualAcuityTypeRequest $request): VisualAcuityType
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
    public function update(VisualAcuityType $record, VisualAcuityTypeRequest $request): VisualAcuityType
    {
        return DB::transaction(function () use ($record, $request) {
            $data = [];

            if ($request->has('name')) {
                $data['name'] = $request->name;
            }

            if ($request->has('scale')) {
                $data['scale'] = $request->scale;
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
    public function findByIdOrCode(string $idOrCode): ?VisualAcuityType
    {
        /** @var VisualAcuityType $record */
        $record = VisualAcuityType::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->when(
                Str::isUuid($idOrCode),
                static fn ($q) => $q->where('id', $idOrCode),
                static fn ($q) => $q->where('code', $idOrCode)
            )
            ->firstOrFail();

        return $record;
    }

    /**
     * Find or create skin type
     */
    private function findOrCreate(VisualAcuityTypeRequest $request): VisualAcuityType
    {
        $existingVisualAcuityType = VisualAcuityType::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $requestData = [
            'scale' => $request->scale,
            'name'  => $request->name,
        ];

        if ($existingVisualAcuityType) {
            if ($existingVisualAcuityType->trashed()) {
                $existingVisualAcuityType->restore();
            }
            $existingVisualAcuityType->update($requestData);

            return $existingVisualAcuityType->fresh();
        }

        return VisualAcuityType::create(array_merge($requestData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
