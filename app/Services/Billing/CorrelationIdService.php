<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CorrelationIdService
{
    public function resolve(?Request $request = null): string
    {
        $request ??= request();

        $header = $request?->headers->get('X-Correlation-Id');

        return is_string($header) && $header !== '' ? $header : (string) Str::uuid();
    }
}
