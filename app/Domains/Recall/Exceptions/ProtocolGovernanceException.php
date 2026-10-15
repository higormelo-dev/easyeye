<?php

declare(strict_types=1);

namespace App\Domains\Recall\Exceptions;

use RuntimeException;

/**
 * Operação de governança recusada (liberar, suspender, retomar, aposentar, retirar da
 * revisão). Erro de estado da versão (transição não permitida) é ProtocolVersionException;
 * aqui estão as recusas de NEGÓCIO: falta a prova da validação, a integridade não confere,
 * falta o motivo. `reason()` é o código que a tela traduz.
 */
final class ProtocolGovernanceException extends RuntimeException
{
    public const NO_VALIDATION_EVIDENCE = 'no_validation_evidence';

    public const INTEGRITY_FAILED = 'integrity_failed';

    public const REASON_TOO_SHORT = 'reason_too_short';

    private function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function noValidationEvidence(string $detail): self
    {
        return new self(self::NO_VALIDATION_EVIDENCE, "Sem prova de validação para liberar: {$detail}.");
    }

    public static function integrityFailed(string $versionId): self
    {
        return new self(self::INTEGRITY_FAILED, "O hash gravado da versão {$versionId} não confere com o conteúdo gravado.");
    }

    public static function reasonTooShort(int $minimum): self
    {
        return new self(self::REASON_TOO_SHORT, "Explique o motivo (ao menos {$minimum} caracteres).");
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
