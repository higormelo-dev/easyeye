<?php

declare(strict_types = 1);

namespace App\Models;

use App\Concerns\HasEntityCode;
use App\Concerns\HasUppercaseName;
use App\Enums\FinancialEntryType;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Builder, Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class FinancialCategory extends Model
{
    use HasAuditColumns;
    use Auditable;
    use HasEntityCode;
    use HasUppercaseName;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'FCT';

    protected string $codePrefixGlobal = 'FCP';

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'type',
        'active',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'type' => FinancialEntryType::class,
            'active' => 'boolean',
            'is_system' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $query = static::where($field ?? $this->getRouteKeyName(), $value);

        if ($entityId = session('selected_entity_id')) {
            $query->where(fn (Builder $q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'));
        }

        return $query->firstOrFail();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(FinancialCashEntry::class, 'category_id');
    }

    public function scopeAvailableForEntity(Builder $query, string $entityId): Builder
    {
        return $query
            ->where(function (Builder $q) use ($entityId): void {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            })
            ->where('active', true);
    }
}

