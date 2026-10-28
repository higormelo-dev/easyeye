<?php

namespace App\Exceptions\Billing;

/**
 * Contratação pela cobrança automática que deixou de ser a pendente da
 * empresa enquanto o gateway respondia (outra contratação, cortesia ou trial
 * feitos ao mesmo tempo). A nova não substitui nada e a recorrência criada
 * no gateway é desfeita — a alteração mais recente é a que vale.
 */
class SubscriptionSupersededException extends BillingException
{
}
