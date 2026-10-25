<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use RuntimeException;

/**
 * Falha ao buscar a cotação automática (frankfurter.dev). A mensagem é um CÓDIGO seguro para log/UI
 * (`http_<status>`, `invalid_payload`, `out_of_range`, `timeout`) — nunca o corpo da resposta.
 */
final class AiExchangeRateFetchException extends RuntimeException
{
    public function code(): string
    {
        return $this->getMessage();
    }
}
