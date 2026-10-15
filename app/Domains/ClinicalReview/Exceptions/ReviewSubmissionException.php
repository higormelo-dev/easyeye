<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Exceptions;

use RuntimeException;

/** O envio do parecer foi recusado. `reason()` é o código que a página traduz; `status()` o HTTP. */
final class ReviewSubmissionException extends RuntimeException
{
    public const NOT_OPEN = 'not_open';

    public const SET_CHANGED = 'set_changed';

    public const STATEMENT_CHANGED = 'statement_changed';

    public const MISSING_DECISION = 'missing_decision';

    public const COMMENT_REQUIRED = 'comment_required';

    public const STATEMENT_NOT_ACCEPTED = 'statement_not_accepted';

    public const CONFLICT_DESCRIPTION_REQUIRED = 'conflict_description_required';

    private function __construct(private readonly string $reason, string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notOpen(): self
    {
        return new self(self::NOT_OPEN, 'O convite não está mais aberto para parecer.', 409);
    }

    public static function setChanged(): self
    {
        return new self(self::SET_CHANGED, 'O conjunto de versões do convite mudou.', 409);
    }

    public static function statementChanged(): self
    {
        return new self(self::STATEMENT_CHANGED, 'A declaração foi atualizada: leia de novo antes de enviar.', 409);
    }

    public static function missingDecision(): self
    {
        return new self(self::MISSING_DECISION, 'Falta a decisão de alguma versão.', 422);
    }

    public static function commentRequired(): self
    {
        return new self(self::COMMENT_REQUIRED, 'Explique a decisão de toda versão que não for aprovada.', 422);
    }

    public static function statementNotAccepted(): self
    {
        return new self(self::STATEMENT_NOT_ACCEPTED, 'É preciso concordar com a declaração.', 422);
    }

    public static function conflictDescriptionRequired(): self
    {
        return new self(self::CONFLICT_DESCRIPTION_REQUIRED, 'Descreva o conflito de interesses.', 422);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return $this->status;
    }
}
