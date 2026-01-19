<?php

namespace App\Services;

use App\Http\Requests\CovenantRequest;
use App\Models\Covenant;
use Illuminate\Support\Facades\DB;

class CovenantService
{
    /**
     * Create a new covenant with all related entities
     *
     * @throws \Throwable
     */
    public function createCovenant(CovenantRequest $request): Covenant
    {
        return DB::transaction(function () use ($request) {
            return $this->findOrCreateCovenant($request);
        });
    }

    /**
     * Update existing covenant and related entities
     */
    public function updateCovenant(Covenant $covenant, CovenantRequest $request): Covenant
    {
        return DB::transaction(function () use ($covenant, $request) {
            $data = [];

            if ($request->has('name')) {
                $data['name'] = $request->name;
            }

            if ($request->has('color')) {
                $data['color'] = $request->color;
            }

            if ($request->has('table')) {
                $data['table'] = $request->boolean('table');
            }

            if ($request->has('active')) {
                $data['active'] = $request->boolean('active');
            }

            $covenant->update($data);

            return $covenant;
        });
    }

    /**
     * Find or create covenant
     */
    private function findOrCreateCovenant(CovenantRequest $request): Covenant
    {
        $existingCovenant = Covenant::query()->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $covenantData = [
            'name'  => $request->name,
            'color' => $request->color,
            'table' => (bool) $request->table,
        ];

        if ($existingCovenant) {
            if ($existingCovenant->trashed()) {
                $existingCovenant->restore();
            }
            $existingCovenant->update($covenantData);

            return $existingCovenant;
        }

        return Covenant::create(array_merge($covenantData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
