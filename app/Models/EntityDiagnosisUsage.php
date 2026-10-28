<?php

namespace App\Models;

use App\Enums\DiagnosisType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityDiagnosisUsage extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'diagnosis_type',
        'cid10_code_id',
        'entity_custom_diagnosis_id',
        'use_count',
        'last_used_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diagnosis_type' => DiagnosisType::class,
            'use_count'      => 'integer',
            'last_used_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function cid10Code(): BelongsTo
    {
        return $this->belongsTo(Cid10Code::class, 'cid10_code_id');
    }

    public function customDiagnosis(): BelongsTo
    {
        return $this->belongsTo(EntityCustomDiagnosis::class, 'entity_custom_diagnosis_id');
    }
}
