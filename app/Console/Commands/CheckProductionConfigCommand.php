<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\ProductionConfigAlertNotification;
use App\Services\Ops\ProductionConfigAudit;
use App\Support\Billing\NoticeLocale;
use App\Support\SaasAlertRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, Log};
use Throwable;

/**
 * Lista o que está simulado ou desligado na configuração (captcha, TISS, WhatsApp, e-mail, IA) e, em
 * PRODUÇÃO, avisa o time: Log::critical (Sentry) + e-mail enviado na hora. Cada item avisa no máximo
 * uma vez por semana (o comando roda todo dia). Fora de produção só lista: simulação é o normal.
 *
 * Nasceu em 08/11/2026: captcha, TISS e WhatsApp estavam assim em produção e ninguém era avisado.
 */
class CheckProductionConfigCommand extends Command
{
    protected $signature = 'ops:check-production-config';

    protected $description = 'Avisa o time quando algo está simulado ou desligado na configuração de produção';

    public function handle(): int
    {
        $findings = ProductionConfigAudit::findings();

        if ($findings === []) {
            $this->info('Nada simulado ou desligado.');

            return self::SUCCESS;
        }

        foreach ($findings as $code) {
            $this->warn(__("ops_alerts.findings.{$code}", [], 'pt_BR'));
        }

        if (! app()->isProduction()) {
            $this->line('Fora de produção: só listei, sem alerta.');

            return self::SUCCESS;
        }

        $new = array_values(array_filter($findings, fn (string $code): bool => Cache::add("ops:config-alert:{$code}", 1, now()->addDays(7))));

        if ($new === []) {
            return self::SUCCESS;
        }

        foreach ($new as $code) {
            Log::critical('[ops:alert] configuração de produção', ['finding' => $code]);
        }

        foreach (SaasAlertRecipients::get() as $user) {
            try {
                $user->notifyNow((new ProductionConfigAlertNotification($new))->locale(NoticeLocale::for($user)));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
