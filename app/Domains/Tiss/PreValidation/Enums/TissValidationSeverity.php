<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Enums;

enum TissValidationSeverity: string
{
    case Error   = 'error';
    case Warning = 'warning';
}
