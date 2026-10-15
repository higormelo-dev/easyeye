<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Exceptions;

use RuntimeException;

/** O aceite (conferência do CRM/RQE no portal do CFM) foi recusado. `reason()` é o código que a tela traduz. */
final class ReviewAcceptanceException extends RuntimeException
{
    public const NOT_SUBMITTED = 'not_submitted';

    public const NO_ATTESTATION = 'no_attestation';

    public const CHECKS_REQUIRED = 'checks_required';

    public const NOTE_REQUIRED = 'note_required';

    private function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notSubmitted(string $status): self
    {
        return new self(self::NOT_SUBMITTED, "O convite está {$status}: só parecer enviado (e ainda não conferido) pode ser aceito ou recusado.");
    }

    public static function noAttestation(): self
    {
        return new self(self::NO_ATTESTATION, 'O convite respondido não tem atestado: inconsistência que impede o aceite.');
    }

    public static function checksRequired(): self
    {
        return new self(self::CHECKS_REQUIRED, 'Aceitar exige ter conferido o CRM/UF e o RQE no portal do CFM.');
    }

    public static function noteRequired(): self
    {
        return new self(self::NOTE_REQUIRED, 'Explique o motivo (ao menos 10 caracteres).');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
