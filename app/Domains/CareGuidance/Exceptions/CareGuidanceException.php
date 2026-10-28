<?php

declare(strict_types=1);

namespace App\Domains\CareGuidance\Exceptions;

use RuntimeException;

/**
 * Recusa de NEGÓCIO das orientações pós-procedimento. `reason()` é o código que a tela traduz
 * (`care_guidance.errors.<código>`); `field()` aponta o campo do formulário quando há um.
 */
final class CareGuidanceException extends RuntimeException
{
    public const TEMPLATE_NOT_FOUND = 'template_not_found';

    public const TEMPLATE_INACTIVE = 'template_inactive';

    public const PROCEDURE_MISMATCH = 'procedure_mismatch';

    public const PROCEDURE_CANCELLED = 'procedure_cancelled';

    public const ATTACHMENT_NOT_PDF = 'attachment_not_pdf';

    public const ATTACHMENT_TOO_LARGE = 'attachment_too_large';

    public const ATTACHMENT_LIMIT = 'attachment_limit';

    public const ATTACHMENT_NOT_FOUND = 'attachment_not_found';

    public const ATTACHMENT_MISSING_FILE = 'attachment_missing_file';

    public const TOO_MANY_DROPS = 'too_many_drops';

    private function __construct(private readonly string $reason, string $message, private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function make(string $reason, string $message, ?string $field = null): self
    {
        return new self($reason, $message, $field);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function field(): ?string
    {
        return $this->field;
    }
}
