<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiCreditWallet;
use App\Enums\{ClientRule, FeatureKey, SubscriptionAccessLevel, SubscriptionStatus};
use App\Models\{Subscription, User};
use App\Services\Billing\SubscriptionNoticeService;
use App\Services\FeatureGateService;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * Paywall da IA: o que dizer (e oferecer) a quem ficou sem créditos.
 *
 * Mesmo payload nas telas de IA (props) e na resposta de saldo insuficiente
 * (422, `code` = ai_insufficient_credits), para o front mostrar a mensagem
 * certa — cortesia/trial sem franquia, franquia do mês esgotada, contratação
 * aguardando o 1º pagamento (a franquia só começa com ele) ou plano sem
 * franquia — e o próximo passo: "Comprar créditos" para quem pode comprar
 * (admin da clínica) ou "peça ao administrador" para os demais. Com o acesso
 * limitado pela régua de cobrança, a orientação é regularizar o pagamento.
 *
 * Cota que sobrou de quando a empresa tinha franquia (ex.: assinatura
 * convertida em cortesia) e que acabou: mensagem `residual_exhausted` com
 * `expires_on` — a mesma história do medidor (AiQuotaService), que mostra
 * "vale até" em vez de "renova em". Datas vão cruas (Y-m-d) para o front
 * formatar no idioma.
 *
 * Na resposta 422, ainda com saldo mas menos do que a execução pede: motivo
 * `insufficient_for_request` ("precisa de X, há Y, falta Z") — nada acabou.
 * Cliente em atraso cuja renovação cai no acesso limitado: a renovação é
 * condicionada ao pagamento (`renews_if_paid_on`), como no medidor.
 */
class AiPaywallService
{
    public const CODE = 'ai_insufficient_credits';

    /** Situações sem franquia em que uma cota que sobrou não volta. */
    private const RESIDUAL_REASONS = ['complimentary', 'trial', 'no_quota'];

    /** Recursos de IA que consomem créditos (mesmo critério do AiRunsController). */
    private const AI_FEATURES = [
        FeatureKey::HasAiExamAssistant,
        FeatureKey::HasAiReportDrafting,
        FeatureKey::HasAiEyeImageAnalysis,
        FeatureKey::HasAiChatAssistant,
    ];

    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {
    }

    /**
     * @param int|null $requested créditos que a execução recusada pedia (resposta 422)
     * @param int|null $available créditos que a carteira ainda liberava nessa hora
     *
     * @return array{
     *   code: string,
     *   reason: string,
     *   can_purchase: bool,
     *   purchase_url: string|null,
     *   renews_on: string|null,
     *   renews_if_paid_on: string|null,
     *   expires_on: string|null,
     *   requested: int|null,
     *   available: int|null,
     *   texts: array<string, string>
     * }
     */
    public function describe(string $entityId, ?string $userRule = null, ?int $requested = null, ?int $available = null): array
    {
        $userRule ??= session('selected_entity_user_rule');

        $subscription = Subscription::bestAccessibleFor($entityId, ['plan.features']);
        $reason       = $this->reason($subscription);
        $canPurchase  = $reason !== 'limited' && $subscription !== null && $this->canPurchase($entityId, $userRule);
        $wallet       = AiCreditWallet::query()->where('entity_id', $entityId)->first();
        $renewal      = $reason === 'quota_exhausted' ? $this->renewsOn($wallet) : null;
        // Em atraso, a renovação que cai no acesso limitado depende do pagamento.
        $ifPaid     = $renewal !== null && AiQuotaService::renewalNeedsPayment($subscription, $renewal);
        $renewsOn   = $renewal !== null && ! $ifPaid ? $renewal->toDateString() : null;
        $expiresOn  = in_array($reason, self::RESIDUAL_REASONS, true) ? $wallet?->quotaLastDay() : null;
        $messageKey = match (true) {
            $ifPaid                                             => 'quota_exhausted_if_paid',
            $reason === 'quota_exhausted' && $renewsOn === null => 'quota_exhausted_undated',
            $expiresOn !== null                                 => 'residual_exhausted',
            default                                             => $reason,
        };

        // Ainda há saldo (franquia, cota que sobrou ou avulso), só que menos
        // do que a execução pede: nada "acabou" — diz quanto falta.
        $short = $reason !== 'limited' && $requested !== null && $available !== null
            && $available > 0 && $available < $requested;

        if ($short) {
            $reason = 'insufficient_for_request';
        }

        return [
            'code'              => self::CODE,
            'reason'            => $reason,
            'can_purchase'      => $canPurchase,
            'purchase_url'      => $canPurchase ? $this->purchaseUrl($userRule) : null,
            'renews_on'         => $short ? null : $renewsOn,
            'renews_if_paid_on' => ! $short && $ifPaid ? $renewal->toDateString() : null,
            'expires_on'        => $short ? null : $expiresOn,
            'requested'         => $short ? $requested : null,
            'available'         => $short ? $available : null,
            'texts'             => [
                'title'     => __($short ? 'ai.paywall.title_insufficient' : 'ai.paywall.title'),
                'message'   => $short ? $this->shortMessage($requested, $available) : __("ai.paywall.reasons.{$messageKey}"),
                'buy'       => __('ai.paywall.buy'),
                'ask_admin' => __('ai.paywall.ask_admin'),
                'limited'   => __('ai.paywall.reasons.limited'),
            ],
        ];
    }

