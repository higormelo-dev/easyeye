<?php

namespace App\Services\Api;

use App\Http\Requests\Api\EntityIntegratorEquipmentRequest;
use App\Models\EntityIntegratorEquipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EntityIntegratorEquipmentService
{
    private const FILLABLE_FIELDS = ['name', 'ip', 'mac', 'serial_number'];

    /**
     * Create a new record with all related entities.
     *
     * @throws Throwable
     */
    public function create(EntityIntegratorEquipmentRequest $request): EntityIntegratorEquipment
    {
        return DB::transaction(fn () => $this->findOrCreate($request));
    }

    /**
     * Update existing record and related entities.
     *
     * @throws Throwable
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
     * Find by ID or Code including soft-deleted records.
     */
    public function findByIdOrCode(string $idOrCode): ?EntityIntegratorEquipment
    {
        $integrator = request()->attributes->get('integrator');
        $query      = EntityIntegratorEquipment::withTrashed()
            ->where('integrator_id', $integrator->id);

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id', $idOrCode],
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

        // Identidade de hardware só existe nos campos INFORMADOS: ip/mac/
        // serial agora são opcionais (o integrador opera por pasta; muitos
        // aparelhos nem têm rede). Comparar um campo ausente seria ou um
        // erro de tipo (macaddr do Postgres rejeita '') ou um falso match
        // (ip IS NULL casaria com qualquer outro equipamento sem ip).
        $hardwareIdentity = array_filter([
            'ip'            => $request->input('ip'),
            'mac'           => $request->filled('mac')
                ? mb_strtoupper($request->input('mac'), 'UTF-8')
                : null,
            'serial_number' => $request->filled('serial_number')
                ? mb_strtoupper($request->input('serial_number'), 'UTF-8')
                : null,
        ]);

        $existingRecord = $hardwareIdentity === []
            ? null
            : EntityIntegratorEquipment::withTrashed()
                ->where('integrator_id', $integrator->id)
                ->where(function ($query) use ($hardwareIdentity) {
                    foreach ($hardwareIdentity as $column => $value) {
                        $query->orWhere($column, $value);
                    }
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
