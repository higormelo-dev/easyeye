<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation;

use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;

final readonly class TissGuideValidationIssue
{
    public function __construct(
        public TissValidationSeverity $severity,
        public string $code,
        public string $field,
        public string $message,
        public string $suggestion,
        public ?string $operatorHint = null,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === TissValidationSeverity::Error;
    }

    public function isWarning(): bool
    {
        return $this->severity === TissValidationSeverity::Warning;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'severity'      => $this->severity->value,
            'code'          => $this->code,
            'field'         => $this->field,
            'message'       => $this->message,
            'suggestion'    => $this->suggestion,
            'operator_hint' => $this->operatorHint,
        ];
    }
}
