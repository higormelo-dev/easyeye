<?php

declare(strict_types=1);

namespace App\Domains\Tiss\DTOs;

use App\Domains\Tiss\Models\{TissBatch, TissGuide};

final readonly class TissBatchData
{
    /**
     * @param TissGuideData[] $guides
     */
    public function __construct(
        public string $batchNumber,
        public string $referenceMonth,
        public string $providerCode,
        public string $operatorAnsCode,
        public string $versionCode,
        public string $layoutVersion,
        public array $guides,
    ) {
    }

    public static function fromModel(TissBatch $batch): self
    {
        $batch->loadMissing(['entity', 'operator', 'version', 'guides.items']);

        return new self(
            batchNumber: (string) $batch->batch_number,
            referenceMonth: (string) $batch->reference_month,
            providerCode: (string) ($batch->entity?->code ?? ''),
            operatorAnsCode: (string) ($batch->operator?->ans_code ?? ''),
            versionCode: (string) ($batch->version?->code ?? ''),
            layoutVersion: (string) ($batch->version?->layout_version ?? ''),
            guides: $batch->guides->map(static fn (TissGuide $guide) => TissGuideData::fromModel($guide))->all(),
        );
    }
}
