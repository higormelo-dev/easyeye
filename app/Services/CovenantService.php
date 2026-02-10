<?php

namespace App\Services;

use App\Http\Requests\CovenantRequest;
use App\Models\{Covenant};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Container\{ContainerExceptionInterface, NotFoundExceptionInterface};

class CovenantService
{
    /**
     * Create a new record with all related entities
     *
     * @throws \Throwable
     */
    public function create(CovenantRequest $request): Covenant
    {
        return DB::transaction(function () use ($request) {
            return $this->findOrCreate($request);
        });
    }

    /**
     * Update existing record and related entities
     */
    public function update(Covenant $covenant, CovenantRequest $request): Covenant
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
     * Find or create record
     */
    private function findOrCreate(CovenantRequest $request): Covenant
    {
        $existingRecord = Covenant::query()->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $recordData = [
            'name'  => $request->name,
            'color' => $request->color,
            'table' => (bool) $request->table,
        ];

        if ($existingRecord) {
            if ($existingRecord->trashed()) {
                $existingRecord->restore();
            }
            $existingRecord->update($recordData);

            return $existingRecord;
        }

        return Covenant::create(array_merge($recordData, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }

    /**
     * Find by ID or Code including soft-deleted records
     *
     * @return Covenant|null
     */
    public function findByIdOrCode(string $idOrCode): ?Covenant
    {
        $query = Covenant::query()
            ->where('entity_id', session()->get('selected_entity_id'));

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->firstOrFail();
    }
}
