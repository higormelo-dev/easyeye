<?php

namespace App\Services;

use App\Http\Requests\{IrisTypeRequest};
use App\Models\{IrisType};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IrisTypeService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function create(IrisTypeRequest $request): IrisType
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
    public function update(IrisType $record, IrisTypeRequest $request): IrisType
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
    public function findByIdOrCode(string $idOrCode): ?IrisType
    {
        $query = IrisType::query()
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
    private function findOrCreate(IrisTypeRequest $request): IrisType
    {
        $existingRecord = IrisType::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $recordData = [
            'name' => $request->name,
        ];

        if ($existingRecord) {
            if ($existingRecord->trashed()) {
                $existingRecord->restore();
            }
            $existingRecord->update($recordData);

            return $existingRecord;
        }

        return IrisType::create(array_merge($recordData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
