<?php

declare(strict_types=1);

namespace App\Domains\AI\Contracts;

use App\DTOs\AI\AiProviderResponseData;
use App\Enums\AI\AiProviderCallRole;

interface AiRunProviderCallStoreInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function store(
        string $aiRunId,
        AiProviderCallRole $role,
        string $status,
        ?AiProviderResponseData $response = null,
        ?string $errorMessage = null,
        array $metadata = [],
        ?int $normalizedCredits = null,
    ): void;
}
