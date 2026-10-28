<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasUppercaseName
{
    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value !== null
                ? mb_convert_case($value, MB_CASE_UPPER, 'UTF-8')
                : null,
        );
    }
}
