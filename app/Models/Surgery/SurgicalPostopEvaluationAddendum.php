<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\Concerns\BelongsToEntity;
use App\Models\Doctor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Adendo de uma avaliação pós-operatória FINALIZADA: texto + (opcional) a
 * refração corrigida, que os indicadores passam a usar no lugar da original.
 * Imutável e sem `updated_at`.
 */
class SurgicalPostopEvaluationAddendum extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'surgical_postop_evaluation_addenda';

    protected $fillable = [
        'entity_id',
        'surgical_postop_evaluation_id',
        'surgical_case_id',
        'doctor_id',
        'text',
        'corrected_sphere',
        'corrected_cylinder',
        'corrected_axis',
        'corrected_spherical_equivalent',
        'content_hash',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Adendo é imutável.');
        });

        static::deleting(function (): never {
            throw new LogicException('Adendo não pode ser apagado.');
        });
    }

    protected function casts(): array
    {
        return [
            'corrected_sphere'               => 'decimal:2',
            'corrected_cylinder'             => 'decimal:2',
            'corrected_spherical_equivalent' => 'decimal:2',
            'corrected_axis'                 => 'integer',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(SurgicalPostopEvaluation::class, 'surgical_postop_evaluation_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
