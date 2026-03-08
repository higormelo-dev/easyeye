<?php

namespace App\Services;

use App\Http\Requests\LenseRequest;
use App\Models\Lense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LenseService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function create(LenseRequest $request): Lense
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
    public function update(Lense $record, LenseRequest $request): Lense
    {
        return DB::transaction(function () use ($record, $request) {
            $data = [];

            if ($request->has('away')) {
                $data['away'] = (bool) $request->away;
            }

            if ($request->has('near')) {
                $data['near'] = (bool) $request->near;
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
    public function findByIdOrCode(string $idOrCode): ?Lense
    {
        /** @var Lense $record */
        $record = Lense::query()
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
    private function findOrCreate(LenseRequest $request): Lense
    {
        $existingLense = Lense::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $requestData = [
            'name' => $request->name,
            'away' => (bool) $request->away,
            'near' => (bool) $request->near,
        ];

        if ($existingLense) {
            if ($existingLense->trashed()) {
                $existingLense->restore();
            }
            $existingLense->update($requestData);

            return $existingLense->fresh();
        }

        return Lense::create(array_merge($requestData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
