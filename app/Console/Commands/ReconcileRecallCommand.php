<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Recall\Services\RecallReconciler;
use Illuminate\Console\Command;

/**
 * Conciliação noturna do recall: reavalia todo paciente que pode ter retorno, clínica por clínica. É a rede de
 * segurança da outbox (gravação em massa, importação, `saveQuietly`, prazos que passaram com o relógio).
 * Falha de um paciente é relatada e NÃO interrompe os demais; o comando só falha se algum paciente falhou.
 */
class ReconcileRecallCommand extends Command
{
    protected $signature = 'recall:reconcile {--entity= : só esta clínica (id)}';

    protected $description = 'Reavalia os retornos de todos os pacientes que podem ter retorno (conciliação)';

    public function handle(RecallReconciler $reconciler): int
    {
        $entityId = $this->option('entity');

        $stats = $entityId !== null && $entityId !== ''
            ? $reconciler->reconcileEntity((string) $entityId) + ['entities' => 1]
            : $reconciler->reconcileAll();

        $this->info(sprintf(
            'Clínicas: %d · pacientes: %d · criados: %d · atualizados: %d · encerrados: %d · sem mudança: %d · falhas: %d',
            $stats['entities'],
            $stats['patients'],
            $stats['created'],
            $stats['updated'],
            $stats['closed'],
            $stats['unchanged'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
