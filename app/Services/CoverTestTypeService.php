<?php

namespace App\Services;

use App\Http\Requests\CoverTestTypeRequest;
use App\Models\CoverTestType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoverTestTypeService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function create(CoverTestTypeRequest $request): CoverTestType
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
    public function update(CoverTestType $record, CoverTestTypeRequest $request): CoverTestType
    {
        return DB::transaction(static function () use ($record, $request) {
            $data = [];

            if ($request->has('name')) {
                $data['name'] = $request->name;
            }

            if ($request->has('abbreviation')) {
                $data['abbreviation'] = $request->abbreviation;
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
    public function findByIdOrCode(string $idOrCode): ?CoverTestType
    {
        /** @var CoverTestType $record */
        $record = CoverTestType::query()
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
    private function findOrCreate(CoverTestTypeRequest $request): CoverTestType
    {
        $existingCoverTestType = CoverTestType::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('abbreviation', $request->abbreviation)
            ->where('name', $request->name)
            ->first();

        $requestData = [
            'name'         => $request->name,
            'abbreviation' => $request->abbreviation,
        ];

        if ($existingCoverTestType) {
            if ($existingCoverTestType->trashed()) {
                $existingCoverTestType->restore();
            }
            $existingCoverTestType->update($requestData);

            return $existingCoverTestType->fresh();
        }

        return CoverTestType::create(array_merge($requestData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
