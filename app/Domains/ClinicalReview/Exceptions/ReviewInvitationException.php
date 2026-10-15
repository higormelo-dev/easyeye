<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Exceptions;

use RuntimeException;

/** Operação recusada sobre um convite de revisão ou sobre o Revisor. `reason()` é o código que a tela traduz. */
final class ReviewInvitationException extends RuntimeException
{
    public const NO_VERSIONS = 'no_versions';

    public const VERSION_NOT_FOUND = 'version_not_found';

    public const VERSION_NOT_IN_REVIEW = 'version_not_in_review';

    public const REVIEWER_INACTIVE = 'reviewer_inactive';

    public const DUPLICATE_PENDING = 'duplicate_pending';

    public const INVALID_ACCESS_CODE = 'invalid_access_code';

    public const NOT_PENDING = 'not_pending';

    public const EXPIRED = 'expired';

    public const REASON_TOO_SHORT = 'reason_too_short';

    private function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function noVersions(): self
    {
        return new self(self::NO_VERSIONS, 'Escolha ao menos uma versão para o convite.');
    }

    public static function versionNotFound(): self
    {
        return new self(self::VERSION_NOT_FOUND, 'Alguma das versões escolhidas não existe.');
    }

    public static function versionNotInReview(string $versionId, string $state): self
    {
        return new self(self::VERSION_NOT_IN_REVIEW, "A versão {$versionId} está em {$state}: só versão em revisão entra em convite.");
    }

    public static function reviewerInactive(): self
    {
        return new self(self::REVIEWER_INACTIVE, 'O Revisor está inativo.');
    }

    public static function duplicatePending(): self
    {
        return new self(self::DUPLICATE_PENDING, 'Já existe um convite pendente deste Revisor para o mesmo conjunto de versões.');
    }

    public static function invalidAccessCode(): self
    {
        return new self(self::INVALID_ACCESS_CODE, 'O código de acesso deve ter de 6 a 12 letras e números.');
    }

    public static function notPending(string $status): self
    {
        return new self(self::NOT_PENDING, "O convite está {$status}: só convite pendente pode ser reenviado ou revogado.");
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED, 'O convite expirou: crie um novo.');
    }

    public static function reasonTooShort(): self
    {
        return new self(self::REASON_TOO_SHORT, 'Explique o motivo (ao menos 10 caracteres).');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
