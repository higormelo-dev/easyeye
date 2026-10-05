<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\{EntityUser, User};
use Illuminate\Support\Str;

/**
 * Canal privado `billing.{entityId}` — pagamento da assinatura confirmado
 * (App\Events\Billing\InvoicePaid), para a tela do checkout liberar sozinha.
 *
 * Mesma regra das telas que pagam (CheckoutController / middleware
 * billing.contact): clínica da sessão = clínica do canal (isolamento entre
 * clínicas) e o usuário é contato de cobrança dela — admin, financeiro ou
 * dono, vínculo ativo (EntityUser::billingContacts). Mudou a regra da rota,
 * mude aqui também.
 */
class ClinicBillingChannel
{
    public function join(User $user, string $entityId): bool
    {
        if (! Str::isUuid($entityId) || $entityId !== (string) session('selected_entity_id')) {
            return false;
        }

        return EntityUser::query()
            ->where('user_id', $user->id)
            ->where('entity_id', $entityId)
            ->billingContacts()
            ->exists();
    }
}
