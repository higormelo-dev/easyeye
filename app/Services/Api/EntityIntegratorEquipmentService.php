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
        $integrator = request()->attributes->get('integrator');

        return EntityIntegratorEquipment::withTrashed()
            ->where('integrator_id', $integrator->id)
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

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('EIQ-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->firstOrFail();
    }

    /**
     * Find or create record.
     *
     * Deduplication is based on hardware identity (ip, mac, serial_number).
     * If any of these match a soft-deleted record for the same integrator,
     * it is restored and updated instead of creating a duplicate.
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
            ->where(function ($query) use ($request) {
                $query->where('ip', $request->input('ip'))
                    ->orWhere('mac', mb_strtoupper($request->mac, 'UTF-8'))
                    ->orWhere('serial_number', mb_strtoupper($request->serial_number, 'UTF-8'));
            })
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
