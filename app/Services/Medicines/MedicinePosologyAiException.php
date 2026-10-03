<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use RuntimeException;

/** Falha da sugestão de posologia por IA com mensagem já traduzida para a tela. */
class MedicinePosologyAiException extends RuntimeException
{
    /** Lista de IAs da tela desatualizada (provedores mudaram com a página aberta). */
    public const STALE_PROVIDERS = 'stale_providers';

    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
