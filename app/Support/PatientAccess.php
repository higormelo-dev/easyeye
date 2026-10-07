<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\{ClientRule, Permission};
use App\Models\{Entity, User};

/**
 * "Tem acesso a Pacientes" — mesma regra do grupo de rotas de Pacientes
 * (permission:patients.manage,admin,financial,doctor,secretary) e do
 * Dashboard. Decide o que sai com dado de contato do paciente fora da tela de
 * Pacientes (telefone na impressão da agenda, conversa no WhatsApp pela linha
 * da agenda, telefones da recepção no Dashboard).
 *
 * Resolvida pelo container (testes trocam a regra sem montar perfis).
 */
class PatientAccess
{
    public function allows(?User $user, ?Entity $entity): bool
    {
        if ($user === null || $entity === null) {
            return false;
        }

        return $user->hasPermissionInEntity($entity, Permission::PatientsManage)
            || $user->hasAnyRoleInEntity($entity, [ClientRule::Admin, ClientRule::Financial, ClientRule::Doctor, ClientRule::Secretary]);
    }

    /** Usuário logado na clínica da sessão. */
    public function current(): bool
    {
        $user = auth()->user();

        return $this->allows(
            $user instanceof User ? $user : null,
            Entity::query()->find((string) session('selected_entity_id')),
        );
    }
}
