<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Covenant;

/**
 * Convênio só entra em vínculo NOVO (agendamento, lista de espera, paciente)
 * se estiver ativo; o que o registro em edição já tem passa mesmo inativado.
 * Ver ActiveCatalogItem.
 */
final class ActiveCovenant extends ActiveCatalogItem
{
    public function __construct(?string $currentId = null)
    {
        parent::__construct(Covenant::class, $currentId, 'validation.custom.covenant.inactive');
    }

    public static function isActive(string $covenantId): bool
    {
        return static::activeIn(Covenant::class, $covenantId);
    }
}
