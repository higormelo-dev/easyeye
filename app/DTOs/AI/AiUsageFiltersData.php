<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use Illuminate\Support\Carbon;

/**
 * Recorte de Manager → Uso de IA. Período pela data de criação da execução
 * (ai_runs.created_at — as chamadas aos provedores acontecem segundos depois);
 * demais filtros opcionais. `provider` restringe as CHAMADAS (custo e
 * contagem só daquele provedor) e as execuções às que usaram o provedor.
 */
final readonly class AiUsageFiltersData
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public ?string $entityId = null,
        public ?string $userId = null,
        public ?string $workflow = null,
        public ?string $provider = null,
        public ?string $status = null,
    ) {
    }

    /** Janela de mesma duração imediatamente anterior (comparativo dos indicadores). */
    public function previousPeriod(): self
    {
        $seconds = $this->from->diffInSeconds($this->to);
        $to      = $this->from->copy()->subSecond();

        return new self(
            from: $to->copy()->subSeconds((int) $seconds),
            to: $to,
            entityId: $this->entityId,
            userId: $this->userId,
            workflow: $this->workflow,
            provider: $this->provider,
            status: $this->status,
        );
    }

    /** Barras do gráfico por mês a partir de ~3 meses (366 barras diárias não se leem). */
    public function granularity(): string
    {
        return $this->from->diffInDays($this->to) > 92 ? 'month' : 'day';
    }
}
