<?php

declare(strict_types=1);

namespace App\Domains\AI\Services\Catalog;

/**
 * Preço de um modelo em USD por 1 milhão de tokens. Raciocínio null = mesmo
 * preço da saída (é como os provedores cobram).
 */
final readonly class CatalogPrice
{
    public function __construct(
        public float $input,
        public float $output,
        public ?float $reasoning = null,
    ) {
    }

    /**
     * Catálogos publicam o preço por token; aqui vira por 1M. Valor ausente,
     * não numérico ou negativo (ex.: "-1" = preço variável) → sem preço.
     */
    public static function fromPerToken(mixed $input, mixed $output, mixed $reasoning = null): ?self
    {
        if (! is_numeric($input) || ! is_numeric($output) || (float) $input < 0 || (float) $output < 0) {
            return null;
        }

        $reasoning = is_numeric($reasoning) && (float) $reasoning > 0 ? self::perMillion($reasoning) : null;

        return new self(self::perMillion($input), self::perMillion($output), $reasoning);
    }

    /** Raciocínio efetivo (sem preço próprio = preço da saída). */
    public function effectiveReasoning(): float
    {
        return $this->reasoning ?? $this->output;
    }

    /** @return array{input: float, output: float} */
    public function summary(): array
    {
        return ['input' => $this->input, 'output' => $this->output];
    }

    private static function perMillion(mixed $perToken): float
    {
        return round((float) $perToken * 1_000_000, 6);
    }
}
