<?php

declare(strict_types=1);

namespace App\Services;

final class IntegratorHealthMinimizer
{
    /** Legacy inputs are accepted for compatibility, but clinical/free text is never retained. */
    public function problems(array $problems): array
    {
        return array_map(static fn (array $problem): array => [
            'id'        => $problem['id'],
            'file_name' => preg_match('/^<file:[a-f0-9]{16}>$/', (string) ($problem['file_name'] ?? '')) === 1
                ? $problem['file_name']
                : '<file:' . substr(hash_hmac('sha256', (string) ($problem['file_name'] ?? ''), (string) config('app.key')), 0, 16) . '>',
            'status'              => $problem['status'],
            'schedule_identifier' => null,
            'patient_identifier'  => null,
            'last_error'          => null,
            'error_code'          => 'upload_failed',
            'attempts'            => $problem['attempts'],
            'api_status'          => $problem['api_status'] ?? null,
            'updated_at'          => $problem['updated_at'],
        ], $problems);
    }
}
