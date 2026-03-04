<?php

namespace App\Services;

use App\Http\Requests\ColorVisionTypeRequest;
use App\Models\ColorVisionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ColorVisionTypeService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function create(ColorVisionTypeRequest $request): ColorVisionType
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
    public function update(ColorVisionType $record, ColorVisionTypeRequest $request): ColorVisionType
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
    public function findByIdOrCode(string $idOrCode): ?ColorVisionType
    {
        /** @var ColorVisionType $record */
        $record = ColorVisionType::query()
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
    private function findOrCreate(ColorVisionTypeRequest $request): ColorVisionType
    {
        $existingColorVisionType = ColorVisionType::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $requestData = [
            'name' => $request->name,
        ];

        if ($existingColorVisionType) {
            if ($existingColorVisionType->trashed()) {
                $existingColorVisionType->restore();
            }
            $existingColorVisionType->update($requestData);

            return $existingColorVisionType->fresh();
        }

        return ColorVisionType::create(array_merge($requestData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
