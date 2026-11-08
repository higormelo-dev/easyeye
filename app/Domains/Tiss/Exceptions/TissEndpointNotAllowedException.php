<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Exceptions;

use RuntimeException;

/**
 * Endereço de webservice TISS recusado antes de qualquer conexão (ver TissEndpointGuard). `reason`:
 * `invalid` (não é http/https ou traz usuário/senha na URL), `private` (rede interna) ou `unresolvable` (sem IP).
 */
class TissEndpointNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct((string) __('tiss_credentials.errors.endpoint_' . $reason));
    }
}
