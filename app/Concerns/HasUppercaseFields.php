<?php

namespace App\Concerns;

trait HasUppercaseFields
{
    public function setAttribute($key, $value): mixed
    {
        if (is_string($value) && in_array($key, $this->uppercaseFields ?? [], true)) {
            $value = mb_convert_case($value, MB_CASE_UPPER, 'UTF-8');
        }

        return parent::setAttribute($key, $value);
    }
}
