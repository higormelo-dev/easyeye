<?php

namespace App\Services\Api;

use App\Http\Requests\Api\EntityIntegratorEquipmentRequest;
use App\Models\{EntityIntegratorEquipment};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EntityIntegratorEquipmentService
{
    private const FILLABLE_FIELDS = ['name', 'ip', 'mac', 'serial_number'];

    /**
     * Create a new record with all related entities
     *
     * @throws \Throwable
     */
    public function create(EntityIntegratorEquipmentRequest $request): EntityIntegratorEquipment
    {
        return DB::transaction(fn () => $this->findOrCreate($request));
    }

    /**
     * Update existing record and related entities
     *
     * @throws \Throwable
     */
    public function update(EntityIntegratorEquipment $equipment, EntityIntegratorEquipmentRequest $request): EntityIntegratorEquipment
    {
        return DB::transaction(static function () use ($equipment, $request) {
            $data = $request->only(self::FILLABLE_FIELDS);

            if ($request->has('active')) {
                $data['active'] = $request->boolean('active');
            }

            $equipment->update(array_filter($data, static fn ($value) => $value !== null));

            return $equipment->refresh();
        });
    }

    public function destroyById(string $id): bool
    {
        return EntityIntegratorEquipment::withTrashed()
            ->where('integrator_id', request()->user()->id)
            ->findOrFail($id)
            ->delete();
    }

    /**
     * Find by ID or Code including soft-deleted records
     */
    public function findByIdOrCode(string $idOrCode): ?EntityIntegratorEquipment
    {
        $integrator = request()->attributes->get('integrator');
        $query      = EntityIntegratorEquipment::withTrashed()
            ->where('integrator_id', $integrator->id);

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->firstOrFail();
    }

    /**
     * Find or create record
     */
    private function findOrCreate(EntityIntegratorEquipmentRequest $request): EntityIntegratorEquipment
    {
        $integrator = request()->attributes->get('integrator');
        $recordData = [
            ...$request->only(self::FILLABLE_FIELDS),
            'active' => $request->boolean('active'),
        ];

        $existingRecord = EntityIntegratorEquipment::withTrashed()
            ->where('integrator_id', $integrator->id)
            ->where('name', $request->name)
            ->first();

        if ($existingRecord) {
            $existingRecord->trashed() && $existingRecord->restore();
            $existingRecord->update($recordData);

            return $existingRecord->refresh();
        }

        return EntityIntegratorEquipment::create([
            ...$recordData,
            'integrator_id' => $integrator->id,
            'active'        => true,
        ]);
    }
}
