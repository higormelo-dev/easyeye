<?php

namespace App\Services;

use App\Http\Requests\VisitTypeRequest;
use App\Models\VisitType;
use Illuminate\Support\Facades\DB;

class VisitTypeService
{
    /**
     * Create a new record with all related entities
     *
     * @throws \Throwable
     */
    public function create(VisitTypeRequest $request): VisitType
    {
        return DB::transaction(function () use ($request) {
            return $this->findOrCreate($request);
        });
    }

    /**
     * Update existing record and related entities
     *
     * @throws \Throwable
     */
    public function update(VisitType $record, VisitTypeRequest $request): VisitType
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
     * Find or create record
     */
    private function findOrCreate(VisitTypeRequest $request): VisitType
    {
        $existingRecord = VisitType::query()->withTrashed()
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

        return VisitType::create(array_merge($recordData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
