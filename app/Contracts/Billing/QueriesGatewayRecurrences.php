<?php

namespace App\Contracts\Billing;

use App\DTOs\Billing\GatewayRecurrenceDTO;
use App\Exceptions\Billing\GatewayIntegrationException;

/**
 * Gateway com API de consulta da recorrência (hoje só o Asaas). Usado para
 * conciliar assinaturas do código anterior (billing:reconcile-legacy) e para
 * desfazer a recorrência criada quando a resposta da criação se perdeu
 * (timeout/5xx). Gateway sem essa API fica para revisão manual.
 */
interface QueriesGatewayRecurrences
{
    /**
     * Recorrência e as cobranças dela. Null quando não existe no gateway.
     *
     * @throws GatewayIntegrationException falha na consulta (rede, 5xx, credencial)
     */
    public function fetchRecurrence(string $externalSubscriptionId): ?GatewayRecurrenceDTO;

    /**
     * Ids das recorrências (não removidas) criadas com essa referência nossa
     * (externalReference = id da assinatura local).
     *
     * @return list<string>
     *
     * @throws GatewayIntegrationException falha na consulta
     */
    public function findRecurrenceIdsByReference(string $externalReference): array;
}
