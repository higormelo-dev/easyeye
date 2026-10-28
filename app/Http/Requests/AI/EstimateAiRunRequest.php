<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

/**
 * Mesmas regras do StoreAiRunRequest hoje — separa só para permitir divergência
 * futura (ex.: estimate aceitar prompt mais curto para previews ao vivo).
 */
class EstimateAiRunRequest extends StoreAiRunRequest
{
}
