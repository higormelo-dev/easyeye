<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Notifications\GatewayOperationalAlertNotification;
use App\Support\Billing\NoticeLocale;
use Illuminate\Support\Facades\{Cache, Log};
use Throwable;

/**
 * Alertas operacionais de gateway para o time do SaaS: log de billing (o
 * manager vê) e e-mail para admin, financeiro e dono (mesmos destinatários do
 * alerta de recorrência desativada — GatewayRecurrenceLossService). Cada
 * alerta (tipo + gateway + chave) sai no máximo uma vez por janela, para um
 * problema que se repete a cada chamada (chave recusada) não virar enxurrada.
 *
 * Tipos (textos em billing_alerts.gateway.*): credential_rejected,
 * environment_mismatch, access_token, recurrence_alignment, refund_credits,
 * health_failed, recurrence_diverged, checkout_terms_changed,
 * card_reregister, dunning_check_failed, refund_unconfirmed.
 */
class GatewayAlertService
{
    public function __construct(
        private readonly BillingLogService $billingLog,
    ) {
    }

    /**
     * 401/403 do gateway: chave inválida, expirada, desabilitada (3 meses
     * sem uso), de outro ambiente, ou IP fora da whitelist
     * (https://docs.asaas.com/docs/autenticação-1, https://docs.asaas.com/docs/whitelist-de-ips).
     */
    public function credentialRejected(string $gateway, int $httpStatus, string $detail = ''): void
    {
        $this->alert(
            gateway: $gateway,
            kind: 'credential_rejected',
            params: ['status' => $httpStatus, 'detail' => mb_substr($detail, 0, 300)],
            message: "Gateway {$gateway} recusou a credencial (HTTP {$httpStatus}) — chave inválida, expirada, desabilitada, de outro ambiente ou IP fora da whitelist. Cobranças e checkout falham até a chave ser trocada.",
            throttleKey: "credential:{$httpStatus}",
            // Toda chamada com a chave recusada dá 401/403: um registro (e um
            // e-mail) por janela, não um log crítico por chamada.
            logOnce: true,
        );
    }

    /**
     * Envia o alerta (log + e-mail), no máximo uma vez por $throttleMinutes
     * para o mesmo tipo/gateway/chave. Nunca lança.
     *
     * @param array<string, scalar|null> $params
     */
    public function alert(
        string $gateway,
        string $kind,
        array $params,
        string $message,
        string $level = 'critical',
        ?string $throttleKey = null,
        int $throttleMinutes = 60,
        ?string $entityId = null,
        bool $logOnce = false,
    ): bool {
        try {
            $key   = 'billing:gateway-alert:' . $gateway . ':' . $kind . ':' . ($throttleKey ?? 'default');
            $fresh = null;

            // logOnce: o registro também sai só uma vez por janela (o mesmo
            // problema repetido a cada chamada não enche o billing_log).
            if ($logOnce) {
                $fresh = Cache::add($key, now()->toIso8601String(), now()->addMinutes(max(1, $throttleMinutes)));

                if (! $fresh) {
                    return false;
                }
            }

            $this->billingLog->log(
                level: $level,
                message: $message,
                context: ['alert' => $kind, ...$params],
                entityId: $entityId,
                gatewayCode: $gateway,
            );

            if ($fresh === null && ! Cache::add($key, now()->toIso8601String(), now()->addMinutes(max(1, $throttleMinutes)))) {
                return false;
            }

            foreach (app(GatewayRecurrenceLossService::class)->staffRecipients() as $user) {
                try {
                    $user->notify((new GatewayOperationalAlertNotification($kind, $gateway, $params))->locale(NoticeLocale::for($user)));
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return true;
        } catch (Throwable $e) {
            Log::error('GatewayAlertService: falha ao registrar alerta de gateway.', ['gateway' => $gateway, 'kind' => $kind, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
