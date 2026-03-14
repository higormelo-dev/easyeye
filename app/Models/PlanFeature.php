<?php

namespace App\Models;

use App\Enums\FeatureKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'plan_id',
        'feature',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'feature' => FeatureKey::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * Retorna o valor como inteiro (para features numéricas).
     */
    public function intValue(): int
    {
        return (int) $this->value;
    }

    /**
     * Retorna o valor como booleano (para features booleanas).
     */
    public function boolValue(): bool
    {
        return $this->value === '1';
    }
}
