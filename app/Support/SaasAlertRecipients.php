<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SaasRule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quem recebe alerta operacional do SaaS por e-mail: admin e dono (vínculo
 * ativo numa empresa que não é cliente), com e-mail verificado.
 */
final class SaasAlertRecipients
{
    /** @return Collection<int, User> */
    public static function get(): Collection
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
