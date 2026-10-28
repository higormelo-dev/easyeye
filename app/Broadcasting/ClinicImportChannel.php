<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\Permission;
use App\Models\{DoctorImport, Entity, PatientImport, ScheduleImport, User};
use Illuminate\Support\Str;

/**
 * Canal privado `imports.{type}.{importId}` — progresso de uma importação de
 * clínica (pacientes, médicos, agenda) via WebSocket.
 *
 * Autoriza com a MESMA regra das telas de importação (routes/web.php):
 * clínica da sessão = clínica dona da importação (isolamento entre clínicas)
 * e permissão/papel de quem pode abrir a tela. Mudou a regra da rota, mude
 * aqui também.
 */
class ClinicImportChannel
{
    /**
     * tipo → [model, permission (ou null), papéis aceitos].
     *
     * @var array<string, array{0: class-string, 1: ?Permission, 2: list<string>}>
     */
    private const TYPES = [
        // permission:patients.manage,admin,financial,doctor,secretary
        'patients' => [PatientImport::class, Permission::PatientsManage, ['admin', 'financial', 'doctor', 'secretary']],
        // permission:patients.manage,admin,financial,secretary
        'doctors' => [DoctorImport::class, Permission::PatientsManage, ['admin', 'financial', 'secretary']],
        // entity.role:admin,doctor,secretary
        'schedules' => [ScheduleImport::class, null, ['admin', 'doctor', 'secretary']],
    ];

    public function join(User $user, string $type, string $importId): bool
    {
        $rule = self::TYPES[$type] ?? null;

        if ($rule === null || ! Str::isUuid($importId)) {
            return false;
        }

        [$modelClass, $permission, $roles] = $rule;

        // Sem tenant.bind na rota de auth do broadcasting: o EntityScope está
        // inerte, então a posse é checada explicitamente abaixo.
        $entityId = $modelClass::withoutGlobalScopes()->whereKey($importId)->value('entity_id');

        if ($entityId === null || (string) $entityId !== (string) session('selected_entity_id')) {
            return false;
        }

        $entity = Entity::query()->find($entityId);

        if (! $entity) {
            return false;
        }

        return ($permission !== null && $user->hasPermissionInEntity($entity, $permission))
            || $user->hasAnyRoleInEntity($entity, $roles);
    }
}
