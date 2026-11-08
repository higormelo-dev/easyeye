<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AI\AiProvider;
use App\Enums\SaasRule;
use App\Models\User;
use App\Notifications\AiOperationalAlertNotification;
use App\Support\Billing\NoticeLocale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Cache, Log};
use Throwable;

/**
 * Alerta operacional de IA para o time do SaaS: o provedor parou de atender
 * por um motivo que só uma pessoa resolve — conta sem crédito/cota (quota) ou
 * chave recusada (auth). Mesmo padrão do WhatsAppAlerts: sempre Log::critical
 * (Sentry) e e-mail ao admin e ao dono do SaaS no máximo uma vez por hora por
 * tipo e provedor. Só contexto técnico: nada de paciente, prompt ou chave.
 */
class AiOperationalAlerts
{
    public const THROTTLE_MINUTES = 60;

    public const TYPES = ['quota', 'auth'];

    /** @param array<string, scalar|null> $context */
    public static function critical(string $type, AiProvider $provider, array $context = []): void
    {
        Log::critical('[ai:alert] ' . $type, ['provider' => $provider->value, ...$context]);

        if (! Cache::add('ai:alert:' . $type . ':' . $provider->value, 1, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        foreach (self::recipients() as $user) {
            try {
                $user->notify((new AiOperationalAlertNotification($type, $provider->label(), $context))->locale(NoticeLocale::for($user)));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Admin e dono do SaaS (vínculo ativo numa empresa que não é cliente),
     * com e-mail verificado — os mesmos do alerta do WhatsApp.
     *
     * @return Collection<int, User>
     */
    public static function recipients(): Collection
    {
        return User::query()
            ->whereNotNull('users.email_verified_at')
            ->whereHas('entityUsers', fn ($q) => $q->where('active', true)
                ->whereHas('entity', fn ($e) => $e->where('is_client', false))
                ->where(fn ($role) => $role->where('rule', SaasRule::Admin->value)->orWhere('is_owner', true)))
            ->orderBy('users.id')
            ->get();
    }
}
