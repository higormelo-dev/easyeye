<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Contexto global de auditoria.
 *
 * Permite forçar um user_id fixo em contextos onde auth()->id() é null,
 * como seeders e comandos Artisan.
 *
 * Uso em seeders:
 *   AuditContext::setUserId($higor->id);
 *
 * Em contexto web não precisa chamar nada — o fallback é auth()->id().
 */
class AuditContext
{
    private static ?string $userId = null;

    public static function setUserId(?string $userId): void
    {
        self::$userId = $userId;
    }

    public static function userId(): ?string
    {
        if (self::$userId !== null) {
            return self::$userId;
        }

        $user = auth()->user();

        return ($user instanceof User) ? $user->getKey() : null;
    }
}
