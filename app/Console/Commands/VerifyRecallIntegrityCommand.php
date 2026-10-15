<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Recall\Services\ProtocolGovernanceService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Verificação noturna de integridade dos protocolos de retorno (ADR 0002, item 5): refaz o
 * hash do TEXTO gravado de cada versão em uso ou em revisão. Divergência tira a versão de uso
 * (liberada/superada → suspensa; em revisão/validada → retirada), grava o evento de governança
 * e faz o comando FALHAR — o agendador/monitoramento enxerga. Idempotente.
 */
class VerifyRecallIntegrityCommand extends Command
{
    protected $signature = 'recall:verify-integrity';

    protected $description = 'Confere o hash das versões de protocolo de retorno e suspende as que divergirem';

    public function handle(ProtocolGovernanceService $governance): int
    {
        $report = $governance->verifyIntegrity();

        $this->info("Versões conferidas: {$report['checked']}");

        if ($report['failed'] === []) {
            return self::SUCCESS;
        }

        foreach ($report['failed'] as $failure) {
            $message = "INTEGRIDADE: {$failure['protocol']} v{$failure['version']} ({$failure['version_id']}) — {$failure['action']}";

            $this->error($message);
            report(new RuntimeException($message));
        }

        return self::FAILURE;
    }
}
