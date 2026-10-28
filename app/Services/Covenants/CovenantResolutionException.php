<?php

declare(strict_types=1);

namespace App\Services\Covenants;

use RuntimeException;

/**
 * A linha da planilha não conseguiu resolver o convênio (não existe, está inativo, ou o de-para aponta para um alvo
 * indisponível). É erro de NEGÓCIO: a mensagem vai para o CSV de erros da clínica (privado) e a linha entra em
 * `covenant_error_lines`, reprocessável depois de ajustar o de-para. Não é logada.
 */
class CovenantResolutionException extends RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const INACTIVE = 'inactive';

    public const MAPPING_COVENANT_UNAVAILABLE = 'mapping_covenant_unavailable';

    public const MAPPING_PLAN_UNAVAILABLE = 'mapping_plan_unavailable';

    public const PARTICULAR_MISSING = 'particular_missing';

    /** @param string $reason not_found|inactive|mapping_covenant_unavailable|mapping_plan_unavailable|particular_missing */
    public function __construct(
        string $message,
        public readonly string $sourceName,
        public readonly string $sourceNameNormalized,
        public readonly string $reason,
        public readonly ?string $covenantId = null,
        public readonly ?string $planId = null,
    ) {
        parent::__construct($message);
    }
}
