<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Surgery\SurgeryAuthorizationService;
use Illuminate\Console\Command;

/**
 * Autorização cirúrgica vencida pela validade (ADR 0003, D9). Roda uma vez por
 * dia (ver routes/console.php): grava o evento `expired` no log imutável e
 * devolve o caso autorizado para "aguardando autorização" (renovação). Caso já
 * agendado continua agendado, com a autorização marcada como vencida. Idempotente.
 */
class ExpireSurgicalAuthorizationsCommand extends Command
{
    protected $signature = 'surgery:expire-authorizations';

    protected $description = 'Marca como vencidas as autorizações cirúrgicas com validade passada e registra o evento no log';

    public function handle(SurgeryAuthorizationService $authorizations): int
    {
        $expired = $authorizations->expireDue();

        $this->info("Autorizações cirúrgicas vencidas agora: {$expired}");

        return self::SUCCESS;
    }
}
