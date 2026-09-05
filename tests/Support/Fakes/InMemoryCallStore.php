<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domains\AI\Contracts\AiRunProviderCallStoreInterface;
use App\DTOs\AI\AiProviderResponseData;
use App\Enums\AI\AiProviderCallRole;

/**
 * Registro em memória das chamadas de provider para testes do orchestrator (Unit, sem DB).
 */
class InMemoryCallStore implements AiRunProviderCallStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $entries = [];

    public function store(
        string $aiRunId,
        AiProviderCallRole $role,
        string $status,
        ?AiProviderResponseData $response = null,
        ?string $errorMessage = null,
        array $metadata = [],
        ?int $normalizedCredits = null,
    ): void {
        $this->entries[] = [
            'ai_run_id' => $aiRunId,
            'role'      => $role->value,
            'status'    => $status,
            // Mesma resolução do EloquentAiRunProviderCallStore real: fallback para
            // metadata.provider quando response é null (caso de status=skipped).
            'provider'           => $response?->provider->value ?? (string) ($metadata['provider'] ?? 'unknown'),
            'model'              => $response?->model,
            'error'              => $errorMessage,
            'normalized_credits' => $normalizedCredits,
            'metadata'           => $metadata,
        ];
    }
}
