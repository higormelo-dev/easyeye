<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Throwable;

/**
 * Franquia mensal de IA: concede a janela que começou para cada assinatura
 * de cobrança automática paga com acesso total (trimestral, semestral e
 * anual também recebem todo mês, sem acumular). Acesso limitado ou
 * bloqueado não recebe — ao voltar a ficar em dia, o pagamento concede a
 * janela atual (SubscriptionObserver). A reserva e o medidor também concedem
 * a janela vencida na hora (AiCreditWalletService::grantDueQuotaWindow);
 * este comando é a garantia para quem não usa a IA. Idempotente por
 * assinatura + início da janela: rodar de novo não repete nem reinicia o
 * consumo.
 */
class GrantMonthlyAiQuotasCommand extends Command
{
    protected $signature = 'ai:grant-monthly-quotas';

    protected $description = 'Concede a franquia mensal de IA das assinaturas pagas cuja janela começou';

    public function handle(AiCreditWalletService $wallet): int
    {
        $granted = 0;
        $failed  = 0;

        Subscription::query()
            ->billable()
            ->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->accessible()
            ->with('plan.features')
            ->each(function (Subscription $subscription) use ($wallet, &$granted, &$failed): void {
                try {
                    // Só a assinatura que hoje vale para a empresa (a de melhor acesso).
                    if (! $subscription->earnsMonthlyAiQuota()
                        || Subscription::bestAccessibleFor((string) $subscription->entity_id)?->id !== $subscription->id) {
                        return;
                    }

                    if ($wallet->grantMonthlyCreditsForSubscription($subscription) !== null) {
                        $granted++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                }
            });

        $this->info("Franquias de IA concedidas: {$granted}");

        if ($failed > 0) {
            $this->warn("Falhas: {$failed}");
        }

        return self::SUCCESS;
    }
}
