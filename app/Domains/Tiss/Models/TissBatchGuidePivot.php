<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

/**
 * Pivot de `tiss_batch_guides` (lote ↔ guia) para os BelongsToMany
 * TissBatch::guides()/TissGuide::batches(). A tabela tem `id` uuid próprio
 * sem default: com o pivot padrão, attach()/sync() inseriam `id` NULL. Herda
 * de TissBatchGuide (HasUuids, auditoria, colunas) — mesmo tratamento de
 * App\Models\ScheduleResource. Auditoria continua com o tipo TissBatchGuide.
 */
class TissBatchGuidePivot extends TissBatchGuide
{
    use AsPivot;

    public $incrementing = false;

    protected $keyType = 'string';

    public function getMorphClass(): string
    {
        return TissBatchGuide::class;
    }
}
