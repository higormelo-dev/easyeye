<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Models\{Entity, MedicalRecordProcedure};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class TissGuideItem extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tiss_guide_items';

    protected $fillable = [
        'entity_id',
        'guide_id',
        'status',
        'tuss_code',
        'table_code',
        'procedure_code',
        'description',
        'quantity',
        'unit_amount',
        'total_amount',
        'execution_date',
        'authorization_number',
        'metadata',
        // Vínculo de AUDITORIA (Fase 3 do estoque) — NÃO é campo de OPM da
        // ANS, ver doc da migration add_reference_to_tiss_guide_items_table.
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity'       => 'decimal:2',
            'unit_amount'    => 'decimal:2',
            'total_amount'   => 'decimal:2',
            'execution_date' => 'date',
            'metadata'       => 'array',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TissGuide::class, 'guide_id');
    }

    public function glosas(): HasMany
    {
        return $this->hasMany(TissGlosa::class, 'guide_item_id');
    }

    /**
     * Procedimento executado (App\Models\MedicalRecordProcedure) que
     * originou o consumo de estoque faturado nesta linha — vínculo de
     * AUDITORIA/reconciliação apenas.
     *
     * NÃO é uma relation Eloquent de verdade (morphTo exigiria um
     * `*_type`/`*_id` mapeado via Relation::morphMap(), que este projeto não
     * usa — `reference_type` grava o FQCN como string solta, mesmo desenho
     * de StockMovement.reference_type). Método simples em vez de relation
     * com `->where()` incorreto (a constraint bateria na tabela ERRADA — a
     * relacionada, não esta).
     */
    public function procedureExecution(): ?MedicalRecordProcedure
    {
        if ($this->reference_type !== MedicalRecordProcedure::class || $this->reference_id === null) {
            return null;
        }

        return MedicalRecordProcedure::find($this->reference_id);
    }
}
