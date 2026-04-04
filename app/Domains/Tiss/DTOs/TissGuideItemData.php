<?php

declare(strict_types=1);

namespace App\Domains\Tiss\DTOs;

use App\Domains\Tiss\Models\TissGuideItem;

final readonly class TissGuideItemData
{
    public function __construct(
        public string $tussCode,
        public string $tableCode,
        public string $description,
        public float $quantity,
        public float $unitAmount,
        public float $totalAmount,
        public ?string $executionDate = null,
        public ?string $authorizationNumber = null,
    ) {
    }

    public static function fromModel(TissGuideItem $item): self
    {
        return new self(
            tussCode: (string) $item->tuss_code,
            tableCode: (string) $item->table_code,
            description: (string) $item->description,
            quantity: (float) $item->quantity,
            unitAmount: (float) $item->unit_amount,
            totalAmount: (float) $item->total_amount,
            executionDate: $item->execution_date?->format('Y-m-d'),
            authorizationNumber: $item->authorization_number,
        );
    }
}
