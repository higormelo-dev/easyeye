<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Enums\SaasRule;
use App\Models\User;
use App\Notifications\WhatsAppOperationalAlertNotification;
use App\Support\Billing\NoticeLocale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Cache, Log};
use Throwable;

/**
 * Alerta operacional do WhatsApp para o time do SaaS: algo que faz as
 * mensagens pararem de sair e só uma pessoa resolve (saldo da carteira da
 * Gupshup, token universal inválido/vencido, limite de tokens do app,
 * template reprovado/pausado...).
 *
 * Sempre Log::critical (Sentry). E-mail ao admin e ao dono do SaaS no máximo
 * uma vez por hora por tipo — mesmo padrão do GatewayRecurrenceLostNotification
 * (só e-mail, nada de dado de paciente: o contexto é técnico).
 */
class WhatsAppAlerts
{
    public const THROTTLE_MINUTES = 60;

    /**
     * Alertas que significam "nada sai agora" (token universal recusado,
     * limite de UATs, saldo da carteira): marcam o canal como fora do ar por
     * CHANNEL_DOWN_MINUTES (renovado a cada alerta). O gate do cadastro
     * (PhoneVerificationService::channelAvailable) libera enquanto isso — um
     * código que não sai não pode prender o onboarding.
     */
    public const CHANNEL_DOWN_TYPES = ['universal_token_invalid', 'uat_limit', 'wallet_low'];

    public const CHANNEL_DOWN_MINUTES = 15;

    private const CHANNEL_DOWN_KEY = 'whatsapp:channel-down';

    /** @param array<string, scalar|null> $context sem token, telefone ou dado de paciente */
    public static function critical(string $type, array $context = []): void
    {
        Log::critical('[whatsapp:alert] ' . $type, $context);

        if (in_array($type, self::CHANNEL_DOWN_TYPES, true)) {
            self::markChannelDown($type, isset($context['app_id']) ? (string) $context['app_id'] : null);
        }

        if (! Cache::add('whatsapp:alert:' . $type, 1, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        foreach (self::recipients() as $user) {
            try {
                $user->notify((new WhatsAppOperationalAlertNotification($type, $context))->locale(NoticeLocale::for($user)));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Token universal recusado vale para todos os apps (é do parceiro);
     * limite de UATs e saldo, para o app do alerta (sem app conhecido: todos).
     */
    private static function markChannelDown(string $type, ?string $appId): void
    {
        $scope = $type === 'universal_token_invalid' || $appId === null || $appId === '' ? '*' : $appId;
        $down  = (array) Cache::get(self::CHANNEL_DOWN_KEY, []);

        $down[$scope] = $type;

        Cache::put(self::CHANNEL_DOWN_KEY, $down, now()->addMinutes(self::CHANNEL_DOWN_MINUTES));
    }

    /** O app (ou todos) está marcado como fora do ar por um alerta recente? */
    public static function channelDown(?string $appId): bool
    {
        $down = (array) Cache::get(self::CHANNEL_DOWN_KEY, []);

        return isset($down['*']) || ($appId !== null && $appId !== '' && isset($down[$appId]));
    }

    /**
     * Admin e dono do SaaS (vínculo ativo numa empresa que não é cliente),
     * com e-mail verificado.
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
