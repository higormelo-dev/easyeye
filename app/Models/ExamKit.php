<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany};

/**
 * Kit de exames (Fase 2 / B2).
 *
 *  - GLOBAL (entity_id nulo): padrão do EasyEye, mantido no manager e
 *    semeado por ExamKitsSeeder (`slug`). Toda clínica vê.
 *  - Da CLÍNICA: copiado de um global (`source_kit_id`) ou criado do zero em
 *    Configurações › Kits de exames, com convênios vinculados (nenhum =
 *    todos os convênios).
 *
 * Itens referenciam o exame pelo CÓDIGO TUSS + nome; no pedido, o código é
 * resolvido no catálogo de procedimentos da clínica (ExamKitService).
 *
 * EntityScope (BelongsToEntity): a clínica vê os dela + os globais.
 */
class ExamKit extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'source_kit_id',
        'slug',
        'name',
        'description',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active'     => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExamKitItem::class)->orderBy('sort_order');
    }

    public function covenants(): BelongsToMany
    {
        return $this->belongsToMany(Covenant::class, 'exam_kit_covenant')->withPivot('created_at');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_kit_id');
    }

    public function isGlobal(): bool
    {
        return $this->entity_id === null;
    }

    /** Kits padrão do SaaS. */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('entity_id'));
    }

    /** Kits da clínica informada (sem os globais). */
    public function scopeOwnedBy(Builder $query, string $entityId): Builder
    {
        return $query->where($this->qualifyColumn('entity_id'), $entityId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('active'), true);
    }
}
