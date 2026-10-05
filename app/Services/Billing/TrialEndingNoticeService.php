<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Billing\SubscriptionTrialNotice;
use App\Models\{Entity, Subscription};
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Notifications\TrialEndingNotification;
use App\Support\Billing\{DunningSchedule, NoticeLocale};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Avisos de fim do teste grátis (comando diário `billing:trial-notices`):
 * e-mail + WhatsApp (instância global do SaaS) aos contatos de cobrança da
 * clínica — admin, financeiro e dono, os mesmos da régua — 3 dias antes, 1
 * dia antes e no dia do fim do trial, com o link para contratar DENTRO do
 * sistema (Minha assinatura).
 *
 * Só a assinatura vigente da empresa, ainda em trial: clínica que contratou
 * (a vigente passou a ser a cobrança automática), cortesia ou trial já
 * vencido não recebem. Trial estendido: os passos são recalculados pela data
 * de fim atual (outra data = avisos novos).
 *
 * Idempotente: cada passo é gravado uma vez por assinatura e data de fim
 * (subscription_trial_notices, índice único); a situação é reconferida na
 * hora do envio (shouldSend do aviso). Chave: billing.trial_notices.enabled
 * (BILLING_TRIAL_NOTICES_ENABLED).
 */
class TrialEndingNoticeService
{
    public const STEP_THREE_DAYS = 'three_days';

    public const STEP_ONE_DAY = 'one_day';

    public const STEP_TODAY = 'today';

    /** Aviso começa a sair com este número de dias para o fim. */
    public const FIRST_NOTICE_DAYS = 3;

    public function __construct(
        private readonly DunningService $dunning,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('billing.trial_notices.enabled', true);
    }

    /** @return array<string, int> avisos enviados nesta execução, por passo */
    public function run(bool $dryRun = false): array
    {
        $stats = [self::STEP_THREE_DAYS => 0, self::STEP_ONE_DAY => 0, self::STEP_TODAY => 0];

        if (! $dryRun && ! $this->isEnabled()) {
            return $stats;
        }

        foreach ($this->candidates() as $subscription) {
            try {
                $step = $dryRun ? $this->pendingStep($subscription) : $this->process($subscription);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($step !== null) {
                $stats[$step]++;
            }
        }

        return $stats;
    }

    /**
     * Passo do aviso que vale agora para a assinatura (null = nenhum): só a
     * vigente da empresa (cliente), em trial ainda válido, até 3 dias do fim.
     */
    public function stepFor(?Subscription $subscription): ?string
    {
        if ($subscription === null || $subscription->status !== SubscriptionStatus::Trial
            || $subscription->billing_mode !== null || ! $subscription->isOnTrial()) {
            return null;
        }

        $entity = $subscription->entity ?? Entity::query()->find($subscription->entity_id);

        if ($entity === null || ! $entity->is_client || ! $subscription->isCurrentOfEntity()) {
            return null;
        }

        return match (DunningSchedule::daysSince(now(), $subscription->trial_ends_at)) {
            0 => self::STEP_TODAY,
            1 => self::STEP_ONE_DAY,
            2, 3 => self::STEP_THREE_DAYS,
            default => null,
        };
    }

    /** O aviso ainda vale (reconferência na hora do envio). */
    public function stillApplies(?Subscription $subscription, string $step, string $trialEndsOn): bool
    {
        return $subscription !== null
            && $this->stepFor($subscription) === $step
            && $subscription->trial_ends_at?->toDateString() === $trialEndsOn;
    }

    /** @return Collection<int, Subscription> */
    private function candidates(): Collection
    {
        return Subscription::query()
            ->latestPerEntity()
            ->with('entity')
            ->where('subscriptions.status', SubscriptionStatus::Trial->value)
            ->whereNull('subscriptions.billing_mode')
            ->where('subscriptions.trial_ends_at', '>', now())
            ->where('subscriptions.trial_ends_at', '<=', now()->addDays(self::FIRST_NOTICE_DAYS)->endOfDay())
            ->orderBy('subscriptions.id')
            ->get();
    }

    private function pendingStep(Subscription $subscription): ?string
    {
        $step = $this->stepFor($subscription);

        return $step !== null && ! $this->alreadySent($subscription, $step) ? $step : null;
    }

    private function alreadySent(Subscription $subscription, string $step): bool
    {
        return SubscriptionTrialNotice::query()
            ->where('subscription_id', $subscription->id)
            ->where('step', $step)
            ->whereDate('trial_ends_on', $subscription->trial_ends_at->toDateString())
            ->exists();
    }

    private function process(Subscription $subscription): ?string
    {
        $record = DB::transaction(function () use ($subscription): ?SubscriptionTrialNotice {
            $locked = Subscription::query()->with('entity')->whereKey($subscription->id)->lockForUpdate()->first();
            $step   = $this->stepFor($locked);

            if ($step === null || $this->alreadySent($locked, $step)) {
                return null;
            }

            try {
                return SubscriptionTrialNotice::query()->create([
                    'entity_id'       => $locked->entity_id,
                    'subscription_id' => $locked->id,
                    'step'            => $step,
                    'trial_ends_on'   => $locked->trial_ends_at->toDateString(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return null; // outra execução já registrou
            }
        });

        if ($record === null) {
            return null;
        }

        $subscription = $subscription->fresh('entity');
        $recipients   = $this->dunning->recipients((string) $subscription->entity_id);
        $daysLeft     = DunningSchedule::daysSince(now(), $subscription->trial_ends_at);
        $whatsapp     = 0;

        foreach ($recipients as $user) {
            $whatsapp += SaasWhatsAppChannel::reachable($user) ? 1 : 0;

            try {
                $user->notify(
                    (new TrialEndingNotification($subscription, $record->step, $record->trial_ends_on->toDateString(), (string) $subscription->entity?->name, $daysLeft))
                        ->locale(NoticeLocale::for($user, $subscription->entity)),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        $record->update(['recipients_count' => $recipients->count(), 'whatsapp_count' => $whatsapp]);

        return $record->step;
    }
}
