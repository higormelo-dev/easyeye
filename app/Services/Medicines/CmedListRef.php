<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use Carbon\CarbonImmutable;

/**
 * Uma lista de preços CMED a baixar: link, versão (estável por publicação —
 * compara com a última importada) e data de publicação.
 */
final readonly class CmedListRef
{
    public function __construct(
        public string $url,
        public string $version,
        public ?CarbonImmutable $publishedAt,
        public string $filename,
        public string $extension,
        /** true = reserva do portal de dados abertos (pode estar atrasada). */
        public bool $fallback = false,
    ) {
    }
}
