<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\ClinicResource;
use App\Models\Surgery\{SurgeryFacility, SurgeryRoom, SurgicalCase};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Locais (clínica própria, hospital, centro cirúrgico) e salas cirúrgicas.
 * Regra: local/sala em uso por um caso NÃO FINAL não é apagado nem desativado
 * (a agenda e a solicitação TISS ainda dependem dele).
 */
class SurgeryFacilityService
{
    /** @param array<string, mixed> $data */
    public function createFacility(string $entityId, array $data): SurgeryFacility
    {
        return SurgeryFacility::query()->create([...$data, 'entity_id' => $entityId]);
    }

    /** @param array<string, mixed> $data */
    public function updateFacility(SurgeryFacility $facility, array $data): SurgeryFacility
    {
        return DB::transaction(function () use ($facility, $data): SurgeryFacility {
            $locked = SurgeryFacility::query()->whereKey($facility->getKey())->lockForUpdate()->firstOrFail();

            if (array_key_exists('active', $data) && ! $data['active'] && $locked->active) {
                $this->assertFacilityFree($locked);
            }

            $locked->update($data);

            return $locked;
        });
    }

    public function deleteFacility(SurgeryFacility $facility): void
    {
        DB::transaction(function () use ($facility): void {
            $locked = SurgeryFacility::query()->whereKey($facility->getKey())->lockForUpdate()->firstOrFail();

            $this->assertFacilityFree($locked);

            $locked->rooms()->get()->each->delete();
            $locked->delete();
        });
    }

    /** @param array<string, mixed> $data */
    public function createRoom(SurgeryFacility $facility, array $data): SurgeryRoom
    {
        $this->assertResource((string) $facility->entity_id, $data['clinic_resource_id'] ?? null, null);

        try {
            return $facility->rooms()->create([...$data, 'entity_id' => $facility->entity_id]);
        } catch (UniqueConstraintViolationException) {
            throw SurgeryRuleException::invalid('surgery.validation.room_name_taken');
        }
    }

    /** @param array<string, mixed> $data */
    public function updateRoom(SurgeryRoom $room, array $data): SurgeryRoom
    {
        return DB::transaction(function () use ($room, $data): SurgeryRoom {
            $locked = SurgeryRoom::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            if (array_key_exists('clinic_resource_id', $data)) {
                $this->assertResource((string) $locked->entity_id, $data['clinic_resource_id'], $locked);
            }

            if (array_key_exists('active', $data) && ! $data['active'] && $locked->active) {
                $this->assertRoomFree($locked);
            }

            try {
                $locked->update($data);
            } catch (UniqueConstraintViolationException) {
                throw SurgeryRuleException::invalid('surgery.validation.room_name_taken');
            }

            return $locked;
        });
    }

    public function deleteRoom(SurgeryRoom $room): void
    {
        DB::transaction(function () use ($room): void {
            $locked = SurgeryRoom::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            $this->assertRoomFree($locked);
            $locked->delete();
        });
    }

    private function openCases(): Builder
    {
        return SurgicalCase::query()->whereNotIn('status', [
            SurgicalCaseStatus::Cancelled->value,
            SurgicalCaseStatus::Discharged->value,
            SurgicalCaseStatus::Performed->value,
        ]);
    }

    private function assertFacilityFree(SurgeryFacility $facility): void
    {
        if ($this->openCases()->where('surgery_facility_id', $facility->getKey())->exists()) {
            throw SurgeryRuleException::state('surgery.errors.facility_in_use');
        }
    }

    private function assertRoomFree(SurgeryRoom $room): void
    {
        if ($this->openCases()->where('surgery_room_id', $room->getKey())->exists()) {
            throw SurgeryRuleException::state('surgery.errors.room_in_use');
        }
    }

    /** O recurso da Agenda existe na clínica, é uma sala e não está ligado a outra sala cirúrgica. */
    private function assertResource(string $entityId, mixed $resourceId, ?SurgeryRoom $except): void
    {
        if (blank($resourceId)) {
            return;
        }

        $resource = ClinicResource::query()->where('entity_id', $entityId)->where('type', 'room')->whereKey($resourceId)->first();

        if ($resource === null) {
            throw SurgeryRuleException::invalid('surgery.errors.resource_not_found');
        }

        $taken = SurgeryRoom::query()
            ->where('clinic_resource_id', $resource->getKey())
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw SurgeryRuleException::state('surgery.errors.resource_already_linked');
        }
    }
}
