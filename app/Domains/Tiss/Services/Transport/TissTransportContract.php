<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Models\TissEntityOperatorCredential;

interface TissTransportContract
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array{
     *   ok: bool,
     *   status_code: int|null,
     *   response_body: string|null,
     *   protocol_number: string|null,
     *   protocol_status: string|null,
     *   error_code: string|null,
     *   error_message: string|null,
     *   response_time_ms: int|null
     * }
     */
    public function sendBatch(
        TissEntityOperatorCredential $credential,
        string $xml,
        string $idempotencyKey,
        array $context = [],
    ): array;
}
