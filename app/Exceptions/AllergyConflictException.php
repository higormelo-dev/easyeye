<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Receita bate com alergia ativa do paciente e veio sem motivo para seguir
 * (Fase 2 / A4). Quem emite devolve 422 com os conflitos — a tela mostra a
 * alergia e a reação e pede o motivo.
 */
class AllergyConflictException extends RuntimeException
{
    /** @param list<array<string, mixed>> $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct(__('clinical_safety.prescription.conflict_message'));
    }

    /** Resposta JSON padrão (422) — mesmo formato em todos os pontos de emissão. */
    public function toResponsePayload(): array
    {
        return [
            'message'           => $this->getMessage(),
            'allergy_conflicts' => $this->conflicts,
            'errors'            => ['allergy_override_reason' => [__('clinical_safety.prescription.reason_required')]],
        ];
    }
}
