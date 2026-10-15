<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\ClinicalReview\Services\ReviewInvitationService;
use Illuminate\Console\Command;

/**
 * Convites de revisão clínica pendentes cujo prazo passou: viram `expired` e perdem o
 * rascunho cifrado do parecer (minimização). Idempotente. Agendado diariamente (ADR 0002).
 */
class ExpireClinicalReviewInvitationsCommand extends Command
{
    protected $signature = 'clinical-review:expire';

    protected $description = 'Expira convites de revisão clínica vencidos e apaga os rascunhos';

    public function handle(ReviewInvitationService $invitations): int
    {
        $this->info('Convites de revisão expirados: ' . $invitations->expireStale());

        return self::SUCCESS;
    }
}
