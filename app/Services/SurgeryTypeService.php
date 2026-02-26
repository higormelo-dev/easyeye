<?php

namespace App\Services;

use App\Http\Requests\{SurgeryTypeRequest};
use App\Models\{SurgeryType};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SurgeryTypeService
{
    /**
     * Create a new record with all related entities
     *
     * @throws \Throwable
     */
    public function create(SurgeryTypeRequest $request): SurgeryType
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
    public function update(SurgeryType $record, SurgeryTypeRequest $request): SurgeryType
    {
        return DB::transaction(static function () use ($record, $request) {
            $data = [];

            if ($request->has('category')) {
                $data['category'] = $request->category;
            }

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
    public function findByIdOrCode(string $idOrCode): SurgeryType
    {
        /** @var SurgeryType $record */
        $record = SurgeryType::query()
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
     * Find or create record
     */
    private function findOrCreate(SurgeryTypeRequest $request): SurgeryType
    {
        $existingRecord = SurgeryType::query()
            ->withTrashed()
            ->where(
                [
                    ['entity_id', '=', session()->get('selected_entity_id')],
                    ['category', '=', $request->category],
                    ['name', '=', $request->name],
                ]
            )
            ->first();

        $recordData = [
            'category' => $request->category,
            'name'     => $request->name,
        ];

        if ($existingRecord) {
            if ($existingRecord->trashed()) {
                $existingRecord->restore();
            }
            $existingRecord->update($recordData);

            return $existingRecord->fresh();
        }

        return SurgeryType::create(array_merge($recordData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
