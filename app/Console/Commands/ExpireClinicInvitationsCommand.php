<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\{DoctorInvitationService, UserInvitationService};
use Illuminate\Console\Command;

/**
 * Convites de clínica (médico e usuário) vencidos sem resposta: marca como
 * expirados; nos de médico, descarta os dados que a clínica digitou
 * (minimização — LGPD); encerrados há mais de RETENTION_DAYS são apagados.
 * Agendado diariamente.
 */
class ExpireClinicInvitationsCommand extends Command
{
    protected $signature = 'clinic-invitations:expire';

    protected $description = 'Expira convites de clínica (médico e usuário) vencidos';

    public function handle(DoctorInvitationService $doctors, UserInvitationService $users): int
    {
        $count  = $doctors->expireOverdue() + $users->expireOverdue();
        $purged = $doctors->purgeClosed() + $users->purgeClosed();

        $this->info("Convites expirados: {$count}; encerrados apagados (retenção): {$purged}");

        return self::SUCCESS;
    }
}
