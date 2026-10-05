<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiCreditWallet;
use App\Models\Subscription;
use App\Support\Billing\DunningSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Medidor da franquia de IA (X/Y) exibido no painel da IA, no assistente do
 * prontuário/Eye Image e no assistente flutuante. Lê a MESMA carteira que a
 * reserva usa para bloquear (AiCreditWallet): cota da janela mensal, quanto
 * já foi usado, fim da janela e saldo avulso — assim o medidor nunca mostra
 * folga quando a execução seria recusada (ou o contrário).
 *
 * Janela vencida de quem ganha franquia: a leitura concede a vigente
 * (AiCreditWalletService::grantDueQuotaWindow), sem esperar o agendador.
 *
 * Renovação só é prometida (`renews_on`) para a assinatura que ganha
 * franquia (Subscription::earnsMonthlyAiQuota). Cota que sobrou de quando a
 * empresa tinha franquia (ex.: assinatura convertida em cortesia) aparece
 * com `expires_on` — vale até esse dia e não renova —, a mesma história que
 * o paywall conta (AiPaywallService, reason residual_exhausted). Cliente em
 * atraso cuja renovação cai no acesso limitado da régua recebe
 * `renews_if_paid_on` em vez de `renews_on`: a renovação depende do
 * pagamento (renewalNeedsPayment).
 *
 * O consumo por mês civil (relatório) continua no AiRunsController::index.
 */
class AiQuotaService
{
    public function __construct(
        private readonly AiCreditWalletService $wallets,
    ) {
    }

    /**
     * @return array{
     *   monthly_quota: int,
     *   consumed_credits: int,
     *   remaining: int,
     *   usage_percent: float|null,
     *   renews_on: string|null,
     *   renews_if_paid_on: string|null,
     *   expires_on: string|null,
     *   purchased_balance: int,
     *   reserved: int,
     *   available: int
     * }
     */
    public function snapshot(string $entityId): array
    {
        $this->wallets->grantDueQuotaWindow($entityId);

        $wallet = AiCreditWallet::query()->where('entity_id', $entityId)->first();

        if (! $wallet) {
            return [
                'monthly_quota'     => 0,
                'consumed_credits'  => 0,
                'remaining'         => 0,
                'usage_percent'     => null,
                'renews_on'         => null,
                'renews_if_paid_on' => null,
                'expires_on'        => null,
                'purchased_balance' => 0,
                'reserved'          => 0,
                'available'         => 0,
            ];
        }

        // Janela vencida (ou cota encerrada): não há franquia em vigor.
        $expired      = $wallet->quotaExpired();
        $quota        = $expired ? 0 : max(0, (int) $wallet->monthly_quota);
        $used         = $expired ? 0 : min($quota, max(0, (int) $wallet->monthly_quota_used));
        $subscription = $quota > 0 ? Subscription::bestAccessibleFor($entityId) : null;
        $renews       = $subscription?->earnsMonthlyAiQuota() ?? false;
        $renewal      = $renews ? $wallet->quota_period_ends_at : null;
        // Em atraso, a renovação que cai no acesso limitado depende do pagamento.
        $ifPaid = $renewal !== null && self::renewalNeedsPayment($subscription, $renewal);

        return [
            'monthly_quota'     => $quota,
            'consumed_credits'  => $used,
            'remaining'         => $wallet->quotaRemaining(),
            'usage_percent'     => $quota > 0 ? round(($used / $quota) * 100, 1) : null,
            'renews_on'         => $renewal !== null && ! $ifPaid ? $renewal->toDateString() : null,
            'renews_if_paid_on' => $ifPaid ? $renewal->toDateString() : null,
            'expires_on'        => $quota > 0 && ! $renews ? $wallet->quotaLastDay() : null,
            'purchased_balance' => max(0, (int) $wallet->balance),
            'reserved'          => max(0, (int) $wallet->reserved_balance),
            'available'         => $wallet->totalAvailable(),
        ];
    }

    /**
     * Cliente pagante em atraso: a janela seguinte só é concedida com acesso
     * total. Se a renovação cai no acesso limitado da régua (ou depois), ela
     * depende de o pagamento em atraso estar confirmado — não dá para
     * prometer "renova em" sem essa condição. Mesma regra no paywall.
     */
    public static function renewalNeedsPayment(?Subscription $subscription, CarbonInterface $renewal): bool
    {
        $since = $subscription?->overdueSince();

        return $since !== null && ! Carbon::instance($renewal)->lessThan(DunningSchedule::limitedFrom($since));
    }
}
