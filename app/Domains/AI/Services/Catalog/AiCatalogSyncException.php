<?php

declare(strict_types=1);

namespace App\Domains\AI\Services\Catalog;

use RuntimeException;

/** Falha na sincronização do catálogo de IA — mensagem já traduzida e sem segredo. */
final class AiCatalogSyncException extends RuntimeException
{
}
