<?php

namespace App\Services;

use App\Http\Requests\NearPointConvergenceRequest;
use App\Models\NearPointConvergence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NearPointConvergenceService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function create(NearPointConvergenceRequest $request): NearPointConvergence
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
    public function update(NearPointConvergence $record, NearPointConvergenceRequest $request): NearPointConvergence
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
    public function findByIdOrCode(string $idOrCode): ?NearPointConvergence
    {
        /** @var NearPointConvergence $record */
        $record = NearPointConvergence::query()
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
    private function findOrCreate(NearPointConvergenceRequest $request): NearPointConvergence
    {
        $existingNearPointConvergence = NearPointConvergence::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $requestData = [
            'name' => $request->name,
        ];

        if ($existingNearPointConvergence) {
            if ($existingNearPointConvergence->trashed()) {
                $existingNearPointConvergence->restore();
            }
            $existingNearPointConvergence->update($requestData);

            return $existingNearPointConvergence->fresh();
        }

        return NearPointConvergence::create(array_merge($requestData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
