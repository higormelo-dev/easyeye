<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionAccessLevel;
use App\Models\Entity;
use App\Services\SubscriptionService;

/**
 * O que a clínica com o acesso BLOQUEADO (Subscription::accessLevel = none)
 * deixa de ter fora do painel — um lugar só para a regra (decisão do dono do
 * produto):
 *
 *  - automações voltadas ao paciente (WhatsApp automático: confirmação,
 *    pesquisa, resposta automática — inclusive pela instância global do
 *    SaaS): puladas com o motivo registrado; voltam sozinhas quando o acesso
 *    volta;
 *  - painel de chamada da TV: "serviço indisponível", sem chamadas;
 *  - portal do paciente: só leitura (documentos e resultados seguem — LGPD).
 *
 * Acesso limitado (régua de cobrança) continua com tudo isso: só o bloqueio
 * total para. Empresa que não é cliente (equipe do SaaS) nunca é bloqueada.
 * billing.enforce_subscription_access = false (chave de emergência
 * BILLING_ENFORCE_SUBSCRIPTION_ACCESS) desliga o bloqueio inteiro.
 *
 * Guarda o nível por clínica enquanto a instância vive (um comando que
 * percorre as clínicas consulta cada uma uma vez).
 */
class ClinicServiceGate
{
    /** Motivo gravado quando um envio automático é pulado. */
    public const REASON_ACCESS_BLOCKED = 'clinic_access_blocked';

    /** @var array<string, bool> */
    private array $blocked = [];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /** A clínica pode ter automações voltadas ao paciente (WhatsApp automático, TV). */
    public function allowsAutomation(Entity|string|null $entity): bool
    {
        return ! $this->isBlocked($entity);
    }

    /** Portal do paciente desta clínica só em leitura. */
    public function portalReadOnly(Entity|string|null $entity): bool
    {
        return $this->isBlocked($entity);
    }

    /** Acesso da clínica bloqueado (nível none) com o bloqueio ligado. */
    public function isBlocked(Entity|string|null $entity): bool
    {
        if ($entity === null || $entity === '' || ! config('billing.enforce_subscription_access', true)) {
            return false;
        }

        $model = $entity instanceof Entity ? $entity : Entity::query()->find($entity);

        if ($model === null || ! $model->is_client) {
            return false;
        }

        return $this->blocked[(string) $model->id] ??= $this->subscriptions->accessLevel($model) === SubscriptionAccessLevel::None;
    }

    /** Esquece o que foi guardado (instância reaproveitada entre clínicas/tempos). */
    public function forget(): void
    {
        $this->blocked = [];
    }
}
