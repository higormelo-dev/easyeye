<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\DunningService;
use Illuminate\Console\Command;

/**
 * Régua de cobrança diária (lembrete D-5, pagamento não identificado,
 * acesso limitado e encerramento no D+7). Idempotente: cada etapa é
 * registrada uma vez por assinatura e vencimento — rodar de novo no mesmo
 * dia não repete aviso nem encerramento. Regra em DunningService.
 *
 * --dry-run: só mostra o que faria (não grava, não envia e não cancela no
 * gateway). BILLING_DUNNING_ENABLED=false desliga a régua (chave de
 * emergência); a simulação continua disponível.
 */
class RunBillingDunningCommand extends Command
{
    protected $signature = 'billing:dunning {--dry-run : Mostra o que a régua faria, sem gravar, enviar e-mail nem cancelar no gateway}';

    protected $description = 'Régua de cobrança: lembretes, avisos de atraso, acesso limitado e encerramento por inadimplência';

    public function handle(DunningService $dunning): int
    {
        if ($this->option('dry-run')) {
            return $this->simulate($dunning);
        }

        if (! $dunning->isEnabled()) {
            $this->warn(__('billing_console.dunning.disabled'));

            return self::SUCCESS;
        }

        $stats = $dunning->run();

        foreach ($stats as $step => $count) {
            $this->line(sprintf('%-24s %d', $step, $count));
        }

        $this->info(__('billing_console.dunning.done', ['count' => array_sum($stats)]));

        return self::SUCCESS;
    }

    private function simulate(DunningService $dunning): int
    {
        $rows = $dunning->preview();

        $this->table(
            [
                __('billing_console.dunning.col_subscription'),
                __('billing_console.dunning.col_entity'),
                __('billing_console.dunning.col_step'),
                __('billing_console.dunning.col_due_on'),
                __('billing_console.dunning.col_days_overdue'),
                __('billing_console.dunning.col_action'),
            ],
            $rows->map(fn (array $row) => [
                $row['subscription_id'],
                $row['entity'],
                $row['step']->label(),
                $row['due_on'],
                $row['days_overdue'] ?? '-',
                match (true) {
                    $row['stops_recurrence']   => __('billing_console.dunning.action_terminate_and_cancel', ['gateway' => (string) $row['gateway']]),
                    $row['step']->terminates() => __('billing_console.dunning.action_terminate'),
                    default                    => __('billing_console.dunning.action_notify', ['count' => $row['recipients']]),
                },
            ])->all(),
        );

        if (! $dunning->isEnabled()) {
            $this->warn(__('billing_console.dunning.disabled'));
        }

        $this->info(__('billing_console.dunning.dry_run_done', ['count' => $rows->count()]));

        return self::SUCCESS;
    }
}
