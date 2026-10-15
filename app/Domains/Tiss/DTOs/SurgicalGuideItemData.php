<?php

declare(strict_types=1);

namespace App\Domains\Tiss\DTOs;

use App\Domains\Tiss\Models\TissGuideItem;

/** Linha de procedimento (internação) ou de OPME (anexo) já resolvida para o XML. */
final readonly class SurgicalGuideItemData
{
    public function __construct(
        public string $tableCode,
        public string $code,
        public string $description,
        public int $quantity,
        public float $unitAmount,
        public ?string $manufacturerOption = null,
        public ?string $anvisaRegistration = null,
        public ?string $manufacturerRef = null,
        public ?string $operatingAuthorization = null,
    ) {
    }

    public static function fromModel(TissGuideItem $item): self
    {
        $meta = (array) ($item->metadata ?? []);

        return new self(
            tableCode: (string) $item->table_code,
            code: (string) $item->tuss_code,
            description: (string) $item->description,
            quantity: max(1, (int) round((float) $item->quantity)),
            unitAmount: (float) $item->unit_amount,
            manufacturerOption: self::text($meta['manufacturer_option'] ?? null),
            anvisaRegistration: self::text($meta['anvisa_registration'] ?? null),
            manufacturerRef: self::text($meta['manufacturer_ref'] ?? null),
            operatingAuthorization: self::text($meta['operating_authorization'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
