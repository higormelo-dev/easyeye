<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Exceptions;

use RuntimeException;

/**
 * Recusa de NEGÓCIO do curso de injeções intravítreas. `reason()` é o código que a tela traduz
 * (`recall_course.errors.<código>`); `field()` aponta o campo do formulário quando há um.
 */
final class IntravitrealException extends RuntimeException
{
    public const EYE_REQUIRED = 'eye_required';

    public const DRUG_REQUIRED = 'drug_required';

    public const NOT_PLANNED = 'not_planned';

    public const EYE_MISMATCH = 'eye_mismatch';

    public const LOT_REQUIRED = 'lot_required';

    public const LOT_EXPIRED = 'lot_expired';

    public const LOT_NOT_FOUND = 'lot_not_found';

    public const AUTHORIZATION_MISSING = 'authorization_missing';

    public const INTERVAL_REQUIRED = 'interval_required';

    public const INTERVAL_RANGE = 'interval_range';

    public const END_REASON_REQUIRED = 'end_reason_required';

    public const COURSE_NOT_FOUND = 'course_not_found';

    public const COURSE_CLOSED = 'course_closed';

    public const NO_REFERENCE_SCHEDULE = 'no_reference_schedule';

    public const ALREADY_SCHEDULED = 'already_scheduled';

    public const OTHER_DOCTOR_CALENDAR = 'other_doctor_calendar';

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