    /**
     * Pode comprar pacotes de créditos (pelo checkout — AiCreditPackCheckoutService):
     * contato de cobrança da clínica (admin, financeiro ou dono — mesma
     * regra do billing.contact), com algum recurso de IA no plano. Sem
     * usuário autenticado (ex.: chamada interna), só o papel admin.
     */
    public function canPurchase(string $entityId, ?string $userRule): bool
    {
        $user    = auth()->user();
        $contact = $user instanceof User
            ? app(SubscriptionNoticeService::class)->canSeeBilling($user, $entityId)
            : $userRule === ClientRule::Admin->value;

        return $contact && $this->hasAnyAiFeature($entityId);
    }

    /**
     * Onde comprar: a tela de IA (pacotes + checkout) para quem a acessa;
     * o financeiro compra em Minha assinatura.
     */
    private function purchaseUrl(?string $userRule): string
    {
        return in_array($userRule, [ClientRule::Admin->value, ClientRule::Doctor->value, ClientRule::Secretary->value], true)
            ? route('panel.ai-runs.index') . '#ai-credit-packages'
            : route('panel.my-subscription.index') . '#ai-credits';
    }

    public function hasAnyAiFeature(string $entityId): bool
    {
        foreach (self::AI_FEATURES as $feature) {
            if ($this->featureGate->can($entityId, $feature)) {
                return true;
            }
        }

        return false;
    }

    private function reason(?Subscription $subscription): string
    {
        if ($subscription === null) {
            return 'no_quota';
        }

        if ($subscription->accessLevel() === SubscriptionAccessLevel::Limited) {
            return 'limited';
        }

        if ($subscription->status === SubscriptionStatus::Trial) {
            return 'trial';
        }

        if ($subscription->isComplimentary()) {
            return 'complimentary';
        }

        $planQuota = (int) ($subscription->plan?->featureValue(FeatureKey::AiMonthlyCredits) ?? 0);

        if (! $subscription->isBillable() || $planQuota <= 0) {
            return 'no_quota';
        }

        // Contratação pela cobrança automática ainda sem o 1º pagamento (D5):
        // a franquia só começa quando ele for confirmado.
        return $subscription->isAwaitingFirstPayment() ? 'awaiting_payment' : 'quota_exhausted';
    }

    private function renewsOn(?AiCreditWallet $wallet): ?CarbonInterface
    {
        if (! $wallet || $wallet->quotaExpired() || (int) $wallet->monthly_quota <= 0) {
            return null;
        }

        return $wallet->quota_period_ends_at;
    }

    /** "Precisa de X, há Y, falta Z" — números no idioma do usuário. */
    private function shortMessage(int $requested, int $available): string
    {
        $locale  = app()->getLocale();
        $missing = $requested - $available;

        return trans_choice('ai.paywall.reasons.insufficient_for_request', $missing, [
            'requested' => Number::format($requested, locale: $locale),
            'available' => Number::format($available, locale: $locale),
            'missing'   => Number::format($missing, locale: $locale),
        ]);
    }
}
