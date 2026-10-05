<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\TrialEndingNoticeService;
use Illuminate\Console\Command;

/**
 * Avisos de fim do teste grátis (3 dias antes, 1 dia antes e no dia), por
 * e-mail e WhatsApp, aos contatos de cobrança. Idempotente: cada aviso sai
 * uma vez por assinatura, passo e data de fim do trial. Regra em
 * TrialEndingNoticeService. BILLING_TRIAL_NOTICES_ENABLED=false desliga.
 */
class SendTrialEndingNoticesCommand extends Command
{
    protected $signature = 'billing:trial-notices {--dry-run : Mostra quantos avisos sairiam, sem gravar nem enviar}';

    protected $description = 'Avisos de fim do teste grátis (e-mail + WhatsApp) para os contatos de cobrança da clínica';

    public function handle(TrialEndingNoticeService $notices): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $notices->isEnabled()) {
            $this->warn(__('billing_console.trial_notices.disabled'));

            return self::SUCCESS;
        }

        $stats = $notices->run($dryRun);

        foreach ($stats as $step => $count) {
            $this->line(sprintf('%-12s %d', $step, $count));
        }

        $this->info(__($dryRun ? 'billing_console.trial_notices.dry_run_done' : 'billing_console.trial_notices.done', ['count' => array_sum($stats)]));

        return self::SUCCESS;
    }
}
